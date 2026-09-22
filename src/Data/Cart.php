<?php

namespace Ombabush\Fourthwall\Data;

use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * A cart as Fourthwall holds it — read back from the Storefront API, never
 * computed here. Lines keep Fourthwall's own prices; the subtotal is theirs
 * summed in integer cents.
 */
final class Cart implements JsonSerializable
{
    /** @param  CartLine[]  $lines */
    public function __construct(
        public readonly ?string $id,
        public readonly array $lines = [],
        public readonly array $metadata = [],
    ) {}

    public static function empty(): self
    {
        return new self(null);
    }

    public function lines(): Collection
    {
        return collect($this->lines);
    }

    public function count(): int
    {
        return array_sum(array_map(fn (CartLine $l) => $l->quantity, $this->lines));
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function line(string $variantId): ?CartLine
    {
        return $this->lines()->firstWhere('variantId', $variantId);
    }

    public function subtotal(): ?Money
    {
        $total = null;

        foreach ($this->lines as $l) {
            $total = $total ? $total->plus($l->total()) : $l->total();
        }

        return $total;
    }

    public static function fromApi(array $a, string $origin = ''): self
    {
        return new self(
            id: $a['id'] ?? null,
            lines: array_map(fn (array $i) => CartLine::fromApi($i, $origin), $a['items'] ?? []),
            metadata: $a['metadata'] ?? [],
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'count' => $this->count(),
            'subtotal' => $this->subtotal(),
            'lines' => $this->lines,
        ];
    }
}
