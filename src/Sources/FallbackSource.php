<?php

namespace Ombabush\Fourthwall\Sources;

use Illuminate\Support\Facades\Log;
use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Data\Shop;
use Throwable;

/**
 * The Storefront API, falling back to the shop's public feeds.
 *
 * A token that has been revoked, mistyped or pasted into the wrong variable
 * answers 401, and a shelf that trusted it would go empty. The feeds need no
 * token and carry most of the same catalogue, so a failure of the first is
 * logged and the second answers instead.
 */
class FallbackSource implements Source
{
    public function __construct(
        private readonly Source $primary,
        private readonly Source $secondary,
    ) {}

    public function name(): string
    {
        return $this->primary->name();
    }

    public function shop(): ?Shop
    {
        return $this->try(fn (Source $s) => $s->shop(), 'shop');
    }

    public function collections(): array
    {
        return $this->try(fn (Source $s) => $s->collections(), 'collections');
    }

    public function products(string $collection): array
    {
        return $this->try(fn (Source $s) => $s->products($collection), "collection {$collection}");
    }

    public function product(string $slug): ?Product
    {
        return $this->try(fn (Source $s) => $s->product($slug), "product {$slug}");
    }

    private function try(callable $read, string $what): mixed
    {
        try {
            return $read($this->primary);
        } catch (Throwable $e) {
            Log::warning("[fourthwall] {$this->primary->name()} failed for {$what} ({$e->getMessage()}) — reading the public feeds instead");

            return $read($this->secondary);
        }
    }
}
