<?php

namespace Ombabush\Fourthwall\Sources;

use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Data\Shop;
use Ombabush\Fourthwall\Data\ShopCollection;

/**
 * Where a catalogue comes from. Four reads, no writes.
 *
 * A source returns only what may be shown: a product whose access is not
 * PUBLIC never leaves it. That is a rule of the package, not something each
 * template has to remember.
 */
interface Source
{
    /** A short name for logs and for `fourthwall:check`. */
    public function name(): string;

    public function shop(): ?Shop;

    /** @return ShopCollection[] */
    public function collections(): array;

    /** @return Product[] in the shop's own order */
    public function products(string $collection): array;

    public function product(string $slug): ?Product;
}
