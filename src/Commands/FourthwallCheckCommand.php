<?php

namespace Ombabush\Fourthwall\Commands;

use Illuminate\Console\Command;
use Ombabush\Fourthwall\Data\Money;
use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Fourthwall;
use Ombabush\Fourthwall\Platform;
use Ombabush\Fourthwall\Sources\FeedSource;
use Ombabush\Fourthwall\Sources\StorefrontSource;
use Throwable;

/**
 * «Here are my credentials — what can you see?»
 *
 * Asks for whatever it is not given, tries every door with it — the public
 * feeds, the Storefront API, the Platform API — and prints what is behind
 * each. Nothing is cached, nothing is written, nothing is sent anywhere but
 * Fourthwall. Customers' e-mail and postal addresses are never printed.
 *
 *   php artisan fourthwall:check
 *   php artisan fourthwall:check --shop=https://shop.example.com
 *   php artisan fourthwall:check --json > shop.json
 */
class FourthwallCheckCommand extends Command
{
    protected $signature = 'fourthwall:check
                            {--shop= : the shop address (default: FOURTHWALL_SHOP)}
                            {--token= : a Storefront token, ptkn_… (default: FOURTHWALL_STOREFRONT_TOKEN)}
                            {--username= : Platform API user (default: FOURTHWALL_API_USERNAME)}
                            {--password= : Platform API password (default: FOURTHWALL_API_PASSWORD)}
                            {--collection=* : also list the products of these collections}
                            {--ask : prompt for everything, ignoring .env}
                            {--json : print everything as JSON instead}';

    protected $description = 'Show everything Fourthwall will tell you about your shop, with the credentials you give it';

    private array $report = [];

    public function handle(): int
    {
        $c = $this->credentials();
        $timeout = (int) config('fourthwall.timeout', 15);
        $currency = strtoupper((string) config('fourthwall.currency', 'USD'));

        if (! $c['shop'] && ! $c['token'] && ! $c['username']) {
            $this->error('Nothing to check with: give a shop address, a Storefront token or a Platform API user.');

            return self::FAILURE;
        }

        // Platform first: with an API user it can tell us the shop's address
        // and hand us the Storefront token, so the other two doors open too.
        $platform = new Platform((string) $c['username'], (string) $c['password'],
            rtrim(config('fourthwall.endpoints.platform'), '/'), $timeout);

        if ($platform->configured()) {
            $this->section('Platform API  (server-side, Basic auth)');
            $this->platform($platform, $c);
        }

        if ($c['shop']) {
            $this->section('Public feeds  (no credentials)');
            $this->source(new FeedSource($c['shop'], $timeout), 'feed');
        }

        if ($c['token']) {
            $this->section('Storefront API  (public token)');
            $this->source(new StorefrontSource($c['token'], rtrim(config('fourthwall.endpoints.storefront'), '/'), $currency, $c['shop'], $timeout), 'storefront');
        }

        // What these credentials, together, would switch on in a site.
        $fw = Fourthwall::fromConfig(['shop' => $c['shop'], 'storefront_token' => $c['token'],
            'api_username' => $c['username'], 'api_password' => $c['password'],
            'webhook' => config('fourthwall.webhook')] + config('fourthwall'), app('cache')->store('array'));
        $this->section('What this opens');
        foreach (\Ombabush\Fourthwall\Capability::cases() as $cap) {
            $on = $fw->supports($cap);
            $this->line(sprintf('  %s %s', $on ? '<fg=green>✓</>' : '<fg=gray>·</>', $cap->value));
            $this->report['capabilities'][$cap->value] = $on;
        }

        $this->section('For your .env');
        $env = array_filter([
            'FOURTHWALL_SHOP' => $c['shop'],
            'FOURTHWALL_STOREFRONT_TOKEN' => $c['token'],
            'FOURTHWALL_API_USERNAME' => $c['username'] ? '# SECRET — your API user' : null,
            'FOURTHWALL_API_PASSWORD' => $c['password'] ? '# SECRET — never commit' : null,
        ]);
        foreach ($env as $k => $v) {
            $this->line(str_starts_with((string) $v, '#') ? "  {$k}=   {$v}" : "  {$k}={$v}");
        }
        $this->report['env'] = array_keys($env);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Every door that was tried and failed is a non-zero exit, so a CI
        // job or a deploy gate can run this and trust the answer.
        return empty($this->report['errors']) ? self::SUCCESS : self::FAILURE;
    }

    private function credentials(): array
    {
        $fromEnv = ! $this->option('ask');
        $pick = fn (string $opt, string $key) => $this->option($opt) ?: ($fromEnv ? config('fourthwall.'.$key) : null);

        $c = [
            'shop' => $pick('shop', 'shop'),
            'token' => $pick('token', 'storefront_token'),
            'username' => $pick('username', 'api_username'),
            'password' => $pick('password', 'api_password'),
        ];

        if ($this->input->isInteractive() && ! $this->option('json')) {
            $c['shop'] ??= $this->ask('Shop address (https://shop.example.com) — enough on its own', null) ?: null;
            $c['token'] ??= $this->ask('Storefront token, ptkn_… (Settings → For developers; Enter to skip)', null) ?: null;

            if (! $c['username'] && $this->confirm('Check the Platform API too? (needs the Open API user)', false)) {
                $c['username'] = $this->ask('API username') ?: null;
                $c['password'] = $this->secret('API password') ?: null;
            }
        }

        if ($c['shop'] && ! str_contains($c['shop'], '://')) {
            $c['shop'] = 'https://'.$c['shop'];
        }

        return array_map(fn ($v) => $v === null ? null : trim((string) $v), $c);
    }

    private function platform(Platform $platform, array &$c): void
    {
        $out = [];

        $this->attempt('shop', function () use ($platform, &$c, &$out) {
            $s = $platform->shop();
            $out['shop'] = $s;
            $this->kv(['Name' => $s['name'] ?? '—', 'Id' => $s['id'] ?? '—', 'Domain' => ($s['publicDomain'] ?? '—').'  ('.($s['domain'] ?? '—').'.fourthwall.com)', 'Status' => $s['status'] ?? '—']);
            $c['shop'] ??= ! empty($s['publicDomain']) ? 'https://'.$s['publicDomain'] : null;
        });

        $this->attempt('storefront token', function () use ($platform, &$c, &$out) {
            $token = $platform->storefrontToken();
            $out['storefront_token'] = $token;
            $this->kv(['Storefront token' => $token.'   (public by design — safe in a browser)']);
            $c['token'] ??= $token;
        });

        $this->attempt('orders', function () use ($platform, &$out) {
            $o = $platform->orders(0, 20);
            $rows = $o['results'] ?? [];
            $out['orders'] = ['total' => $o['total'] ?? count($rows)];
            $this->kv(['Orders' => ($o['total'] ?? count($rows)).' in all']);
            $this->table(['#', 'When', 'Status', 'Total', 'Items'], array_map(fn ($r) => [
                $r['friendlyId'] ?? substr((string) ($r['id'] ?? ''), 0, 10),
                substr((string) ($r['createdAt'] ?? ''), 0, 10),
                $r['status'] ?? '',
                $this->money($r['amounts']['total'] ?? null),
                collect($r['offers'] ?? [])->map(fn ($x) => ($x['name'] ?? '?').' ×'.($x['variant']['quantity'] ?? $x['quantity'] ?? 1))->implode(', '),
            ], array_slice($rows, 0, 10)));
        });

        $this->attempt('donations', function () use ($platform, &$out) {
            $d = $platform->donations(0, 20);
            $rows = $d['results'] ?? [];
            $out['donations'] = ['total' => $d['total'] ?? count($rows)];
            $this->kv(['Donations' => ($d['total'] ?? count($rows)).' in all']);
            // Username and message are what the donor chose to show; their
            // e-mail is not, and is never printed.
            $this->table(['When', 'From', 'Amount', 'Status', 'Message'], array_map(fn ($r) => [
                substr((string) ($r['createdAt'] ?? ''), 0, 10),
                $r['username'] ?? '—',
                $this->money($r['amounts']['total'] ?? null),
                $r['status'] ?? '',
                mb_strimwidth((string) ($r['message'] ?? ''), 0, 50, '…'),
            ], array_slice($rows, 0, 10)));
        });

        $this->attempt('promotions', function () use ($platform, &$out) {
            $p = $platform->promotions();
            $rows = $p['results'] ?? [];
            $out['promotions'] = count($rows);
            $this->kv(['Promotions' => count($rows)]);
            $rows && $this->table(['Code', 'Type', 'Status'], array_map(fn ($r) => [
                $r['code'] ?? $r['title'] ?? $r['id'] ?? '?', $r['type'] ?? ($r['discount']['type'] ?? ''), $r['status'] ?? '',
            ], $rows));
        });

        $this->attempt('webhooks', function () use ($platform, &$out) {
            $w = $platform->webhooks();
            $rows = $w['results'] ?? $w;
            $out['webhooks'] = count($rows);
            $this->kv(['Webhooks' => count($rows)]);
            $rows && $this->table(['URL', 'Events'], array_map(fn ($r) => [$r['url'] ?? '?', implode(', ', $r['allowedTypes'] ?? $r['types'] ?? [])], $rows));
        });

        $this->attempt('membership tiers', function () use ($platform, &$out) {
            $t = $platform->membershipTiers();
            $rows = $t['results'] ?? $t;
            $out['membership_tiers'] = count($rows);
            $this->kv(['Membership tiers' => count($rows) ?: 'none']);
        });

        $this->report['platform'] = $out;
    }

    private function source(FeedSource|StorefrontSource $source, string $label): void
    {
        $out = [];

        $this->attempt('shop', function () use ($source, &$out) {
            $s = $source->shop();
            $out['shop'] = $s?->toArray();
            $s && $this->kv(['Shop' => $s->name, 'Address' => $s->url, 'Donations' => $s->donationUrl()]);
        });

        $this->attempt('collections', function () use ($source, &$out) {
            $cols = $source->collections();
            $out['collections'] = array_map(fn ($c) => $c->toArray(), $cols);
            $this->kv(['Collections' => implode(', ', array_map(fn ($c) => "{$c->name} [{$c->slug}]", $cols)) ?: 'none']);
        });

        foreach (array_unique(array_merge(['all'], (array) $this->option('collection'))) as $handle) {
            $this->attempt("products in «{$handle}»", function () use ($source, $handle, &$out) {
                $products = $source->products($handle);
                $out['products'][$handle] = array_map(fn (Product $p) => $p->toArray(), $products);

                $this->line("  <options=bold>{$handle}</> — ".count($products).' product(s)');
                $this->table(['Product', 'Price', 'Colours', 'Sizes', 'Variants', 'Images', 'Available'], array_map(fn (Product $p) => [
                    mb_strimwidth($p->name, 0, 44, '…'),
                    $p->price->format('en').($p->hasPriceRange() ? '–'.$p->maxPrice->format('en') : ''),
                    count($p->colors()),
                    implode(' ', $p->sizes()),
                    count($p->variants).' ('.collect($p->variants)->where('available', false)->count().' sold out)',
                    count($p->images),
                    $p->available ? 'yes' : 'SOLD OUT',
                ], $products));

                foreach ($products as $p) {
                    $this->line("  <fg=gray>{$p->slug}</>  {$p->url}");
                }
            });
        }

        $this->report[$label] = $out;
    }

    private function attempt(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->line("  <fg=red>✗ {$what}:</> ".$e->getMessage());
            $this->report['errors'][] = "{$what}: ".$e->getMessage();
        }
    }

    private function section(string $title): void
    {
        if (! $this->option('json')) {
            $this->newLine();
            $this->line("<options=bold;fg=cyan>━━ {$title}</>");
        }
    }

    private function kv(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            $this->line(sprintf('  <fg=gray>%-18s</> %s', $k, $v));
        }
    }

    private function money(?array $m): string
    {
        return isset($m['value']) ? Money::fromDecimal($m['value'], $m['currency'] ?? 'USD')->format('en', true) : '—';
    }

    /** In --json mode everything human goes nowhere; only the report is printed. */
    public function line($string, $style = null, $verbosity = null)
    {
        if (! $this->option('json')) {
            parent::line($string, $style, $verbosity);
        }
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        if (! $this->option('json') && $rows) {
            parent::table($headers, $rows, $tableStyle, $columnStyles);
        }
    }
}
