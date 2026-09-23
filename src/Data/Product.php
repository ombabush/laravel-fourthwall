<?php

namespace Ombabush\Fourthwall\Data;

use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * A product as a page sees it — plain values, never a provider payload.
 *
 * Every source (Storefront API, public feeds, an array in config) maps onto
 * this one shape, so a template written against it does not care which one
 * the site is using today.
 */
final class Product implements JsonSerializable
{
    public const TYPE_PRODUCT = 'PRODUCT';

    public const TYPE_BUNDLE = 'BUNDLE';

    /**
     * @param  Image[]  $images
     * @param  Variant[]  $variants
     * @param  string[]  $collections  handles of the collections it was found in
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $url,
        public readonly Money $price,
        public readonly ?Money $maxPrice = null,
        public readonly ?Money $compareAt = null,
        public readonly bool $available = true,
        public readonly string $type = self::TYPE_PRODUCT,
        public readonly array $images = [],
        public readonly array $variants = [],
        public readonly array $collections = [],
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    public function image(): ?Image
    {
        return $this->images[0] ?? null;
    }

    public function variants(): Collection
    {
        return collect($this->variants);
    }

    public function variant(string $id): ?Variant
    {
        return $this->variants()->firstWhere('id', $id);
    }

    /** The first variant that can be bought — what a one-click «buy» sends. */
    public function defaultVariant(): ?Variant
    {
        return $this->variants()->firstWhere('available', true) ?? $this->variants[0] ?? null;
    }

    /** @return string[] in the order the shop lists them */
    public function colors(): array
    {
        return $this->variants()->pluck('color')->filter()->unique()->values()->all();
    }

    /** @return array<string,string> colour name => swatch, where there is one */
    public function swatches(): array
    {
        return $this->variants()->filter(fn (Variant $v) => $v->color)
            ->mapWithKeys(fn (Variant $v) => [$v->color => $v->swatch])->all();
    }

    /** @return string[] */
    public function sizes(): array
    {
        return $this->variants()->pluck('size')->filter()->unique()->values()->all();
    }

    /** Does the price depend on what you pick? 2XL often costs more. */
    public function hasPriceRange(): bool
    {
        return $this->maxPrice !== null && $this->maxPrice->compare($this->price) > 0;
    }

    public function onSale(): bool
    {
        return $this->compareAt !== null && $this->compareAt->compare($this->price) > 0;
    }

    public function isBundle(): bool
    {
        return $this->type === self::TYPE_BUNDLE;
    }

    public function in(string $collection): bool
    {
        return in_array($collection, $this->collections, true);
    }

    /**
     * The description as HTML that is safe to print.
     *
     * It is written in the shop's admin, which on a site showing somebody
     * else's shop is somebody else's HTML. Only text-level tags survive, with
     * no attributes except an http(s) or mailto href.
     */
    public function descriptionHtml(): string
    {
        $html = strip_tags($this->description, '<p><br><b><strong><i><em><u><ul><ol><li><a><h3><h4><blockquote>');

        return preg_replace_callback('/<(\w+)(\s[^>]*)?>/', function ($m) {
            $tag = strtolower($m[1]);

            if ($tag === 'a' && preg_match('/\shref\s*=\s*("|\')((?:https?:|mailto:)[^"\']*)\1/i', $m[2] ?? '', $h)) {
                return '<a href="'.htmlspecialchars($h[2], ENT_QUOTES).'" rel="nofollow noopener" target="_blank">';
            }

            return "<{$tag}>";
        }, $html);
    }

    /** Plain text, for a card or a meta description. The shop sends HTML. */
    public function excerpt(int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($this->description), ENT_QUOTES | ENT_HTML5)));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }

    /**
     * Straight into Fourthwall's checkout with this product in the basket.
     *
     * `/cart/checkout?products=variantId:qty` needs no cart, no API call and
     * no JavaScript — it is a link. Bundles have no variants of their own and
     * go to their product page instead.
     */
    public function checkoutUrl(?string $variantId = null, int $quantity = 1, array $params = []): string
    {
        $variant = $variantId ? $this->variant($variantId) : $this->defaultVariant();

        if (! $variant || $this->isBundle()) {
            return $this->url;
        }

        return $this->origin().'/cart/checkout?'.http_build_query(
            ['products' => $variant->id.':'.max(1, $quantity)] + $params
        );
    }

    /** scheme://host of the shop this product lives in. */
    public function origin(): string
    {
        $p = parse_url($this->url);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
    }

    /** The same product under another name and description — see Fourthwall::localize(). */
    public function renamed(?string $name, ?string $description): self
    {
        $a = $this->toArray();
        $a['name'] = $name ?? $this->name;
        $a['description'] = $description ?? $this->description;

        return self::fromArray($a);
    }

    public function withCollections(array $collections): self
    {
        $a = $this->toArray();
        $a['collections'] = array_values(array_unique(array_merge($this->collections, $collections)));

        return self::fromArray($a);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'price' => $this->price->toArray(),
            'maxPrice' => $this->maxPrice?->toArray(),
            'compareAt' => $this->compareAt?->toArray(),
            'available' => $this->available,
            'type' => $this->type,
            'images' => array_map(fn (Image $i) => $i->toArray(), $this->images),
            'variants' => array_map(fn (Variant $v) => $v->toArray(), $this->variants),
            'collections' => $this->collections,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) $a['id'],
            slug: (string) $a['slug'],
            name: (string) $a['name'],
            description: (string) ($a['description'] ?? ''),
            url: (string) ($a['url'] ?? ''),
            price: Money::fromArray($a['price']),
            maxPrice: Money::fromArray($a['maxPrice'] ?? null),
            compareAt: Money::fromArray($a['compareAt'] ?? null),
            available: (bool) ($a['available'] ?? true),
            type: (string) ($a['type'] ?? self::TYPE_PRODUCT),
            images: array_map(fn ($i) => Image::fromArray($i), $a['images'] ?? []),
            variants: array_map(fn ($v) => Variant::fromArray($v), $a['variants'] ?? []),
            collections: array_values($a['collections'] ?? []),
            createdAt: $a['createdAt'] ?? null,
            updatedAt: $a['updatedAt'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray() + [
            'priceFormatted' => $this->price->format(),
            'checkoutUrl' => $this->checkoutUrl(),
        ];
    }
}
