<?php

namespace Ombabush\Fourthwall;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Ombabush\Fourthwall\Data\Money;
use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Data\Shop;
use Ombabush\Fourthwall\Data\ShopCollection;
use Ombabush\Fourthwall\Exceptions\FourthwallException;
use Ombabush\Fourthwall\Sources\ArraySource;
use Ombabush\Fourthwall\Sources\FallbackSource;
use Ombabush\Fourthwall\Sources\FeedSource;
use Ombabush\Fourthwall\Sources\NullSource;
use Ombabush\Fourthwall\Sources\Source;
use Ombabush\Fourthwall\Sources\StorefrontSource;
use Throwable;

/**
 * The one object a site talks to.
 *
 *   Fourthwall::products('archive')->available()->take(3)->get();
 *   Fourthwall::product('mosquito-scan-1993-black-print-t-shirt');
 *   Fourthwall::donationUrl(10);
 *
 * Reads go through a cache that keeps the LAST GOOD COPY for ever: a copy
 * older than `cache.ttl` is refetched on read, and if that fetch fails the old
 * copy is served and the failure logged. A shelf that showed three t-shirts
 * an hour ago keeps showing them while Fourthwall has a bad afternoon.
 *
 * This is the Laravel shape of what Fourthwall's own Next.js starter does
 * with `revalidate: 3600` and cache tags purged by webhook — and, like it,
 * the package keeps nothing in your database.
 */
class Fourthwall
{
    /** @var array<string, mixed> read-once memo for the life of the instance */
    private array $memo = [];

    public function __construct(
        private readonly Source $source,
        private readonly Cache $cache,
        private readonly array $config = [],
    ) {}

    /**
     * Build from a config array — the package's own, or one per shop when a
     * site shows somebody else's: `Fourthwall::for(['shop' => …])`.
     */
    public static function fromConfig(array $config, Cache $cache): self
    {
        return new self(self::sourceFor($config), $cache, $config);
    }

    public static function sourceFor(array $config): Source
    {
        $source = $config['source'] ?? 'auto';
        $timeout = (int) ($config['timeout'] ?? 15);
        $pages = (int) ($config['max_pages'] ?? 20);

        if ($source === 'auto') {
            $source = match (true) {
                self::looksLikeToken($config['storefront_token'] ?? null) => 'storefront',
                ! empty($config['shop']) => 'feed',
                ! empty($config['catalogue']['products']) => 'array',
                default => 'null',
            };
        }

        $storefront = fn () => new StorefrontSource(
            token: (string) ($config['storefront_token'] ?? throw FourthwallException::notConfigured('FOURTHWALL_STOREFRONT_TOKEN')),
            endpoint: rtrim($config['endpoints']['storefront'] ?? 'https://storefront-api.fourthwall.com/v1', '/'),
            currency: strtoupper($config['currency'] ?? 'USD'),
            shopUrl: $config['shop'] ?? null,
            timeout: $timeout,
            maxPages: $pages,
        );

        return match ($source) {
            // With the shop's address known, a failing Storefront API falls
            // back to the public feeds rather than emptying every shelf.
            'storefront' => ! empty($config['shop'])
                ? new FallbackSource($storefront(), new FeedSource((string) $config['shop'], $timeout, $pages))
                : $storefront(),
            'feed' => new FeedSource(
                shopUrl: (string) ($config['shop'] ?? throw FourthwallException::notConfigured('FOURTHWALL_SHOP')),
                timeout: $timeout,
                maxPages: $pages,
            ),
            'array' => new ArraySource($config['catalogue'] ?? []),
            'null' => new NullSource,
            default => throw new FourthwallException("Unknown Fourthwall source «{$source}»."),
        };
    }

    /**
     * Storefront tokens start `ptkn_`. Anything else in that variable is a
     * mistake — often a password pasted into the wrong line — and is neither
     * used nor printed.
     */
    public static function looksLikeToken(?string $token): bool
    {
        return is_string($token) && str_starts_with(trim($token), 'ptkn_');
    }

    /**
     * Another shop, same cache: `Fourthwall::for(['shop' => 'https://their.shop'])`.
     * Keys include the shop, so two shops never read each other's catalogue.
     */
    public function for(array $config): self
    {
        // Our shop's identity and credentials never leak into theirs; the
        // plumbing (endpoints, timeouts, cache settings) is shared.
        $plumbing = array_diff_key($this->config, array_flip(['shop', 'storefront_token', 'api_username', 'api_password', 'catalogue', 'source']));

        return self::fromConfig($config + ['source' => 'auto'] + $plumbing, $this->cache);
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function configured(): bool
    {
        return ! $this->source instanceof NullSource;
    }

    public function shop(): ?Shop
    {
        $a = $this->remember('shop', fn () => $this->source->shop()?->toArray());

        if ($a) {
            return Shop::fromArray($a);
        }

        // Nothing cached and nothing fetched — but if we were told where the
        // shop is, links out can still be built.
        return ! empty($this->config['shop'])
            ? new Shop((string) parse_url($this->shopUrl(), PHP_URL_HOST), $this->shopUrl())
            : null;
    }

    /** The shop's address, from config first so it never needs a request. */
    public function shopUrl(): ?string
    {
        if (! empty($this->config['shop'])) {
            $url = rtrim($this->config['shop'], '/');

            return str_contains($url, '://') ? $url : 'https://'.$url;
        }

        return $this->shop()?->url;
    }

    /** @return Collection<int, ShopCollection> */
    public function collections(): Collection
    {
        return collect($this->remember('collections', fn () => array_map(
            fn (ShopCollection $c) => $c->toArray(), $this->source->collections()
        )) ?? [])->map(fn ($a) => ShopCollection::fromArray($a));
    }

    public function collection(string $slug): ?ShopCollection
    {
        return $this->collections()->firstWhere('slug', $slug);
    }

    /**
     * Start a selection. No collection means `all` — every public product.
     */
    public function products(string|array|null $collections = null): ProductQuery
    {
        $collections = array_values(array_filter((array) ($collections ?? 'all'))) ?: ['all'];

        return new ProductQuery(fn (string $handle) => $this->productsIn($handle), $collections);
    }

    /** @return Product[] */
    public function productsIn(string $collection): array
    {
        return array_map(
            fn (array $a) => Product::fromArray($a),
            $this->remember('collection.'.$collection, fn () => array_map(
                fn (Product $p) => $p->toArray(), $this->source->products($collection)
            )) ?? []
        );
    }

    public function product(string $slug): ?Product
    {
        // Almost always already in `all`, which is almost always cached.
        foreach ($this->productsIn('all') as $p) {
            if ($p->slug === $slug) {
                return $p;
            }
        }

        $a = $this->remember('product.'.$slug, fn () => $this->source->product($slug)?->toArray());

        return $a ? Product::fromArray($a) : null;
    }

    /**
     * Where a card for this product should link: your own page for it if
     * `product_route` is set, otherwise its page on the shop — tagged with
     * `link_params` so the shop knows who sent the visitor.
     */
    public function productLink(Product $product, array $params = []): string
    {
        if ($route = $this->config['product_route'] ?? null) {
            return route($route, $product->slug);
        }

        return $this->tag($product->url, $params);
    }

    /** Straight to checkout with this product — one variant, one of it. */
    public function buyLink(Product $product, ?string $variantId = null, array $params = []): string
    {
        return $product->checkoutUrl($variantId, 1, $this->linkParams($params) + array_filter([
            'currency' => $this->config['currency'] ?? null,
        ]));
    }

    /** `link_params` from config, overridden by what is passed. */
    public function linkParams(array $params = []): array
    {
        return array_filter($params + ($this->config['link_params'] ?? []), fn ($v) => $v !== null && $v !== '');
    }

    private function tag(string $url, array $params = []): string
    {
        $params = $this->linkParams($params);

        return $params ? $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params) : $url;
    }

    /**
     * Into checkout with several things at once, still without a cart:
     * `checkoutUrl(['variant-uuid' => 2, 'other-uuid' => 1], coupon: 'SNIFF10')`.
     *
     * Extra parameters are passed through — `utm_source`, `utm_campaign` and
     * the like are recorded by Fourthwall against the order.
     */
    public function checkoutUrl(array $lines, ?string $coupon = null, ?string $currency = null, array $params = []): string
    {
        $products = collect($lines)->map(fn ($qty, $id) => $id.':'.max(1, (int) $qty))->implode(',');

        return $this->shopUrl().'/cart/checkout?'.http_build_query(array_filter([
            'products' => $products,
            'coupon' => $coupon,
            'currency' => $currency ?? ($this->config['currency'] ?? null),
        ]) + $this->linkParams($params));
    }

    /**
     * Fourthwall's own donation page, pre-filled — the same GET its shop
     * form sends: /donation/?amount=10.00&currency=USD&donor=…&message=…
     *
     * @param  array<int|float|string>  $options  the amounts offered on that page
     */
    public function donationUrl(int|float|string|null $amount = null, ?string $donor = null, ?string $message = null, array $options = [], ?string $currency = null): string
    {
        $currency = strtoupper($currency ?? ($this->config['currency'] ?? 'USD'));
        $dec = fn ($v) => Money::fromDecimal(is_string($v) ? $v : (string) $v, $currency)->decimal();

        return $this->shopUrl().'/donation/?'.http_build_query(array_filter([
            'donor' => $donor,
            'message' => $message !== null ? mb_substr($message, 0, 200) : null,
            'amount' => $amount !== null ? $dec($amount) : null,
            'currency' => $currency,
            'donationOpts' => array_map($dec, $options) ?: null,
        ]) + $this->linkParams());
    }

    /**
     * Can this shop, with the credentials given, do this here?
     */
    public function supports(Capability $capability): bool
    {
        $name = $this->source->name();

        return match ($capability) {
            Capability::Catalogue => $this->configured(),
            Capability::Collections => in_array($name, ['storefront', 'array'], true),
            Capability::Stock => $name === 'storefront',
            Capability::CheckoutLinks, Capability::Donations => (bool) $this->shopUrlOrNull(),
            Capability::Carts => self::looksLikeToken($this->config['storefront_token'] ?? null) && (bool) $this->shopUrlOrNull()
                && $this->tokenIsThisShops(),
            Capability::Promotions, Capability::Supporters => $this->platform()->configured(),
            Capability::Webhooks => ! empty($this->config['webhook']['path']) && ! empty($this->config['webhook']['secret']),
        };
    }

    /**
     * Does the storefront token belong to the configured shop? Asked of
     * Fourthwall once and cached like the catalogue, so a page does not pay
     * for it. A token from another shop never switches the cart on.
     */
    public function tokenIsThisShops(): bool
    {
        $token = $this->config['storefront_token'] ?? null;

        if (! self::looksLikeToken($token) || empty($this->config['shop'])) {
            return false;
        }

        return (bool) $this->remember('token_matches', function () use ($token) {
            try {
                (new StorefrontSource((string) $token, rtrim($this->config['endpoints']['storefront'] ?? 'https://storefront-api.fourthwall.com/v1', '/'),
                    'USD', (string) $this->config['shop'], (int) ($this->config['timeout'] ?? 15)))->assertSameShop();

                return true;
            } catch (FourthwallException $e) {
                // A refusal is an answer worth caching; a network failure is not.
                if ($e->status === null || in_array($e->status, [401, 403], true)) {
                    Log::warning('[fourthwall] '.$e->getMessage());

                    return false;
                }

                throw $e;
            }
        });
    }

    /** @return Capability[] every capability that is on */
    public function capabilities(): array
    {
        return array_values(array_filter(Capability::cases(), fn (Capability $c) => $this->supports($c)));
    }

    /**
     * The visitor's cart, or null when carts are not available — no
     * storefront token. Callers degrade to buyLink() then.
     */
    public function cart(): ?Cart
    {
        if (! $this->supports(Capability::Carts)) {
            return null;
        }

        return $this->memo['__cart'] ??= new Cart(
            token: (string) $this->config['storefront_token'],
            shopUrl: (string) $this->shopUrl(),
            session: app('session.store'),
            endpoint: rtrim($this->config['endpoints']['storefront'] ?? 'https://storefront-api.fourthwall.com/v1', '/'),
            currency: strtoupper($this->config['currency'] ?? 'USD'),
            metadata: $this->config['cart']['metadata'] ?? [],
            timeout: (int) ($this->config['timeout'] ?? 15),
        );
    }

    /**
     * The shop's promotions that are live now, reduced to what a banner can
     * say: the code, a title, and the discount in words the page can print.
     * Needs the Platform API user; cached like the catalogue.
     *
     * @return array<int, array{id: string, code: ?string, title: ?string, type: string, discount: array, automatic: bool}>
     */
    public function promotions(): array
    {
        if (! $this->supports(Capability::Promotions)) {
            return [];
        }

        return $this->remember('platform.promotions', fn () => self::livePromotions($this->platform()->promotions()['results'] ?? [])) ?? [];
    }

    /**
     * Recent completed donations for a thank-you wall: the name the donor
     * typed, the amount and the message. NEVER the e-mail address, which
     * Fourthwall includes and which this method drops before anything is
     * cached. Needs the Platform API user.
     *
     * @return array<int, array{name: ?string, amount: ?Money, message: ?string, at: ?string}>
     */
    public function supporters(int $limit = 12): array
    {
        if (! $this->supports(Capability::Supporters)) {
            return [];
        }

        $rows = $this->remember('platform.supporters', fn () => self::publicDonations($this->platform()->donations(0, 50)['results'] ?? [])) ?? [];

        return array_map(fn (array $r) => ['amount' => Money::fromArray($r['amount'])] + $r, array_slice($rows, 0, $limit));
    }

    /** @internal public for tests */
    public static function livePromotions(array $rows): array
    {
        return array_values(array_map(fn (array $p) => [
            'id' => (string) ($p['id'] ?? ''),
            'code' => $p['code'] ?? null,
            'title' => $p['title'] ?? null,
            'type' => (string) ($p['type'] ?? ''),
            'automatic' => ($p['type'] ?? '') === 'SHOP_AUTO_APPLYING',
            'discount' => array_filter([
                'type' => $p['discount']['type'] ?? null,
                'percentage' => $p['discount']['percentage'] ?? null,
                'amount' => isset($p['discount']['money']['value'])
                    ? Money::fromDecimal($p['discount']['money']['value'], $p['discount']['money']['currency'] ?? 'USD')->toArray()
                    : null,
                'free_shipping' => ($p['discount']['type'] ?? null) === 'FREE_SHIPPING' || ! empty($p['discount']['freeShipping']),
            ], fn ($v) => $v !== null && $v !== false),
        ], array_filter($rows, fn (array $p) => ($p['status'] ?? '') === 'Live'
            // Membership promotions are for members only, and a public banner
            // advertising one would promise a discount most visitors cannot use.
            && str_starts_with((string) ($p['type'] ?? ''), 'SHOP_'))));
    }

    /** @internal public for tests */
    public static function publicDonations(array $rows): array
    {
        return array_values(array_map(fn (array $d) => [
            'name' => ($d['username'] ?? '') !== '' ? mb_substr((string) $d['username'], 0, 60) : null,
            'amount' => isset($d['amounts']['total']['value'])
                ? Money::fromDecimal($d['amounts']['total']['value'], $d['amounts']['total']['currency'] ?? 'USD')->toArray()
                : null,
            'message' => ($d['message'] ?? '') !== '' ? mb_substr((string) $d['message'], 0, 200) : null,
            'at' => $d['createdAt'] ?? null,
        ], array_filter($rows, fn (array $d) => ($d['status'] ?? '') === 'COMPLETED')));
    }

    private function shopUrlOrNull(): ?string
    {
        return ! empty($this->config['shop']) ? $this->shopUrl() : null;
    }

    /** The Platform API — server-side only. */
    public function platform(): Platform
    {
        return new Platform(
            username: (string) ($this->config['api_username'] ?? ''),
            password: (string) ($this->config['api_password'] ?? ''),
            endpoint: rtrim($this->config['endpoints']['platform'] ?? 'https://api.fourthwall.com/open-api/v1.0', '/'),
            timeout: (int) ($this->config['timeout'] ?? 15),
        );
    }

    /**
     * Fetch now and replace what is cached — one thing at a time.
     *
     *   refresh()                  the shop and its collection list
     *   refresh(collection: 'x')   the products of one collection
     *   refresh(product: 'slug')   one product
     *
     * Throws when the fetch fails: a refresh is asked for BECAUSE someone
     * wants the new answer, and quietly serving the old one would lie.
     *
     * @return array{what: string, count: int, before: int}
     */
    public function refresh(?string $collection = null, ?string $product = null): array
    {
        if ($product !== null) {
            $before = $this->peek('product.'.$product) ? 1 : 0;
            $data = $this->source->product($product)?->toArray();
            $this->put('product.'.$product, $data);

            return ['what' => "product {$product}", 'count' => $data ? 1 : 0, 'before' => $before];
        }

        if ($collection !== null) {
            $before = count($this->peek('collection.'.$collection) ?? []);
            $data = array_map(fn (Product $p) => $p->toArray(), $this->source->products($collection));

            // An empty answer where there used to be products is far more
            // often a bad afternoon at Fourthwall than a shop emptied on
            // purpose; keep what we had and say so.
            if ($data === [] && $before > 0) {
                throw new FourthwallException("Collection «{$collection}» came back empty; keeping the {$before} product(s) cached.");
            }

            $this->put('collection.'.$collection, $data);

            return ['what' => "collection {$collection}", 'count' => count($data), 'before' => $before];
        }

        $this->put('shop', $this->source->shop()?->toArray());
        $collections = array_map(fn (ShopCollection $c) => $c->toArray(), $this->source->collections());
        $before = count($this->peek('collections') ?? []);
        $this->put('collections', $collections);

        return ['what' => 'shop and collection list', 'count' => count($collections), 'before' => $before];
    }

    /**
     * Refetch what the Platform API user can see — promotions and supporters.
     * Nothing when there is no API user.
     *
     * @return array<string, int>
     */
    public function refreshPlatform(): array
    {
        if (! $this->platform()->configured()) {
            return [];
        }

        $this->put('platform.promotions', self::livePromotions($this->platform()->promotions()['results'] ?? []));
        $this->put('platform.supporters', self::publicDonations($this->platform()->donations(0, 50)['results'] ?? []));

        return ['promotions' => count($this->peek('platform.promotions')), 'supporters' => count($this->peek('platform.supporters'))];
    }

    /** Drop one cached thing, or — with no argument — everything cached for this shop. */
    public function forget(?string $what = null): void
    {
        $keys = $what !== null ? [$what] : array_merge(['shop', 'collections'], $this->peek('_keys') ?? []);

        foreach ($keys as $k) {
            $this->cache->forget($this->key($k));
            unset($this->memo[$k]);
        }
    }

    /** When was this last fetched? null if never. */
    public function fetchedAt(string $what): ?int
    {
        return $this->cache->get($this->key($what))['at'] ?? null;
    }

    /**
     * Last-good-copy cache. See the class comment.
     */
    private function remember(string $what, callable $fetch): mixed
    {
        if (array_key_exists($what, $this->memo)) {
            return $this->memo[$what];
        }

        // The Platform API does not need a catalogue source — only its user.
        if ($this->source instanceof NullSource && ! str_starts_with($what, 'platform.')) {
            return $this->memo[$what] = null;
        }

        $entry = $this->cache->get($this->key($what));
        // `null` is a real value here — never stale on read — so not `??`.
        $ttl = array_key_exists('ttl', $this->config['cache'] ?? []) ? $this->config['cache']['ttl'] : 3600;

        if (is_array($entry) && ($ttl === null || time() - $entry['at'] < $ttl)) {
            return $this->memo[$what] = $entry['data'];
        }

        try {
            $data = $fetch();
            $this->put($what, $data);

            return $data;
        } catch (Throwable $e) {
            Log::warning('[fourthwall] '.$what.': '.$e->getMessage().(is_array($entry) ? ' — serving the copy from '.date('c', $entry['at']) : ' — nothing cached to fall back on'));

            return $this->memo[$what] = is_array($entry) ? $entry['data'] : null;
        }
    }

    private function peek(string $what): mixed
    {
        return $this->cache->get($this->key($what))['data'] ?? null;
    }

    private function put(string $what, mixed $data): void
    {
        $this->cache->forever($this->key($what), ['at' => time(), 'data' => $data]);
        $this->memo[$what] = $data;

        // Remember which keys exist, so forget() can find per-collection and
        // per-product entries without a cache that supports tags.
        if (! in_array($what, ['shop', 'collections', '_keys'], true)) {
            $keys = $this->peek('_keys') ?? [];

            if (! in_array($what, $keys, true)) {
                $keys[] = $what;
                $this->cache->forever($this->key('_keys'), ['at' => time(), 'data' => $keys]);
            }
        }
    }

    private function key(string $what): string
    {
        return implode(':', [
            $this->config['cache']['prefix'] ?? 'fourthwall',
            $this->source->name(),
            md5(($this->config['shop'] ?? '').'|'.($this->config['storefront_token'] ?? '')),
            strtoupper($this->config['currency'] ?? 'USD'),
            $what,
        ]);
    }
}
