<?php

namespace Ombabush\Fourthwall;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Ombabush\Fourthwall\Data\Money;
use Ombabush\Fourthwall\Data\Product;

/**
 * A selection of products, built fluently and run in memory.
 *
 *   Fourthwall::products('archive')->available()->color('Black')->sortBy('price')->take(4)->get();
 *
 * In memory, because none of Fourthwall's endpoints filters: they page through
 * a collection and that is all. A shop's catalogue is tens of products, not
 * millions, and it is already cached — so filtering it here costs less than
 * one request would.
 */
class ProductQuery
{
    /** @var array<int, Closure(Product): bool> */
    private array $filters = [];

    private ?string $sort = null;

    private ?int $limit = null;

    private int $offset = 0;

    private bool $shuffle = false;

    /**
     * @param  Closure(string): Product[]  $resolve  collection handle → products
     * @param  string[]  $collections
     */
    public function __construct(
        private readonly Closure $resolve,
        private array $collections = ['all'],
    ) {}

    /** Products from these collections, merged, each product once. */
    public function in(string ...$collections): static
    {
        $this->collections = $collections ?: ['all'];

        return $this;
    }

    public function available(bool $available = true): static
    {
        return $this->where(fn (Product $p) => $p->available === $available);
    }

    /** Any of these colours, compared without regard to case. */
    public function color(string ...$colors): static
    {
        $want = array_map('mb_strtolower', array_filter($colors));

        return $want ? $this->where(fn (Product $p) => (bool) array_intersect($want, array_map('mb_strtolower', $p->colors()))) : $this;
    }

    public function size(string ...$sizes): static
    {
        $want = array_map('mb_strtolower', array_filter($sizes));

        return $want ? $this->where(fn (Product $p) => (bool) array_intersect($want, array_map('mb_strtolower', $p->sizes()))) : $this;
    }

    /**
     * By the lowest price a product can be had for. Bounds are decimals in the
     * catalogue's currency — `priceBetween(10, 20)` — or Money.
     */
    public function priceBetween(int|float|string|Money|null $min = null, int|float|string|Money|null $max = null): static
    {
        $toMinor = fn ($v, Product $p) => $v instanceof Money ? $v->minor : Money::fromDecimal((string) $v, $p->price->currency)->minor;

        return $this->where(fn (Product $p) => ($min === null || $p->price->minor >= $toMinor($min, $p))
            && ($max === null || $p->price->minor <= $toMinor($max, $p)));
    }

    public function onSale(): static
    {
        return $this->where(fn (Product $p) => $p->onSale());
    }

    public function bundles(bool $bundles = true): static
    {
        return $this->where(fn (Product $p) => $p->isBundle() === $bundles);
    }

    /** Words in the name or the description, all of them, any order. */
    public function search(?string $q): static
    {
        $words = array_filter(preg_split('/\s+/u', mb_strtolower(trim((string) $q))));

        return $words ? $this->where(function (Product $p) use ($words) {
            $hay = mb_strtolower($p->name.' '.strip_tags($p->description));

            foreach ($words as $w) {
                if (! str_contains($hay, $w)) {
                    return false;
                }
            }

            return true;
        }) : $this;
    }

    public function only(string ...$slugs): static
    {
        return $this->where(fn (Product $p) => in_array($p->slug, $slugs, true));
    }

    public function except(string ...$slugs): static
    {
        return $this->where(fn (Product $p) => ! in_array($p->slug, $slugs, true));
    }

    /** Your own condition. */
    public function where(Closure $filter): static
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * `featured` (the shop's own order — the default), `price`, `-price`,
     * `name`, `newest`, `oldest`, `updated`.
     */
    public function sortBy(?string $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /** A different selection on every render — for a banner or «you may also like». */
    public function inRandomOrder(): static
    {
        $this->shuffle = true;

        return $this;
    }

    public function take(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function skip(int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Filters from a query string, so a filtered list is a URL someone can
     * send: ?q=mosquito&color=Black&size=XL&min=10&max=20&sort=price&available=1
     */
    public function fromRequest(Request $request): static
    {
        $list = fn ($v) => array_filter(is_array($v) ? $v : explode(',', (string) $v));

        $this->search($request->query('q'));
        $this->color(...$list($request->query('color')));
        $this->size(...$list($request->query('size')));

        if ($request->filled('min') || $request->filled('max')) {
            $this->priceBetween($request->query('min') ?: null, $request->query('max') ?: null);
        }

        if ($request->boolean('available')) {
            $this->available();
        }

        if ($request->filled('sort')) {
            $this->sortBy((string) $request->query('sort'));
        }

        return $this;
    }

    /** @return Collection<int, Product> */
    public function get(): Collection
    {
        $list = $this->filtered();

        if ($this->offset || $this->limit !== null) {
            $list = $list->slice($this->offset, $this->limit)->values();
        }

        return $list;
    }

    public function first(): ?Product
    {
        return $this->filtered()->first();
    }

    public function count(): int
    {
        return $this->filtered()->count();
    }

    public function paginate(int $perPage = 12, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $page ??= LengthAwarePaginator::resolveCurrentPage($pageName);
        $all = $this->filtered();

        return new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(), $all->count(), $perPage, $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }

    /**
     * What a filter bar can offer: every colour and size, the price range and
     * the collections, counted over the selection BEFORE any filter — so a
     * choice never disappears from the bar because it is currently chosen.
     *
     * @return array{colors: array<string,int>, sizes: array<string,int>, min: ?Money, max: ?Money, total: int}
     */
    public function facets(): array
    {
        $all = $this->base();
        $count = fn (string $method) => $all->flatMap(fn (Product $p) => $p->{$method}())->countBy()->all();

        return [
            'colors' => $count('colors'),
            'sizes' => $this->orderSizes($count('sizes')),
            'min' => $all->map(fn (Product $p) => $p->price)->sortBy('minor')->first(),
            'max' => $all->map(fn (Product $p) => $p->maxPrice ?? $p->price)->sortByDesc('minor')->first(),
            'total' => $all->count(),
        ];
    }

    private function filtered(): Collection
    {
        $list = $this->base()->filter(function (Product $p) {
            foreach ($this->filters as $f) {
                if (! $f($p)) {
                    return false;
                }
            }

            return true;
        });

        if ($this->shuffle) {
            return $list->shuffle()->values();
        }

        return match ($this->sort) {
            'price' => $list->sortBy(fn (Product $p) => $p->price->minor)->values(),
            '-price' => $list->sortByDesc(fn (Product $p) => $p->price->minor)->values(),
            'name' => $list->sortBy(fn (Product $p) => mb_strtolower($p->name))->values(),
            'newest' => $list->sortByDesc(fn (Product $p) => (string) $p->createdAt)->values(),
            'oldest' => $list->sortBy(fn (Product $p) => (string) $p->createdAt)->values(),
            'updated' => $list->sortByDesc(fn (Product $p) => (string) $p->updatedAt)->values(),
            default => $list->values(),
        };
    }

    /** Every product in the chosen collections, once, remembering where it was found. */
    private function base(): Collection
    {
        $byId = [];

        foreach ($this->collections as $handle) {
            foreach (($this->resolve)($handle) as $p) {
                $byId[$p->id] = isset($byId[$p->id]) ? $byId[$p->id]->withCollections($p->collections) : $p;
            }
        }

        return collect(array_values($byId));
    }

    /** XS S M L XL 2XL … in wearing order, not alphabetical. */
    private function orderSizes(array $sizes): array
    {
        $rank = array_flip(['XXS', 'XS', 'S', 'M', 'L', 'XL', '2XL', 'XXL', '3XL', 'XXXL', '4XL', '5XL', '6XL']);

        uksort($sizes, fn ($a, $b) => ($rank[strtoupper($a)] ?? 100) <=> ($rank[strtoupper($b)] ?? 100) ?: strnatcasecmp($a, $b));

        return $sizes;
    }
}
