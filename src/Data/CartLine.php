<?php

namespace Ombabush\Fourthwall\Data;

use JsonSerializable;

final class CartLine implements JsonSerializable
{
    public function __construct(
        public readonly string $variantId,
        public readonly string $variantName,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly ?string $productSlug = null,
        public readonly ?string $productName = null,
        public readonly ?Image $image = null,
        public readonly ?string $url = null,
        public readonly ?string $bundleId = null,
    ) {}

    public function total(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }

    public static function fromApi(array $i, string $origin = ''): self
    {
        $v = $i['variant'] ?? [];
        $img = $v['images'][0] ?? null;
        $slug = $v['product']['slug'] ?? null;

        return new self(
            variantId: (string) ($v['id'] ?? ''),
            variantName: (string) ($v['name'] ?? $v['attributes']['description'] ?? ''),
            quantity: (int) ($i['quantity'] ?? 0),
            unitPrice: Money::fromDecimal($v['unitPrice']['value'] ?? 0, $v['unitPrice']['currency'] ?? 'USD'),
            productSlug: $slug,
            productName: $v['product']['name'] ?? null,
            image: $img ? new Image((string) ($img['transformedUrl'] ?? null ?: $img['url']), $img['width'] ?? null, $img['height'] ?? null, (string) ($v['product']['name'] ?? '')) : null,
            url: $slug && $origin ? rtrim($origin, '/').'/products/'.$slug : null,
            bundleId: $i['groupedBy']['bundleId'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'variantId' => $this->variantId,
            'variantName' => $this->variantName,
            'productName' => $this->productName,
            'productSlug' => $this->productSlug,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice,
            'total' => $this->total(),
            'image' => $this->image,
            'url' => $this->url,
        ];
    }
}
