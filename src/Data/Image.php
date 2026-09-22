<?php

namespace Ombabush\Fourthwall\Data;

use JsonSerializable;

final class Image implements JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $alt = '',
    ) {}

    /**
     * The same picture at another width.
     *
     * Fourthwall serves product photographs through imgproxy, and the width is
     * a path segment — `…/rt:fill/w:422/…` — so a 1200px original need not be
     * sent to a 180px card. URLs that are not imgproxy come back unchanged.
     */
    public function width(int $width): string
    {
        if (! str_contains($this->url, 'imgproxy.')) {
            return $this->url;
        }

        return preg_match('#/w:\d+/#', $this->url)
            ? preg_replace('#/w:\d+/#', "/w:{$width}/", $this->url, 1)
            : $this->url;
    }

    public function ratio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    public function toArray(): array
    {
        return ['url' => $this->url, 'width' => $this->width, 'height' => $this->height, 'alt' => $this->alt];
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['url'], $a['width'] ?? null, $a['height'] ?? null, (string) ($a['alt'] ?? ''));
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
