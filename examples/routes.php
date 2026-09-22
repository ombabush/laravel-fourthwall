<?php

/*
 * Examples — copy what you need into routes/web.php. Nothing here is loaded
 * by the package; it registers no routes of its own.
 *
 * .env for all of them:
 *
 *   FOURTHWALL_SHOP=https://shop.example.com
 *   FOURTHWALL_STOREFRONT_TOKEN=ptkn_…          # optional: adds collections, stock, bundles
 *   FOURTHWALL_UTM_SOURCE=example.com           # optional: orders say where they came from
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ombabush\Fourthwall\Facades\Fourthwall;

// 1. A shop page: chosen collections big, everything else smaller below —
//    each product once, in the first place it appears.
Route::get('/shop', function () {
    $featured = Fourthwall::products(['summer', 'archive'])->available()->get();

    return view('shop.index', [
        'featured' => $featured,
        'rest' => Fourthwall::products()->except(...$featured->pluck('slug'))->get(),
    ]);
})->name('shop');

// 2. A filtered, paginated catalogue: /catalogue?q=shirt&color=Black&sort=price
Route::get('/catalogue', function (Request $request) {
    $query = Fourthwall::products();

    return view('shop.catalogue', [
        'facets' => $query->facets(),                      // before filtering, for the bar
        'products' => $query->fromRequest($request)->paginate(12)->withQueryString(),
    ]);
});

// 3. One product on your own site. Set FOURTHWALL_PRODUCT_ROUTE=shop.product
//    and every card links here instead of to the shop.
Route::get('/shop/{slug}', function (string $slug) {
    $product = Fourthwall::product($slug) ?? abort(404);

    return view('shop.product', [
        'product' => $product,
        'related' => Fourthwall::products($product->collections)->except($slug)->available()->inRandomOrder()->take(4)->get(),
    ]);
})->name('shop.product');

// 4. Donations — a form here, the payment on Fourthwall.
Route::view('/donate', 'shop.donate');

// 5. A link that drops two things straight into checkout, with a coupon.
Route::get('/go/bundle', fn () => redirect()->away(
    Fourthwall::checkoutUrl(['variant-uuid-1' => 1, 'variant-uuid-2' => 1], coupon: 'FRIENDS', params: ['utm_campaign' => 'newsletter'])
));

// 6. Webhooks: set FOURTHWALL_WEBHOOK_PATH=webhooks/fourthwall and
//    FOURTHWALL_WEBHOOK_SECRET, then listen for what you care about:
//
//    Event::listen(function (\Ombabush\Fourthwall\Events\FourthwallWebhookReceived $e) {
//        if ($e->type === 'DONATION') {
//            Notification::route('mail', 'me@example.com')->notify(new ThankYou($e->data()));
//        }
//    });

// 7. Keep the catalogue fresh without a render ever waiting on Fourthwall:
//    FOURTHWALL_CACHE_TTL=never, and in routes/console.php
//
//    Schedule::command('fourthwall:refresh')->hourly();
