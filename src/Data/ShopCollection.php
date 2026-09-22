<?php

namespace Ombabush\Fourthwall\Data;

use JsonSerializable;

/**
 * A Fourthwall collection. Named so it does not collide with
 * Illuminate\Support\Collection in every file that uses both.
 *
 * `slug` is the handle — the last segment of the collection's URL in the
 * Fourthwall admin — and is what every method here takes. Every shop has a
 * built-in `all`.
 */
final class ShopCollection implements JsonSerializable
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description = '',
        public readonly ?string $id = null,
    ) {}

    public function toArray(): array
    {
        return ['slug' => $this->slug, 'name' => $this->name, 'description' => $this->description, 'id' => $this->id];
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['slug'], (string) ($a['name'] ?? $a['slug']), (string) ($a['description'] ?? ''), $a['id'] ?? null);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
