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
     * The same picture at another width — where the URL allows it.
     *
     * Fourthwall serves photographs through imgproxy, where the width is a
     * path segment (`…/rt:fill/w:422/…`) — but the FIRST segment is an HMAC
     * signature over the rest, so changing the width breaks the URL (imgproxy
     * answers 400). Only an unsigned imgproxy URL (`/insecure/` or `/_/`) is
     * rewritten; anything else comes back exactly as the shop sent it.
     */
    public function width(int $width): string
    {
        if (! preg_match('#^https?://[^/]*imgproxy[^/]*/(insecure|_)/#', $this->url)) {
            return $this->url;
        }

        return preg_replace('#/w:\d+/#', "/w:{$width}/", $this->url, 1);
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
