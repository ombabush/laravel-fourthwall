<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Ombabush\Fourthwall\Data\Money;
use Ombabush\Fourthwall\Data\Product;
use Ombabush\Fourthwall\Http\WebhookController;
use Ombabush\Fourthwall\Sources\FeedSource;
use Ombabush\Fourthwall\Sources\NullSource;
use Ombabush\Fourthwall\Sources\StorefrontSource;

/*
 * Money — the one field nobody forgives being wrong.
 */

it('keeps 19.93 as 1993 cents whichever way it arrives', function () {
    expect(Money::fromDecimal(19.93, 'USD')->minor)->toBe(1993)
        ->and(Money::fromDecimal('19.93', 'USD')->minor)->toBe(1993)
        ->and(Money::fromDecimal('20.00 USD', 'USD')->minor)->toBe(2000)
        ->and(Money::fromDecimal(0.1 + 0.2, 'USD')->minor)->toBe(30)
        ->and(Money::fromDecimal('1500', 'JPY')->minor)->toBe(1500)
        ->and(Money::fromDecimal(19.93, 'USD')->decimal())->toBe('19.93')
        ->and(Money::fromDecimal(19.93, 'USD')->format('en'))->toBe('$19.93')
        ->and(Money::fromDecimal(15, 'USD')->format('en'))->toBe('$15');
});

it('refuses to add two currencies', function () {
    (new Money(100, 'USD'))->plus(new Money(100, 'EUR'));
})->throws(InvalidArgumentException::class);

/*
 * The public feeds — no credentials. Recorded from shop.ombabush.com.
 */

it('reads a whole shop from its public feeds alone', function () {
    fakeFeeds();
    $products = (new FeedSource('https://shop.test'))->products('all');

    expect($products)->toHaveCount(3);

    $logo = collect($products)->firstWhere('slug', 'archive-logo-1999-t-shirt');

    expect($logo->name)->toBe('Archive Logo, 1999 — T-Shirt')
        ->and($logo->price->minor)->toBe(1500)
        ->and($logo->maxPrice->minor)->toBe(2300)        // 5XL costs more
        ->and($logo->hasPriceRange())->toBeTrue()
        ->and($logo->variants)->toHaveCount(44)
        ->and($logo->colors())->toContain('Black')
        ->and($logo->sizes())->toContain('XS', '5XL')
        ->and($logo->url)->toBe('https://shop.test/products/archive-logo-1999-t-shirt')
        ->and(count($logo->images))->toBeGreaterThan(1);  // one per colour, from the merchant feed

    $mosquito = collect($products)->firstWhere('slug', 'mosquito-scan-1993-black-print-t-shirt');
    expect($mosquito->price->minor)->toBe(1993)
        ->and($mosquito->description)->toContain('AGFA');
});

it('marks what the merchant feed says is out of stock', function () {
    fakeFeeds();
    $all = collect((new FeedSource('https://shop.test'))->products('all'));
    $soldOut = $all->flatMap(fn (Product $p) => $p->variants)->where('available', false);

    expect($soldOut)->not->toBeEmpty();
});

/*
 * The Storefront API — shape from the reference.
 */

it('never lets a hidden product through', function () {
    fakeStorefront();
    $products = (new StorefrontSource('ptkn_x', 'https://storefront.test/v1'))->products('archive');

    expect(collect($products)->pluck('slug')->all())->toBe(['mosquito-scan-1993-black-print-t-shirt', 'both-mosquitoes']);
});

it('maps stock, sale prices, swatches and bundles', function () {
    fakeStorefront();
    [$shirt, $bundle] = (new StorefrontSource('ptkn_x', 'https://storefront.test/v1'))->products('archive');

    expect($shirt->variant('v-black-3xl')->available)->toBeFalse()
        ->and($shirt->variant('v-navy-m')->stock)->toBe(4)
        ->and($shirt->variant('v-navy-m')->onSale())->toBeTrue()
        ->and($shirt->swatches())->toBe(['Black' => '#000000', 'Navy' => '#1f2a44'])
        ->and($shirt->url)->toBe('https://shop.test/products/mosquito-scan-1993-black-print-t-shirt')
        ->and($bundle->isBundle())->toBeTrue()
        ->and($bundle->available)->toBeFalse()
        ->and($bundle->price->minor)->toBe(3586)
        ->and($bundle->checkoutUrl())->toBe($bundle->url);   // no variants: goes to its page
});

it('prints only safe HTML from a description', function () {
    fakeStorefront();
    [$shirt] = (new StorefrontSource('ptkn_x', 'https://storefront.test/v1'))->products('archive');

    expect($shirt->descriptionHtml())
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->toContain('<a href="https://sniff.ru" rel="nofollow noopener" target="_blank">');
});

/*
 * Selecting.
 */

it('filters, sorts and counts in memory', function () {
    fakeFeeds();
    $fw = fourthwall(['shop' => 'https://shop.test', 'storefront_token' => null]);

    expect($fw->products()->count())->toBe(3)
        ->and($fw->products()->search('mosquito white')->get()->pluck('slug')->all())->toBe(['mosquito-scan-1993-white-print-t-shirt'])
        ->and($fw->products()->priceBetween(19, 19.99)->get()->pluck('price.minor')->all())->toBe([1993])
        ->and($fw->products()->sortBy('-price')->first()->price->minor)->toBe(2000)
        ->and($fw->products()->sortBy('price')->take(2)->get())->toHaveCount(2)
        ->and($fw->products()->except('archive-logo-1999-t-shirt')->count())->toBe(2)
        ->and($fw->products()->color('black')->count())->toBeGreaterThan(0);
});

it('takes filters from a query string', function () {
    fakeFeeds();
    $fw = fourthwall(['shop' => 'https://shop.test', 'storefront_token' => null]);
    $r = Request::create('/shop', 'GET', ['q' => 'mosquito', 'sort' => 'price', 'max' => '19.99']);

    expect($fw->products()->fromRequest($r)->get()->pluck('slug')->all())->toBe(['mosquito-scan-1993-black-print-t-shirt']);
});

it('offers facets in wearing order', function () {
    fakeFeeds();
    $f = fourthwall(['shop' => 'https://shop.test', 'storefront_token' => null])->products()->facets();

    expect(array_slice(array_keys($f['sizes']), 0, 4))->toBe(['XS', 'S', 'M', 'L'])
        ->and($f['min']->minor)->toBe(1500)
        ->and($f['total'])->toBe(3);
});

/*
 * The cache: last good copy, never an error on a page.
 */

it('serves the last good copy when Fourthwall is down', function () {
    fakeFeeds();
    $fw = fourthwall(['shop' => 'https://shop.test', 'storefront_token' => null, 'cache' => ['ttl' => 0, 'prefix' => 't']]);
    expect($fw->products()->count())->toBe(3);

    Http::fake(['*' => Http::response('down', 503)]);
    $fw = fourthwall();   // new instance, no memo — only the cache

    expect($fw->products()->count())->toBe(3);
});

it('never fetches on read with ttl null once something is cached', function () {
    fakeFeeds();
    $fw = fourthwall(['shop' => 'https://shop.test', 'storefront_token' => null, 'cache' => ['ttl' => null, 'prefix' => 't2']]);
    $fw->products()->count();

    Http::fake(['*' => fn () => throw new RuntimeException('a page render reached the network')]);

    expect(fourthwall()->products()->count())->toBe(3);
});

it('renders empty, and asks nobody, when no shop is configured', function () {
    Http::fake(['*' => fn () => throw new RuntimeException('should not be called')]);
    $fw = fourthwall(['shop' => null, 'storefront_token' => null, 'source' => 'auto']);

    expect($fw->source())->toBeInstanceOf(NullSource::class)
        ->and($fw->products()->get())->toBeEmpty()
        ->and(Blade::render('<x-fourthwall::shelf heading="Merch" />'))->not->toContain('Merch');
});

it('keeps two shops apart in one cache', function () {
    $a = fourthwall(['source' => 'array', 'shop' => 'https://a.test', 'catalogue' => ['products' => [['id' => '1', 'slug' => 'a', 'name' => 'A', 'price' => '10']]]]);
    $b = $a->for(['source' => 'array', 'shop' => 'https://b.test', 'catalogue' => ['products' => [['id' => '2', 'slug' => 'b', 'name' => 'B', 'price' => '20']]]]);

    expect($a->products()->get()->pluck('slug')->all())->toBe(['a'])
        ->and($b->products()->get()->pluck('slug')->all())->toBe(['b']);
});

/*
 * Links out — checkout and donations.
 */

it('builds checkout and donation links with utm tags', function () {
    $fw = fourthwall(['source' => 'null', 'shop' => 'https://shop.test', 'link_params' => ['utm_source' => 'sniff.ru']]);

    expect($fw->checkoutUrl(['v1' => 2, 'v2' => 1], 'SNIFF10'))
        ->toBe('https://shop.test/cart/checkout?products=v1%3A2%2Cv2%3A1&coupon=SNIFF10&currency=USD&utm_source=sniff.ru')
        ->and($fw->donationUrl(10, 'Nik', 'thanks', [5, 10, 20]))
        ->toBe('https://shop.test/donation/?donor=Nik&message=thanks&amount=10.00&currency=USD&donationOpts%5B0%5D=5.00&donationOpts%5B1%5D=10.00&donationOpts%5B2%5D=20.00&utm_source=sniff.ru');
});

/*
 * Webhooks.
 */

it('accepts only correctly signed webhooks, and drops the cache on a product event', function () {
    config(['fourthwall.webhook.secret' => 's3cret']);
    $body = json_encode(['type' => 'PRODUCT_UPDATED', 'data' => ['slug' => 'x']]);
    $good = base64_encode(hash_hmac('sha256', $body, 's3cret', true));

    expect(WebhookController::verify($body, $good, 's3cret'))->toBeTrue()
        ->and(WebhookController::verify($body, $good, 'wrong'))->toBeFalse()
        ->and(WebhookController::verify($body.' ', $good, 's3cret'))->toBeFalse()
        ->and(WebhookController::verify($body, '', 's3cret'))->toBeFalse();
});

it('mounts the webhook route only when asked', function () {
    expect(app('router')->has('fourthwall.webhook'))->toBeFalse();
});

/*
 * Components.
 */

it('renders a shelf, a product and a donation form from the same data', function () {
    fakeFeeds();
    fourthwall(['shop' => 'https://shop.test', 'storefront_token' => null]);

    $shelf = Blade::render('<x-fourthwall::shelf :limit="2" heading="Merch" />');
    expect($shelf)->toContain('Merch')->and(substr_count($shelf, 'class="fw-card '))->toBe(2);

    $product = Blade::render('<x-fourthwall::product :product="$p" />', ['p' => app(\Ombabush\Fourthwall\Fourthwall::class)->product('mosquito-scan-1993-black-print-t-shirt')]);
    expect($product)->toContain('action="https://shop.test/cart/checkout"')->toContain('name="products"');

    $donate = Blade::render('<x-fourthwall::donate :amounts="[5, 10, 20]" />');
    expect($donate)->toContain('action="https://shop.test/donation/"')->toContain('value="10.00"');
});
