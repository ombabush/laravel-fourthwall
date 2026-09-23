<?php

return [
    /*
     * The shop's public address — `https://shop.example.com` or
     * `https://yourname.fourthwall.com`. This alone is enough: every Fourthwall
     * shop publishes its catalogue as JSON and as a Merchant Center feed, no
     * key needed, and every «buy» and «donate» link points here.
     */
    'shop' => env('FOURTHWALL_SHOP'),

    /*
     * Optional. A Storefront token (`ptkn_…`), from Settings → For developers.
     * It is PUBLIC BY DESIGN — Fourthwall's own storefront ships it to the
     * browser — and it adds what the feeds lack: every image, stock counts,
     * hidden-vs-public, bundles and the list of collections.
     */
    'storefront_token' => env('FOURTHWALL_STOREFRONT_TOKEN'),

    /*
     * Optional, and SERVER-SIDE ONLY. The Platform API user from Settings →
     * For developers → Open API. It sees orders, donations and customers'
     * e-mail addresses. Nothing in this package ever sends it to a browser;
     * it is used by `fourthwall:check` and by `Fourthwall::platform()`.
     */
    'api_username' => env('FOURTHWALL_API_USERNAME'),

    'api_password' => env('FOURTHWALL_API_PASSWORD'),

    /*
     * Where the catalogue comes from:
     *
     *   auto        storefront if a token is set, otherwise the public feeds
     *   storefront  the Storefront API (needs the token)
     *   feed        the shop's public JSON + Merchant Center feeds (needs `shop`)
     *   array       `catalogue` below — for a site without a shop, and for tests
     *   null        nothing, and no request is ever made
     *
     * With nothing configured at all, `auto` resolves to `null`: a site that
     * has not connected a shop renders empty shelves instead of erroring.
     */
    'source' => env('FOURTHWALL_SOURCE', 'auto'),

    'catalogue' => [
        // 'collections' => [['slug' => 'all', 'name' => 'All']],
        // 'products' => [[...Product::toArray() shape...]],
    ],

    /*
     * Prices come back in this currency. Fourthwall converts; seventeen are
     * on offer (USD EUR CAD GBP AUD NZD SEK NOK DKK PLN INR JPY MYR SGD MXN
     * BRL CHF). The public feeds only ever speak the shop's own currency.
     */
    'currency' => env('FOURTHWALL_CURRENCY', 'USD'),

    /*
     * The catalogue is cached, and the last good copy is kept FOREVER — if
     * Fourthwall is down, the shelf shows what it showed an hour ago rather
     * than nothing.
     *
     * `ttl` is how old a copy may get before a read refetches it. `null` means
     * never on read: only `fourthwall:refresh` (put it on the scheduler) or a
     * webhook replace it, so a page render never waits on Fourthwall.
     */
    'cache' => [
        'store' => env('FOURTHWALL_CACHE_STORE'),
        'ttl' => in_array($ttl = env('FOURTHWALL_CACHE_TTL', 3600), [null, '', 'never'], true) ? null : (int) $ttl,
        'prefix' => 'fourthwall',
    ],

    /*
     * Webhooks. Set the path to mount the receiver — say `webhooks/fourthwall`
     * — and the secret Fourthwall showed you when you created the webhook.
     * Every request is checked against X-Fourthwall-Hmac-SHA256 before
     * anything reads its body. Product and collection events refresh the
     * cache; every event is re-dispatched as FourthwallWebhookReceived.
     */
    'webhook' => [
        'path' => env('FOURTHWALL_WEBHOOK_PATH'),
        'secret' => env('FOURTHWALL_WEBHOOK_SECRET'),
    ],

    /*
     * Where a product card links. Unset, it goes to the product's page on the
     * shop. Set it to the name of one of YOUR routes that takes the slug —
     * `Route::get('/shop/{slug}', …)->name('shop.product')` — and cards stay
     * on your site until someone presses «buy».
     */
    'product_route' => env('FOURTHWALL_PRODUCT_ROUTE'),

    /*
     * Added to every link that leaves for the shop — product pages, checkout,
     * donations — so Fourthwall's analytics can tell which site sent the
     * order. `utm_*` are recorded against the order by Fourthwall's checkout.
     * Override per link with the component's `:params`.
     */
    'link_params' => array_filter([
        'utm_source' => env('FOURTHWALL_UTM_SOURCE'),
        'utm_medium' => env('FOURTHWALL_UTM_MEDIUM', 'website'),
    ]),

    /*
     * A cart on your own site — needs the storefront token. Set the path to
     * mount its endpoints (`shop/cart` → POST /shop/cart/add, …) and point
     * `page` at YOUR route that shows it (the package ships the component,
     * not the page). Unset, every «buy» is a straight link into checkout.
     *
     * `metadata` is attached to every cart and comes back on the order —
     * `['site' => 'sniff.ru']` — at most 10 keys, 512 bytes a value.
     */
    'cart' => [
        'path' => env('FOURTHWALL_CART_PATH'),
        'page' => env('FOURTHWALL_CART_PAGE'),
        'middleware' => ['web'],
        'metadata' => array_filter(['site' => env('FOURTHWALL_UTM_SOURCE')]),
    ],

    /*
     * Names and descriptions in other languages, by locale and product slug.
     * Fourthwall has no translations of its own; see Fourthwall::localize().
     */
    'translations' => [
        // 'ru' => ['some-slug' => ['name' => '…', 'description' => '…']],
    ],

    'endpoints' => [
        'storefront' => env('FOURTHWALL_STOREFRONT_ENDPOINT', 'https://storefront-api.fourthwall.com/v1'),
        'platform' => env('FOURTHWALL_PLATFORM_ENDPOINT', 'https://api.fourthwall.com/open-api/v1.0'),
    ],

    'timeout' => (int) env('FOURTHWALL_TIMEOUT', 15),

    /*
     * A ceiling on pagination, so a misbehaving endpoint cannot walk for ever.
     * Fifty products a page, so twenty pages is a thousand products.
     */
    'max_pages' => (int) env('FOURTHWALL_MAX_PAGES', 20),
];
