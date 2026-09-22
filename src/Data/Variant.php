<?php

namespace Ombabush\Fourthwall\Data;

use JsonSerializable;

/**
 * One buyable thing: a colour and a size of a product.
 *
 * The id is what checkout takes — `…/cart/checkout?products={id}:1` — and is
 * never the product's id.
 */
final class Variant implements JsonSerializable
{
    /** @param  Image[]  $images */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Money $price,
        public readonly ?Money $compareAt = null,
        public readonly ?string $sku = null,
        public readonly ?string $color = null,
        public readonly ?string $swatch = null,
        public readonly ?string $size = null,
        public readonly bool $available = true,
        public readonly ?int $stock = null,
        public readonly array $images = [],
    ) {}

    public function onSale(): bool
    {
        return $this->compareAt !== null && $this->compareAt->compare($this->price) > 0;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price->toArray(),
            'compareAt' => $this->compareAt?->toArray(),
            'sku' => $this->sku,
            'color' => $this->color,
            'swatch' => $this->swatch,
            'size' => $this->size,
            'available' => $this->available,
            'stock' => $this->stock,
            'images' => array_map(fn (Image $i) => $i->toArray(), $this->images),
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) $a['id'],
            name: (string) ($a['name'] ?? ''),
            price: Money::fromArray($a['price']),
            compareAt: Money::fromArray($a['compareAt'] ?? null),
            sku: $a['sku'] ?? null,
            color: $a['color'] ?? null,
            swatch: $a['swatch'] ?? null,
            size: $a['size'] ?? null,
            available: (bool) ($a['available'] ?? true),
            stock: $a['stock'] ?? null,
            images: array_map(fn ($i) => Image::fromArray($i), $a['images'] ?? []),
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
