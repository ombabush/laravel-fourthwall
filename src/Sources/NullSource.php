<?php

namespace Ombabush\Fourthwall\Sources;

use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Data\Shop;

/**
 * No shop. Never makes a request; every shelf renders empty.
 *
 * The default when nothing is configured, so installing the package on a
 * site that has not connected a shop yet breaks nothing.
 */
class NullSource implements Source
{
    public function name(): string
    {
        return 'null';
    }

    public function shop(): ?Shop
    {
        return null;
    }

    public function collections(): array
    {
        return [];
    }

    public function products(string $collection): array
    {
        return [];
    }

    public function product(string $slug): ?Product
    {
        return null;
    }
}
