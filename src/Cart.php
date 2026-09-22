<?php

namespace Ombabush\Fourthwall;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Ombabush\Fourthwall\Data\Cart as CartData;
use Ombabush\Fourthwall\Exceptions\FourthwallException;

/**
 * A cart held on OUR site and handed to Fourthwall's checkout by id.
 *
 * This is the pattern of Fourthwall's own Next.js starter: the cart lives in
 * their Storefront API, only its id lives here — in the visitor's session —
 * and checkout is a redirect to `/cart/checkout?cartId=…`. Nothing about the
 * buyer is stored, and the shop's own cart (on its own domain) is never read:
 * its cookies are not ours, and pretending otherwise breaks in Safari.
 *
 * The item COUNT is kept in the session too, updated on every change, so a
 * cart icon in the site's header costs no request on any page. Only the cart
 * page itself asks Fourthwall what is in it.
 *
 * Metadata given when the cart is created — up to 10 keys — comes back on the
 * order as `metadata`, next to the UTM tags: which page, gallery or banner
 * sold it, exactly rather than guessed.
 */
class Cart
{
    private ?CartData $loaded = null;

    public function __construct(
        private readonly string $token,
        private readonly string $shopUrl,
        private readonly Session $session,
        private readonly string $endpoint = 'https://storefront-api.fourthwall.com/v1',
        private readonly string $currency = 'USD',
        private readonly array $metadata = [],
        private readonly int $timeout = 15,
    ) {}

    public function id(): ?string
    {
        return $this->session->get($this->key('id'));
    }

    /** The number of items, from the session — never a request. */
    public function count(): int
    {
        return (int) $this->session->get($this->key('count'), 0);
    }

    /** What is in it, asked of Fourthwall — once per request. */
    public function get(): CartData
    {
        if ($this->loaded) {
            return $this->loaded;
        }

        if (! $this->id()) {
            return $this->loaded = CartData::empty();
        }

        try {
            return $this->remember($this->request('get', '/carts/'.$this->id()));
        } catch (FourthwallException $e) {
            // Carts expire on Fourthwall's side, and a checked-out cart is
            // gone. Either way the id we hold points at nothing: start over.
            if (in_array($e->status, [400, 404, 410], true)) {
                $this->forget();

                return $this->loaded = CartData::empty();
            }

            throw $e;
        }
    }

    /**
     * Add a variant. The cart is created on first use, carrying the site's
     * metadata plus any given here.
     */
    public function add(string $variantId, int $quantity = 1, ?string $bundleId = null, array $metadata = []): CartData
    {
        $item = array_filter(['variantId' => $variantId, 'quantity' => max(1, min(1000, $quantity)), 'bundleId' => $bundleId]);

        if (! $this->id()) {
            $cart = $this->request('post', '/carts', [
                'items' => [$item],
                'metadata' => self::metadata($this->metadata + $metadata) ?: (object) [],
            ]);
            $this->session->put($this->key('id'), $cart['id']);

            return $this->remember($cart);
        }

        try {
            return $this->remember($this->request('post', '/carts/'.$this->id().'/add', ['items' => [$item]]));
        } catch (FourthwallException $e) {
            if (! in_array($e->status, [400, 404, 410], true)) {
                throw $e;
            }

            // The held cart has gone (expired, or already checked out) —
            // start a new one with this item rather than failing the click.
            $this->forget();

            return $this->add($variantId, $quantity, $bundleId, $metadata);
        }
    }

    /** Set a line to exactly this many. Zero removes it. */
    public function change(string $variantId, int $quantity): CartData
    {
        if ($quantity <= 0) {
            return $this->remove($variantId);
        }

        return $this->id()
            ? $this->remember($this->request('post', '/carts/'.$this->id().'/change', ['items' => [['variantId' => $variantId, 'quantity' => min(1000, $quantity)]]]))
            : CartData::empty();
    }

    public function remove(string $variantId): CartData
    {
        return $this->id()
            ? $this->remember($this->request('post', '/carts/'.$this->id().'/remove', ['items' => [['variantId' => $variantId, 'quantity' => 0]]]))
            : CartData::empty();
    }

    /** Let go of the cart here. Fourthwall expires it on its own. */
    public function forget(): void
    {
        $this->session->forget([$this->key('id'), $this->key('count')]);
        $this->loaded = null;
    }

    /**
     * Into Fourthwall's checkout with this cart. The cart id is forgotten
     * here — once it is checked out it is no longer ours to add to — and the
     * visitor gets a fresh one next time.
     */
    public function checkoutUrl(array $params = [], ?string $coupon = null): ?string
    {
        if (! $id = $this->id()) {
            return null;
        }

        $url = rtrim($this->shopUrl, '/').'/cart/checkout?'.http_build_query(array_filter([
            'cartId' => $id,
            'currency' => $this->currency,
            'coupon' => $coupon,
        ]) + $params);

        $this->forget();

        return $url;
    }

    /**
     * Fourthwall's limits, enforced before sending rather than discovered in a
     * 400: at most 10 keys, keys [A-Za-z0-9_], values ≤ 512 bytes, 2 KB total.
     */
    public static function metadata(array $metadata): array
    {
        $out = [];
        $size = 0;

        foreach ($metadata as $k => $v) {
            $k = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $k);
            $v = mb_strcut((string) (is_scalar($v) ? $v : json_encode($v)), 0, 512);

            if ($k === '' || $v === '' || count($out) >= 10 || $size + strlen($k) + strlen($v) > 2048) {
                continue;
            }

            $out[$k] = $v;
            $size += strlen($k) + strlen($v);
        }

        return $out;
    }

    private function remember(array $cart): CartData
    {
        $this->loaded = CartData::fromApi($cart, $this->shopUrl);
        $this->session->put($this->key('count'), $this->loaded->count());

        return $this->loaded;
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $url = $this->endpoint.$path.'?'.http_build_query(['storefront_token' => $this->token, 'currency' => $this->currency]);
        $response = $method === 'get' ? $this->http()->get($url) : $this->http()->post($url, $body);

        if (! $response->successful()) {
            throw FourthwallException::fromResponse($response, strtoupper($method).' '.preg_replace('#/carts/[^/]+#', '/carts/{id}', $path));
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->withUserAgent('ombabush/laravel-fourthwall')
            ->timeout($this->timeout);
    }

    /**
     * Per shop, so a site showing two shops holds two carts. Flat — no dots:
     * Laravel reads dots in a session key as nesting, and a flash message
     * under `fourthwall.cart` once overwrote the whole cart this way.
     */
    private function key(string $what): string
    {
        return 'fourthwall_cart_'.substr(md5($this->shopUrl), 0, 8).'_'.$what;
    }
}
