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
use SimpleXMLElement;

/**
 * A catalogue read from the shop's own public feeds — no token, no account.
 *
 * Every Fourthwall shop publishes two, documented under «Shop Feeds»:
 *
 *   {shop}/collections/{slug}.json, /{slug}/2.json …
 *       products in a collection: id, handle, one image, prices already in
 *       cents, and every variant's id — which is what checkout takes.
 *
 *   {shop}/.well-known/merchant-center/rss.xml
 *       the Google Merchant Center feed: one item per variant, with the
 *       description, colour, size, stock and a picture of THAT colour.
 *
 * Neither alone is enough; together they are most of what the Storefront API
 * gives. What they cannot do: list collections (only `all` and the handles
 * you name are reachable), show stock counts, or tell a bundle's contents.
 */
class FeedSource implements Source
{
    /** @var array<string, array>|null  item_group_id => merged merchant-center data */
    private ?array $merchant = null;

    private ?string $shopName = null;

    public function __construct(
        private readonly string $shopUrl,
        private readonly int $timeout = 15,
        private readonly int $maxPages = 20,
    ) {}

    public function name(): string
    {
        return 'feed';
    }

    public function shop(): Shop
    {
        $this->merchant();

        return new Shop(
            name: $this->shopName ?: (string) parse_url($this->shopUrl, PHP_URL_HOST),
            url: $this->base(),
            domain: parse_url($this->shopUrl, PHP_URL_HOST) ?: null,
        );
    }

    /**
     * Only `all` is discoverable without a token. Named collections still
     * work in products() — they just cannot be listed.
     */
    public function collections(): array
    {
        $first = $this->page('all', 1);

        return [new ShopCollection('all', (string) ($first['title'] ?? 'All Products'), '', $first['id'] ?? null)];
    }

    public function products(string $collection): array
    {
        $out = [];
        $seen = [];

        for ($n = 1; $n <= $this->maxPages; $n++) {
            $page = $this->page($collection, $n);
            $rows = $page['products'] ?? [];

            // The feed does not say how many pages there are; an empty page,
            // or a page that repeats one we have, is the end.
            if ($rows === [] || isset($seen[$rows[0]['id'] ?? ''])) {
                break;
            }

            foreach ($rows as $row) {
                $seen[$row['id']] = true;
                $out[] = $this->map($row, [$collection]);
            }
        }

        return $out;
    }

    public function product(string $slug): ?Product
    {
        foreach ($this->products('all') as $p) {
            if ($p->slug === $slug) {
                return $p;
            }
        }

        return null;
    }

    public function map(array $row, array $collections = []): Product
    {
        $merchant = $this->merchant()[$row['id']] ?? ['variants' => [], 'images' => [], 'description' => ''];
        $name = (string) ($row['title'] ?? '');

        $variants = array_map(function (array $v) use ($merchant, $name) {
            $m = $merchant['variants'][$v['id']] ?? [];
            [$color, $size] = $this->split((string) ($v['title'] ?? ''));

            return new Variant(
                id: (string) $v['id'],
                name: (string) ($v['title'] ?? ''),
                price: new Money((int) ($v['price']['cents'] ?? 0), (string) ($v['price']['currency_iso'] ?? 'USD')),
                sku: $m['sku'] ?? null,
                color: $m['color'] ?? $color,
                size: $m['size'] ?? $size,
                available: $m['available'] ?? true,
                images: isset($m['image']) ? [new Image($m['image'], alt: $name)] : [],
            );
        }, $row['variants'] ?? []);

        $prices = array_map(fn (Variant $v) => $v->price->minor, $variants);
        $currency = $variants[0]->price->currency ?? 'USD';

        // The card image first (it is the one the shop chose for the grid),
        // then one picture per colour from the merchant feed.
        $images = [];
        if (! empty($row['image'])) {
            $images[$row['image']] = new Image($row['image'], alt: $name);
        }
        foreach ($merchant['images'] as $url) {
            $images[$url] ??= new Image($url, alt: $name);
        }

        return new Product(
            id: (string) $row['id'],
            slug: (string) $row['handle'],
            name: $name,
            description: (string) $merchant['description'],
            url: $this->base().($row['url'] ?? '/products/'.$row['handle']),
            price: $prices ? new Money(min($prices), $currency) : Money::fromDecimal((string) ($row['price'] ?? '0'), $currency),
            maxPrice: $prices ? new Money(max($prices), $currency) : null,
            compareAt: ! empty($row['compare_at_price']) ? Money::fromDecimal((string) $row['compare_at_price'], $currency) : null,
            available: (bool) ($row['available'] ?? true),
            type: Product::TYPE_PRODUCT,
            images: array_values($images),
            variants: $variants,
            collections: $collections,
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
        );
    }

    /**
     * "Black, XS" → ['Black', 'XS']; "One size" → [null, 'One size'].
     * Only a fallback — the merchant feed says which is which when it can.
     */
    private function split(string $title): array
    {
        $parts = array_map('trim', explode(',', $title));

        return count($parts) >= 2 ? [implode(', ', array_slice($parts, 0, -1)), end($parts)] : [null, $parts[0] ?: null];
    }

    private function page(string $collection, int $n): array
    {
        $path = '/collections/'.rawurlencode($collection).($n > 1 ? '/'.$n : '').'.json';
        $response = $this->http()->get($this->base().$path);

        if ($response->status() === 404 && $n > 1) {
            return [];
        }

        if (! $response->successful()) {
            throw FourthwallException::fromResponse($response, "GET {$path}");
        }

        return (array) $response->json();
    }

    /**
     * The Merchant Center feed, folded from one-item-per-variant into
     * one-entry-per-product. Read once per instance.
     */
    private function merchant(): array
    {
        if ($this->merchant !== null) {
            return $this->merchant;
        }

        $response = $this->http()->get($this->base().'/.well-known/merchant-center/rss.xml');

        // Without it the catalogue still works — names, prices, variants and
        // one picture each — so a missing feed degrades rather than fails.
        if (! $response->successful() || ! ($xml = @simplexml_load_string($response->body()))) {
            return $this->merchant = [];
        }

        $this->shopName = trim((string) $xml->channel->children('g', true)->title) ?: null;
        $out = [];

        foreach ($xml->channel->item as $item) {
            $g = $item->children('g', true);
            $group = (string) $g->item_group_id;
            $id = (string) $g->id;

            $out[$group] ??= ['description' => (string) $g->description, 'variants' => [], 'images' => []];
            $out[$group]['variants'][$id] = array_filter([
                'sku' => (string) $g->mpn ?: null,
                'color' => (string) $g->color ?: null,
                'size' => (string) $g->size ?: null,
                'image' => (string) $g->image_link ?: null,
                'available' => (string) $g->availability !== 'out of stock',
            ], fn ($v) => $v !== null);

            if ((string) $g->image_link !== '' && ! in_array((string) $g->image_link, $out[$group]['images'], true)) {
                $out[$group]['images'][] = (string) $g->image_link;
            }
        }

        return $this->merchant = $out;
    }

    private function base(): string
    {
        $url = rtrim($this->shopUrl, '/');

        return str_contains($url, '://') ? $url : 'https://'.$url;
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent('ombabush/laravel-fourthwall')
            ->timeout($this->timeout)
            ->retry(2, 250, throw: false);
    }
}
