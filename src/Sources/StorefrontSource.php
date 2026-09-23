<?php

namespace Ombabush\Fourthwall\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Ombabush\Fourthwall\Data\Image;
use Ombabush\Fourthwall\Data\Money;
use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Data\Shop;
use Ombabush\Fourthwall\Data\ShopCollection;
use Ombabush\Fourthwall\Data\Variant;
use Ombabush\Fourthwall\Exceptions\FourthwallException;

/**
 * The Storefront API — `storefront-api.fourthwall.com/v1`, with the public
 * `ptkn_…` token as a query parameter, exactly as Fourthwall's own Next.js
 * starter (FourthwallHQ/vercel-commerce) calls it.
 *
 *   GET /shop
 *   GET /collections
 *   GET /collections/{slug}/products?currency=&page=&size=
 *   GET /products/{slug}?currency=
 */
class StorefrontSource implements Source
{
    private ?Shop $shop = null;

    public function __construct(
        private readonly string $token,
        private readonly string $endpoint = 'https://storefront-api.fourthwall.com/v1',
        private readonly string $currency = 'USD',
        private readonly ?string $shopUrl = null,
        private readonly int $timeout = 15,
        private readonly int $maxPages = 20,
    ) {}

    public function name(): string
    {
        return 'storefront';
    }

    public function shop(): Shop
    {
        if ($this->shop) {
            return $this->shop;
        }

        $this->assertSameShop();
        $s = $this->shopPayload();
        $domain = $s['publicDomain'] ?? null ?: (($s['domain'] ?? null) ? $s['domain'].'.fourthwall.com' : null);

        return $this->shop = new Shop(
            name: (string) ($s['name'] ?? ''),
            url: $this->shopUrl ? rtrim($this->shopUrl, '/') : 'https://'.$domain,
            id: $s['id'] ?? null,
            domain: $s['domain'] ?? null,
        );
    }

    public function collections(): array
    {
        $this->assertSameShop();

        return array_map(fn (array $c) => new ShopCollection(
            slug: (string) $c['slug'],
            name: (string) ($c['name'] ?? $c['slug']),
            description: (string) ($c['description'] ?? ''),
            id: $c['id'] ?? null,
        ), $this->paged('/collections'));
    }

    public function products(string $collection): array
    {
        $this->assertSameShop();

        $rows = $this->paged('/collections/'.rawurlencode($collection).'/products', ['currency' => $this->currency]);

        return array_values(array_filter(array_map(
            fn (array $p) => $this->map($p, [$collection]), $rows
        )));
    }

    public function product(string $slug): ?Product
    {
        $this->assertSameShop();

        try {
            return $this->map($this->get('/products/'.rawurlencode($slug), ['currency' => $this->currency]));
        } catch (FourthwallException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A storefront token belongs to ONE shop, and it is easy to create it in
     * the wrong one — Fourthwall's admin switches between a user's shops in a
     * corner. A token from another shop would put that shop's products on
     * this page with links into this shop's checkout, where they do not
     * exist. So when the site names its shop, the token's shop must be it.
     */
    public function assertSameShop(): void
    {
        if (! $this->shopUrl) {
            return;
        }

        $want = strtolower((string) parse_url(str_contains($this->shopUrl, '://') ? $this->shopUrl : 'https://'.$this->shopUrl, PHP_URL_HOST));
        $s = $this->shopPayload();
        $hosts = array_filter([
            strtolower((string) ($s['publicDomain'] ?? '')),
            ! empty($s['domain']) ? strtolower($s['domain']).'.fourthwall.com' : null,
        ]);

        if (! in_array($want, $hosts, true)) {
            throw new FourthwallException(sprintf(
                'The storefront token belongs to «%s» (%s), not to %s. Create the token in that shop\'s admin.',
                $s['name'] ?? '?', implode(', ', $hosts) ?: '?', $want
            ));
        }
    }

    private ?array $shopPayload = null;

    private function shopPayload(): array
    {
        return $this->shopPayload ??= $this->get('/shop');
    }

    /**
     * One payload → one Product, or null when it must not be shown.
     */
    public function map(array $p, array $collections = []): ?Product
    {
        // HIDDEN, PRIVATE and ARCHIVED products come back from some endpoints.
        // They are not for a public page, and deciding that is our job here,
        // not every template's.
        if (($p['access']['type'] ?? 'PUBLIC') !== 'PUBLIC') {
            return null;
        }

        $type = ($p['type'] ?? 'PRODUCT') === 'BUNDLE' ? Product::TYPE_BUNDLE : Product::TYPE_PRODUCT;
        $name = (string) ($p['name'] ?? '');
        $images = $this->images($p['images'] ?? [], $name);
        $variants = array_map(fn (array $v) => $this->variant($v, $name), $p['variants'] ?? []);
        $soldOut = ($p['state']['type'] ?? 'AVAILABLE') !== 'AVAILABLE';

        if ($type === Product::TYPE_BUNDLE) {
            // A bundle has no variants of its own: its price is on the root,
            // for the cheapest selection of what it contains.
            $price = Money::fromDecimal($p['price']['value'] ?? 0, $p['price']['currency'] ?? $this->currency);
            $max = null;
            $compareAt = isset($p['compareAtPrice']['value'])
                ? Money::fromDecimal($p['compareAtPrice']['value'], $p['compareAtPrice']['currency'] ?? $price->currency)
                : null;
        } else {
            $sorted = $variants;
            usort($sorted, fn (Variant $a, Variant $b) => $a->price->minor <=> $b->price->minor);
            $cheapest = $sorted[0] ?? null;
            $price = $cheapest?->price ?? new Money(0, $this->currency);
            $max = end($sorted) ?: null;
            $max = $max?->price;
            $compareAt = $cheapest?->compareAt;
        }

        return new Product(
            id: (string) $p['id'],
            slug: (string) $p['slug'],
            name: $name,
            description: (string) ($p['description'] ?? ''),
            url: rtrim($this->shopUrl ?? $this->shop()->url, '/').'/products/'.$p['slug'],
            price: $price,
            maxPrice: $max,
            compareAt: $compareAt,
            available: ! $soldOut && ($type === Product::TYPE_BUNDLE || collect($variants)->contains('available', true)),
            type: $type,
            images: $images,
            variants: $variants,
            collections: $collections,
            createdAt: $p['createdAt'] ?? null,
            updatedAt: $p['updatedAt'] ?? null,
        );
    }

    private function variant(array $v, string $productName): Variant
    {
        $stock = $v['stock'] ?? ['type' => 'UNLIMITED'];
        $limited = ($stock['type'] ?? 'UNLIMITED') === 'LIMITED';

        return new Variant(
            id: (string) $v['id'],
            // `attributes.description` is «White, XS»; `name` repeats the whole
            // product name in front of it, which a variant picker does not need.
            name: (string) ($v['attributes']['description'] ?? null ?: $v['name'] ?? ''),
            price: Money::fromDecimal($v['unitPrice']['value'] ?? 0, $v['unitPrice']['currency'] ?? $this->currency),
            compareAt: isset($v['compareAtPrice']['value'])
                ? Money::fromDecimal($v['compareAtPrice']['value'], $v['compareAtPrice']['currency'] ?? $this->currency)
                : null,
            sku: $v['sku'] ?? null,
            color: $v['attributes']['color']['name'] ?? null,
            // It goes into a style attribute; only a colour is let through.
            swatch: preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($v['attributes']['color']['swatch'] ?? '')) ? $v['attributes']['color']['swatch'] : null,
            size: $v['attributes']['size']['name'] ?? null,
            available: ! $limited || (int) ($stock['inStock'] ?? 0) > 0,
            stock: $limited ? (int) ($stock['inStock'] ?? 0) : null,
            images: $this->images($v['images'] ?? [], $productName),
        );
    }

    /** @return Image[] */
    private function images(array $images, string $alt): array
    {
        return array_map(fn (array $i) => new Image(
            url: (string) ($i['transformedUrl'] ?? null ?: $i['url']),
            width: $i['width'] ?? null,
            height: $i['height'] ?? null,
            alt: $alt,
        ), $images);
    }

    private function paged(string $path, array $query = []): array
    {
        $rows = [];

        for ($page = 0; $page < $this->maxPages; $page++) {
            $body = $this->get($path, $query + ['page' => $page, 'size' => 50]);
            array_push($rows, ...($body['results'] ?? []));

            if (! ($body['paging']['hasNextPage'] ?? false)) {
                break;
            }
        }

        return $rows;
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->http()->get($this->endpoint.$path, $query + ['storefront_token' => $this->token]);

        if (! $response->successful()) {
            throw FourthwallException::fromResponse($response, "GET {$path}");
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent('ombabush/laravel-fourthwall')
            ->timeout($this->timeout)
            ->retry(2, 250, throw: false);
    }
}
