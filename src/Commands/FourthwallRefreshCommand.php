<?php

namespace Ombabush\Fourthwall\Commands;

use Illuminate\Console\Command;
use Ombabush\Fourthwall\Fourthwall;
use Throwable;

/**
 * Fetch now and replace the cached copy — what the scheduler runs when the
 * site is set never to fetch on read (`FOURTHWALL_CACHE_TTL=never`).
 *
 * One thing per step, each reported as it is done: the shop, then each
 * collection named, then each product named. A failure leaves the old copy
 * in place and says so.
 *
 *   php artisan fourthwall:refresh                          shop + `all`
 *   php artisan fourthwall:refresh --collection=archive     …and that one
 *   php artisan fourthwall:refresh --product=some-slug      just that product
 */
class FourthwallRefreshCommand extends Command
{
    protected $signature = 'fourthwall:refresh
                            {--collection=* : collection handles to refresh (default: all)}
                            {--product=* : product slugs to refresh}
                            {--forget : drop everything cached for this shop first}';

    protected $description = 'Refresh the cached Fourthwall catalogue, one collection or product at a time';

    public function handle(Fourthwall $fourthwall): int
    {
        if (! $fourthwall->configured()) {
            $this->warn('No shop configured (FOURTHWALL_SHOP or FOURTHWALL_STOREFRONT_TOKEN) — nothing to refresh.');

            return self::SUCCESS;
        }

        if ($this->option('forget')) {
            $fourthwall->forget();
            $this->line('  forgot everything cached');
        }

        $steps = [];

        if (! $this->option('product')) {
            $steps[] = [null, null];

            foreach ($this->option('collection') ?: ['all'] as $c) {
                $steps[] = [$c, null];
            }
        }

        foreach ($this->option('product') as $p) {
            $steps[] = [null, $p];
        }

        $failed = 0;

        foreach ($steps as [$collection, $product]) {
            try {
                $r = $fourthwall->refresh($collection, $product);
                $this->line(sprintf('  <fg=green>✓</> %-32s %d (was %d)', $r['what'], $r['count'], $r['before']));
            } catch (Throwable $e) {
                $failed++;
                $this->line('  <fg=red>✗</> '.($product ?? $collection ?? 'shop').': '.$e->getMessage());
            }
        }

        if (! $this->option('product') && ! $this->option('collection')) {
            try {
                foreach ($fourthwall->refreshPlatform() as $what => $n) {
                    $this->line(sprintf('  <fg=green>✓</> %-32s %d', $what, $n));
                }
            } catch (Throwable $e) {
                $failed++;
                $this->line('  <fg=red>✗</> platform: '.$e->getMessage());
            }
        }

        $this->line('  source: '.$fourthwall->source()->name().'   can: '.implode(', ', array_map(fn ($c) => $c->value, $fourthwall->capabilities())));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
