<?php

namespace Ombabush\Fourthwall;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Ombabush\Fourthwall\Exceptions\FourthwallException;

/**
 * The Platform (Open) API — HTTP Basic with the shop's API user.
 *
 * SERVER-SIDE ONLY. These credentials read orders, donations and customers'
 * e-mail addresses. Return values are Fourthwall's own payloads, unmapped:
 * this is the admin side, used by `fourthwall:check` and by code that knows
 * what it is asking for — not by templates.
 */
class Platform
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $endpoint = 'https://api.fourthwall.com/open-api/v1.0',
        private readonly int $timeout = 15,
    ) {}

    public function configured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    /** id, name, domain, publicDomain, status (LIVE | COMING_SOON | PASSWORD_PROTECTED) */
    public function shop(): array
    {
        return $this->get('/shops/current');
    }

    /**
     * The Storefront token, created if the shop has none yet — so the API
     * user is enough to set up everything else.
     */
    public function storefrontToken(): string
    {
        $r = $this->http()->put($this->endpoint.'/public-token');

        if (! $r->successful()) {
            throw FourthwallException::fromResponse($r, 'PUT /public-token');
        }

        return (string) $r->json('token');
    }

    public function products(int $page = 0, int $size = 50): array
    {
        return $this->get('/products', compact('page', 'size'));
    }

    public function orders(int $page = 0, int $size = 20): array
    {
        return $this->get('/order', compact('page', 'size'));
    }

    public function donations(int $page = 0, int $size = 20): array
    {
        return $this->get('/donations', compact('page', 'size'));
    }

    public function promotions(int $page = 0, int $size = 50): array
    {
        return $this->get('/promotions', compact('page', 'size'));
    }

    public function webhooks(): array
    {
        return $this->get('/webhooks');
    }

    public function membershipTiers(): array
    {
        return $this->get('/memberships/tiers');
    }

    /** Anything else in the reference: `$platform->get('/shops/current/contact-info')`. */
    public function get(string $path, array $query = []): array
    {
        if (! $this->configured()) {
            throw FourthwallException::notConfigured('FOURTHWALL_API_USERNAME / FOURTHWALL_API_PASSWORD');
        }

        $r = $this->http()->get($this->endpoint.$path, $query);

        if (! $r->successful()) {
            throw FourthwallException::fromResponse($r, "GET {$path}");
        }

        return (array) $r->json();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withBasicAuth($this->username, $this->password)
            ->withUserAgent('ombabush/laravel-fourthwall')
            ->timeout($this->timeout);
    }
}
