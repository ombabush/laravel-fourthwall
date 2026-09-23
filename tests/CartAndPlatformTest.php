<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Ombabush\Fourthwall\Capability;
use Ombabush\Fourthwall\Cart;
use Ombabush\Fourthwall\Fourthwall;

/*
 * Carts — the Storefront API holds the cart, our session holds its id.
 */

function cartPayload(string $id, array $items): array
{
    return ['id' => $id, 'items' => array_map(fn ($i) => [
        'quantity' => $i[1],
        'variant' => [
            'id' => $i[0], 'name' => 'Black, M',
            'unitPrice' => ['value' => 19.93, 'currency' => 'USD'],
            'images' => [['url' => 'https://img.test/a.webp', 'width' => 100, 'height' => 100]],
            'product' => ['id' => 'p1', 'slug' => 'mosquito', 'name' => 'Mosquito Scan'],
        ],
    ], $items), 'metadata' => []];
}

function withCart(array $extra = []): Fourthwall
{
    return fourthwall(['source' => 'array', 'shop' => 'https://shop.test', 'storefront_token' => 'ptkn_test',
        'endpoints' => ['storefront' => 'https://storefront.test/v1'], 'cart' => ['metadata' => ['site' => 'sniff.ru']]] + $extra);
}

it('creates the cart on first add, with our metadata, and keeps only its id and count', function () {
    Http::fake(['storefront.test/v1/carts?*' => Http::response(cartPayload('cart-1', [['v1', 2]]))]);
    $cart = withCart()->cart();

    $data = $cart->add('v1', 2, metadata: ['source_page' => '/gallery/kiev']);

    expect($cart->id())->toBe('cart-1')
        ->and($cart->count())->toBe(2)
        ->and($data->subtotal()->minor)->toBe(3986)
        ->and($data->lines[0]->url)->toBe('https://shop.test/products/mosquito');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'storefront_token=ptkn_test')
        && $r['items'][0] === ['variantId' => 'v1', 'quantity' => 2]
        && $r['metadata'] === ['site' => 'sniff.ru', 'source_page' => '/gallery/kiev']);
});

it('adds to the cart it already holds, and starts a new one when that has expired', function () {
    Http::fake([
        'storefront.test/v1/carts/cart-1/add*' => Http::response(['code' => 'CART_NOT_FOUND'], 404),
        'storefront.test/v1/carts?*' => Http::response(cartPayload('cart-2', [['v2', 1]])),
    ]);
    $cart = withCart()->cart();
    (fn () => $this->session->put($this->key('id'), 'cart-1'))->call($cart);

    $cart->add('v2');

    expect($cart->id())->toBe('cart-2')->and($cart->count())->toBe(1);
});

it('counts from the session — a header icon never makes a request', function () {
    Http::fake(['*' => fn () => throw new RuntimeException('the icon reached the network')]);
    $cart = withCart()->cart();
    (fn () => $this->session->put($this->key('count'), 3))->call($cart);

    expect($cart->count())->toBe(3)
        ->and(Blade::render('<x-fourthwall::cart-icon href="/cart" />'))->toContain('fw-cart-icon__count">3<');
});

it('hands the cart to checkout by id, then lets it go', function () {
    Http::fake(['storefront.test/v1/carts?*' => Http::response(cartPayload('cart-1', [['v1', 1]]))]);
    $cart = withCart(['link_params' => ['utm_source' => 'sniff.ru']])->cart();
    $cart->add('v1');

    expect($cart->checkoutUrl(['utm_source' => 'sniff.ru'], 'SNIFF15'))
        ->toBe('https://shop.test/cart/checkout?cartId=cart-1&currency=USD&coupon=SNIFF15&utm_source=sniff.ru')
        ->and($cart->id())->toBeNull()
        ->and($cart->count())->toBe(0);
});

it('keeps cart metadata inside Fourthwall’s limits', function () {
    $many = [];
    foreach (range(1, 15) as $i) {
        $many["key-$i"] = str_repeat('x', 600);
    }

    $m = Cart::metadata($many);

    expect(count($m))->toBeLessThanOrEqual(10)
        ->and(array_keys($m)[0])->toBe('key_1')
        ->and(strlen($m['key_1']))->toBe(512)
        ->and(array_sum(array_map(fn ($k, $v) => strlen($k) + strlen($v), array_keys($m), $m)))->toBeLessThanOrEqual(2048);
});

it('mounts the cart routes and adds by a plain form post', function () {
    Http::fake(['storefront.test/v1/carts?*' => Http::response(cartPayload('cart-1', [['v1', 1]]))]);
    withCart();

    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
        ->from('/shop')->post('/shop/cart/add', ['variant' => 'v1'])
        ->assertRedirect('/shop')
        ->assertSessionHas('fourthwall.cart.status', 'added');

    // The next request is the same visitor: carry the session over.
    $r = $this->withSession(app('session.store')->all())->get('/shop/cart/checkout')->assertStatus(303);

    expect($r->headers->get('Location'))->toStartWith('https://shop.test/cart/checkout?cartId=cart-1');
});

it('answers 404 on the cart routes without a storefront token', function () {
    fourthwall(['source' => 'array', 'shop' => 'https://shop.test', 'storefront_token' => null]);

    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
        ->post('/shop/cart/add', ['variant' => 'v1'])->assertNotFound();
});

it('has no cart without a storefront token, and «add to cart» becomes «buy»', function () {
    $fw = fourthwall(['source' => 'array', 'shop' => 'https://shop.test', 'storefront_token' => null,
        'catalogue' => ['products' => [['id' => '1', 'slug' => 'tee', 'name' => 'Tee', 'price' => '15', 'url' => 'https://shop.test/products/tee',
            'variants' => [['id' => 'v1', 'name' => 'M', 'price' => '15']]]]]]);

    expect($fw->cart())->toBeNull()
        ->and($fw->supports(Capability::Carts))->toBeFalse()
        ->and(Blade::render('<x-fourthwall::add-to-cart :product="$p" />', ['p' => $fw->product('tee')]))
        ->toContain('action="https://shop.test/cart/checkout"')->toContain('value="v1:1"');
});

/*
 * Capabilities.
 */

it('says what each set of credentials opens', function () {
    $feed = fourthwall(['source' => 'auto', 'shop' => 'https://shop.test', 'storefront_token' => null, 'api_username' => null, 'api_password' => null]);
    $all = fourthwall(['storefront_token' => 'ptkn_x', 'api_username' => 'u', 'api_password' => 'p', 'webhook' => ['path' => 'wh', 'secret' => 's']]);

    expect(array_map(fn ($c) => $c->value, $feed->capabilities()))->toBe(['catalogue', 'checkout_links', 'donations'])
        ->and(count($all->capabilities()))->toBe(count(Capability::cases()));
});

/*
 * The Platform API side: promotions and supporters.
 */

it('advertises only live, public promotions', function () {
    $rows = [
        ['type' => 'SHOP_SINGLE', 'id' => 'a', 'code' => 'SNIFF15', 'status' => 'Live', 'discount' => ['type' => 'PERCENTAGE', 'percentage' => 15]],
        ['type' => 'SHOP_SINGLE', 'id' => 'b', 'code' => 'OLD', 'status' => 'Ended', 'discount' => ['type' => 'PERCENTAGE', 'percentage' => 50]],
        ['type' => 'MEMBERSHIPS_SINGLE', 'id' => 'c', 'code' => 'MEMBERS', 'status' => 'Live', 'discount' => ['type' => 'PERCENTAGE', 'percentage' => 20]],
        ['type' => 'SHOP_AUTO_APPLYING', 'id' => 'd', 'title' => 'Autumn', 'status' => 'Live', 'discount' => ['type' => 'FLAT_RATE', 'money' => ['value' => 5, 'currency' => 'USD']]],
    ];

    $live = Fourthwall::livePromotions($rows);

    expect(array_column($live, 'id'))->toBe(['a', 'd'])
        ->and($live[1]['automatic'])->toBeTrue()
        ->and($live[1]['discount']['amount']['minor'])->toBe(500);
});

it('never lets a donor’s e-mail reach the wall', function () {
    Http::fake(['api.test/*' => Http::response(['results' => [
        ['id' => 'd1', 'status' => 'COMPLETED', 'email' => 'secret@example.com', 'username' => 'Nik', 'message' => 'за архив', 'amounts' => ['total' => ['value' => 10, 'currency' => 'USD']], 'createdAt' => '2026-09-23T10:00:00Z'],
        ['id' => 'd2', 'status' => 'ABANDONED', 'email' => 'other@example.com', 'username' => 'Ghost', 'amounts' => ['total' => ['value' => 99, 'currency' => 'USD']]],
    ]])]);

    $fw = fourthwall(['source' => 'null', 'shop' => 'https://shop.test', 'api_username' => 'u', 'api_password' => 'p',
        'endpoints' => ['platform' => 'https://api.test/open-api/v1.0'], 'cache' => ['ttl' => 3600, 'prefix' => 'sup']]);

    $rows = $fw->supporters();
    $html = Blade::render('<x-fourthwall::supporters />');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Nik')
        ->and($rows[0]['amount']->minor)->toBe(1000)
        ->and(json_encode($rows))->not->toContain('@')
        ->and($html)->toContain('Nik')->toContain('за архив')->not->toContain('secret@example.com')->not->toContain('Ghost');
});

it('renders the live promotion, and nothing once it has ended', function () {
    Http::fake(['api.test/open-api/v1.0/promotions*' => Http::sequence()
        ->push(['results' => [['type' => 'SHOP_SINGLE', 'id' => 'a', 'code' => 'SNIFF15', 'status' => 'Live', 'discount' => ['type' => 'PERCENTAGE', 'percentage' => 15]]]])
        ->push(['results' => []]),
    ]);
    $cfg = ['source' => 'null', 'shop' => 'https://shop.test', 'api_username' => 'u', 'api_password' => 'p',
        'endpoints' => ['platform' => 'https://api.test/open-api/v1.0'], 'cache' => ['ttl' => 0, 'prefix' => 'promo'], 'link_params' => []];

    fourthwall($cfg);
    expect(Blade::render('<x-fourthwall::promo />'))->toContain('−15%')->toContain('SNIFF15')->toContain('coupon=SNIFF15');

    fourthwall($cfg);
    expect(trim(Blade::render('<x-fourthwall::promo />')))->toBe('');
});

it('ignores, and never prints, a token that is not a storefront token', function () {
    fakeFeeds();
    $fw = fourthwall(['source' => 'auto', 'shop' => 'https://shop.test', 'storefront_token' => 'NotAToken-maybe-a-password']);

    expect($fw->source()->name())->toBe('feed')
        ->and($fw->supports(Capability::Carts))->toBeFalse()
        ->and($fw->products()->count())->toBe(3);

    $this->artisan('fourthwall:check', ['--shop' => 'https://shop.test', '--token' => 'NotAToken-maybe-a-password', '--no-interaction' => true])
        ->doesntExpectOutputToContain('NotAToken-maybe-a-password')
        ->expectsOutputToContain('NOT a storefront token')
        ->assertFailed();
});

it('falls back to the public feeds when the Storefront API refuses the token', function () {
    Http::fake([
        'storefront.test/*' => Http::response(['code' => 'NotAuthorizedError'], 401),
        'shop.test/collections/all.json' => Http::response(fixture('feed-collection-all.json')),
        'shop.test/collections/all/2.json' => Http::response(['products' => []]),
        'shop.test/.well-known/merchant-center/rss.xml' => Http::response(fixture('feed-merchant-center.xml')),
    ]);

    $fw = fourthwall(['source' => 'auto', 'shop' => 'https://shop.test', 'storefront_token' => 'ptkn_revoked',
        'endpoints' => ['storefront' => 'https://storefront.test/v1']]);

    expect($fw->products()->count())->toBe(3);
});
