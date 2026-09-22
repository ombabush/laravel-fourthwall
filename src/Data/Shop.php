<?php

namespace Ombabush\Fourthwall\Data;

use JsonSerializable;

final class Shop implements JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $id = null,
        public readonly ?string $domain = null,
        public readonly ?string $status = null,
    ) {}

    /** Fourthwall's own donation page, pre-filled. See Fourthwall::donationUrl(). */
    public function donationUrl(): string
    {
        return rtrim($this->url, '/').'/donation/';
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'url' => $this->url, 'id' => $this->id, 'domain' => $this->domain, 'status' => $this->status];
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['name'], (string) $a['url'], $a['id'] ?? null, $a['domain'] ?? null, $a['status'] ?? null);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
