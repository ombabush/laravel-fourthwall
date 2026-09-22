<?php

namespace Ombabush\Fourthwall\Sources;

use Ombabush\Fourthwall\Data\Money;
use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Data\Shop;
use Ombabush\Fourthwall\Data\ShopCollection;

/**
 * A catalogue written down — in config, in a seeder, in a test.
 *
 * Products take Product::toArray()'s shape, with one convenience for people
 * writing them by hand: a price may be a plain decimal ("19.93") plus a
 * `currency`, instead of ['minor' => 1993, 'currency' => 'USD'].
 */
class ArraySource implements Source
{
    public function __construct(private readonly array $catalogue = []) {}

    public function name(): string
    {
        return 'array';
    }

    public function shop(): ?Shop
    {
        return isset($this->catalogue['shop']) ? Shop::fromArray($this->catalogue['shop']) : null;
    }

    public function collections(): array
    {
        return array_map(fn ($c) => ShopCollection::fromArray($c), $this->catalogue['collections'] ?? []);
    }

    public function products(string $collection): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Product $p) => $collection === 'all' || $p->in($collection)
        ));
    }

    public function product(string $slug): ?Product
    {
        foreach ($this->all() as $p) {
            if ($p->slug === $slug) {
                return $p;
            }
        }

        return null;
    }

    /** @return Product[] */
    private function all(): array
    {
        return array_values(array_filter(array_map(function (array $a) {
            if (($a['access'] ?? 'PUBLIC') !== 'PUBLIC') {
                return null;
            }

            $currency = $a['currency'] ?? 'USD';

            foreach (['price', 'maxPrice', 'compareAt'] as $key) {
                if (isset($a[$key]) && ! is_array($a[$key])) {
                    $a[$key] = Money::fromDecimal((string) $a[$key], $currency)->toArray();
                }
            }

            foreach ($a['variants'] ?? [] as $i => $v) {
                foreach (['price', 'compareAt'] as $key) {
                    if (isset($v[$key]) && ! is_array($v[$key])) {
                        $a['variants'][$i][$key] = Money::fromDecimal((string) $v[$key], $currency)->toArray();
                    }
                }
            }

            $a['url'] ??= '';

            return Product::fromArray($a);
        }, $this->catalogue['products'] ?? [])));
    }
}
