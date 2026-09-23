# laravel-fourthwall

Put a [Fourthwall](https://fourthwall.com) merch shop on a Laravel site:
shelves, filtered product lists, one-product pages, banners and a donation
form. They sit in your pages and your design, and hand the buyer over to
Fourthwall's own checkout.

```php
Fourthwall::products('archive')->available()->sortBy('price')->take(3)->get();
Fourthwall::product('mosquito-scan-1993-black-print-t-shirt');
Fourthwall::donationUrl(10);
```

```blade
<x-fourthwall::shelf collection="archive" :limit="3" />
<x-fourthwall::banner slug="mosquito-scan-1993-black-print-t-shirt" headline="The 1993 scan, on a shirt" />
<x-fourthwall::donate :amounts="[5, 10, 20]" />
```

## What it is not

**It never takes money.** There is no card, no address and no order table
here, and even the optional cart is Fourthwall's, held by id. Fourthwall does
checkout, tax, shipping, refunds and chargebacks for a living. What a content site is missing is not a shop, it is a *shelf*:
the products on its own pages, linking out.

It keeps **nothing in your database**. Fourthwall's official headless starter
([FourthwallHQ/vercel-commerce](https://github.com/FourthwallHQ/vercel-commerce))
fetches live, caches for an hour and purges on webhook. This package does the
same in Laravel, and adds one thing: the **last good copy is kept for ever**,
so a shelf still shows what it showed when Fourthwall is down.

## Install

```sh
composer require ombabush/laravel-fourthwall
```

```env
FOURTHWALL_SHOP=https://shop.example.com
```

That is enough. Every Fourthwall shop publishes its catalogue as JSON and as a
Google Merchant Center feed with no key at all, and the package reads both.

Requires PHP 8.2+ and Laravel 12. With `ext-intl`, prices are formatted
for the reader's locale (`19,93 $` in Russian). Without it they read `$19.93`
everywhere.

## Check what you have

```sh
php artisan fourthwall:check
```

The command asks for whatever it has not been given: the shop address, a
storefront token, the Platform API user. It tries every source with those
credentials and prints what each one returns: the shop, collections,
every product with its price range, colours, sizes, stock and pictures, and,
with the API user, orders, donations, promotions, webhooks and membership
tiers. It writes nothing and caches nothing. It never prints a customer's
e-mail or postal address. `--json` dumps it all, and a non-zero exit means
something failed, so it can serve as a deploy gate.

## Three sources, one shape

| Source | Needs | Gives |
|---|---|---|
| `feed` | `FOURTHWALL_SHOP` | Every public product, variant ids, prices, colours, sizes, stock yes/no, a picture per colour, descriptions. Only the `all` collection plus the handles you name; collections cannot be listed. |
| `storefront` | `FOURTHWALL_STOREFRONT_TOKEN` (`ptkn_…`) | Everything above, plus the list of collections, every image, stock counts, bundles, prices in 17 currencies. |
| `array` | `fourthwall.catalogue` | A catalogue written in config, for a site with no shop and for tests. |

`auto`, the default, picks `storefront` when a token is set, otherwise `feed`,
otherwise nothing. With nothing configured, every shelf renders empty and no
request is made, so installing the package breaks no page.

All three map onto the same plain objects: `Product`, `Variant`, `Money`,
`Image`, `ShopCollection`, `Shop`. A template never sees a provider payload.

**Money is integer minor units.** `19.93` arrives from the API as a JSON
number, and a float cannot hold it exactly. The conversion happens once, at
the edge; after that everything counts cents.

**A product whose access is not `PUBLIC` never leaves a source.** A hidden,
private or archived product cannot reach a page by accident.

### About the storefront token

The token is **public by design**: Fourthwall's own storefront ships it to
browsers. Create it in *Settings → For developers*, or let `fourthwall:check`
fetch it with the API user.

The Platform API user (`FOURTHWALL_API_USERNAME` and `_PASSWORD`) is the
opposite. It can read orders and customers' e-mail addresses. It is used
server-side only, by `fourthwall:check` and `Fourthwall::platform()`, and
nothing in this package ever puts it in a page.

## Selecting products

```php
use Ombabush\Fourthwall\Facades\Fourthwall;

Fourthwall::products();                          // every public product
Fourthwall::products('archive');                 // one collection
Fourthwall::products(['summer', 'archive']);     // several, each product once

    ->available()                  // in stock
    ->color('Black', 'Navy')       // any of these
    ->size('XL')
    ->priceBetween(10, 20)         // by the lowest price it can be had for
    ->onSale()
    ->search('mosquito scan')      // every word, in name or description
    ->except('some-slug')  ->only('a', 'b')
    ->where(fn (Product $p) => …)
    ->sortBy('price')              // featured (default) · price · -price · name · newest · oldest · updated
    ->inRandomOrder()
    ->take(4)  ->skip(4)

    ->get()  ->first()  ->count()  ->paginate(12)
    ->facets()                     // colours, sizes (in wearing order), min/max price, for a filter bar
    ->fromRequest($request)        // ?q=&color=&size=&min=&max=&sort=&available=1
```

Filtering happens in memory. None of Fourthwall's endpoints filters, a shop's
catalogue is tens of products, and it is already cached, so filtering locally
costs less than one request would.

## A product

```php
$p = Fourthwall::product('mosquito-scan-1993-black-print-t-shirt');

$p->name;  $p->price;  $p->maxPrice;  $p->compareAt;     // Money
$p->hasPriceRange();                                      // 2XL often costs more
$p->colors();  $p->sizes();  $p->swatches();              // ['Black' => '#000000']
$p->image()->width(400);                                  // resized only if the URL is unsigned; Fourthwall's are signed
$p->variants()->where('available', true);
$p->excerpt(160);                                         // plain text
$p->descriptionHtml();                                    // safe: text tags only, no attributes but href
$p->checkoutUrl($variantId);                              // straight into checkout
```

## Links out, and knowing where orders came from

Every link that leaves for the shop goes through one of these:

```php
Fourthwall::productLink($p);             // your page for it (FOURTHWALL_PRODUCT_ROUTE) or the shop's
Fourthwall::buyLink($p, $variantId);     // …/cart/checkout?products={variant}:1
Fourthwall::checkoutUrl(['v1' => 2, 'v2' => 1], coupon: 'FRIENDS');
Fourthwall::donationUrl(10, 'Name', 'Message', options: [5, 10, 20]);
```

`/cart/checkout?products=variantId:qty` is Fourthwall's documented direct
checkout. It needs no cart, no API call and no JavaScript. The «buy» form in
`<x-fourthwall::product>` is a plain GET whose variant picker *is* that
parameter, so it works with scripts switched off.

Set `FOURTHWALL_UTM_SOURCE` and every one of those links carries
`utm_source` and `utm_medium`. Fourthwall's checkout records `utm_*` against
the order, so the shop's analytics show which site sent it. Pass `:params`
to any component, or `params:` to any method, to add a campaign.

## Components

They ship **structure, not a look.** Class names are `fw-*`. There is one
optional stylesheet (`<x-fourthwall::styles />`) that lays things out and is
themed through `--fw-*` custom properties. Publish the views to rewrite any of
them: `php artisan vendor:publish --tag=fourthwall-views`.

| | |
|---|---|
| `<x-fourthwall::shelf>` | A grid of cards. From `:products`, or `collection` / `limit` / `sort` / `available` / `random` / `except`. `size="small"` for a secondary row, `heading`, `more` for a «see all» link. It renders nothing when empty. |
| `<x-fourthwall::card>` | One product: picture, name, price, sold-out state. |
| `<x-fourthwall::product>` | The whole product: pictures, price, colours, a variant picker that is also the checkout form, and the description. |
| `<x-fourthwall::banner>` | An advert: one product, large, with your headline. It takes `slug`, or `collection` for a random pick on each render. |
| `<x-fourthwall::donate>` | Amounts, name and message, handed to Fourthwall's donation page. |
| `<x-fourthwall::filters>` | A GET filter bar built from `facets()`. |
| `<x-fourthwall::price>` | «$15», «from $15», or the old price struck through. |
| `<x-fourthwall::add-to-cart>` | Into the site's cart, or straight into checkout where there is none. |
| `<x-fourthwall::cart-icon>`, `<x-fourthwall::cart>` | See *A cart on your own site*. |
| `<x-fourthwall::promo>`, `<x-fourthwall::supporters>` | See *Promotions and supporters*. |

Strings are in English and Russian. Publish them with `--tag=fourthwall-lang`
to add a language.

## A cart on your own site

With a storefront token, visitors can fill a cart on your pages and check out
with several things at once.

```env
FOURTHWALL_STOREFRONT_TOKEN=ptkn_…
FOURTHWALL_CART_PATH=shop/cart      # mounts POST shop/cart/add|change|remove, GET shop/cart/checkout
FOURTHWALL_CART_PAGE=shop.cart      # YOUR route that shows <x-fourthwall::cart />
```

```blade
<x-fourthwall::cart-icon />                   {{-- in the header: an icon and a count --}}
<x-fourthwall::add-to-cart :product="$p" />   {{-- «add to cart», or «buy» where there is no cart --}}
<x-fourthwall::cart />                        {{-- on your cart page --}}
```

This is how Fourthwall's own headless starter does it:

- The cart lives in Fourthwall's Storefront API. Only its id lives in the
  visitor's session.
- Checkout is a 303 to `/cart/checkout?cartId=…`.
- The item count is kept in the session and updated on every change, so the
  header icon **costs no request on any page**.
- A cart id that has expired, or has already been checked out, is dropped,
  and the next click starts a new cart.
- Every form is a plain POST that works without JavaScript. Send
  `Accept: application/json` to get the cart back as JSON for a drawer.

Every cart carries **metadata**. The site comes from `cart.metadata`, and
`source_page`, the page the click came from, is added by the package. Both
come back on the order next to the UTM tags, so an order says which page sold
it.

**The shop's own cart cannot be read.** Its cookies belong to its domain, and
anything that tries to share them breaks in Safari. It works the other way,
though. Seen on a live shop on 2026-09-23: once the buyer presses «checkout»,
the cart made here *becomes* the shop's cart and is still in `{shop}/cart`
if they wander off. The id is kept here too, so a buyer who looks at checkout
and comes back unpaid finds the same cart on both sites. It is let go when
Fourthwall says the cart is gone.

## Promotions and supporters

With the Platform API user (server-side only):

```blade
<x-fourthwall::promo />              {{-- «−15% with the code SNIFF15», linked to checkout with the coupon --}}
<x-fourthwall::supporters :limit="12" />
```

- **Promotions** shows the shop's live promotions, and only public ones: a
  members-only discount advertised to everyone would be a promise most
  visitors cannot use. When the promotion ends, the banner renders nothing.
- **Supporters** shows recent completed donations: the name the donor typed,
  the amount and the message. Fourthwall's record also carries the donor's
  e-mail address, which is **dropped before anything is cached**.

Both are cached like the catalogue and refreshed by `fourthwall:refresh` and
by the `PROMOTION_*` and `DONATION` webhooks.

## What your credentials allow

```php
Fourthwall::supports(Capability::Carts);   // false without a storefront token
Fourthwall::capabilities();                // everything that is on
```

| | shop address | + storefront token | + Platform API user | + webhook |
|---|:-:|:-:|:-:|:-:|
| catalogue, checkout links, donations | ✓ | ✓ | ✓ | ✓ |
| collections list, stock counts, **cart** | | ✓ | ✓ | ✓ |
| promotions, supporters | | | ✓ | ✓ |
| instant updates | | | | ✓ |

Components check this themselves. Without a cart, `add-to-cart` is a «buy»
link, and without promotions, `promo` renders nothing. One template works at
every level. `fourthwall:check` prints the table for whatever credentials it
is given.

## Other languages

A Fourthwall shop is in one language: names and descriptions come back in it,
and `/ru` on the shop is a 404. A site in two languages writes its own, by
locale and slug. Anything left unwritten falls through to the shop's text:

```php
// config/fourthwall.php
'translations' => [
    'ru' => [
        'mosquito-scan-1993-black-print-t-shirt' => [
            'name' => 'Комар, скан 1993 — футболка, чёрный принт',
            'description' => '<p>Настоящий комар. …</p>',
        ],
    ],
],
```

Translations apply on every read for `app()->getLocale()` and are never
cached, so switching the language needs no refresh. Search runs on the words
the reader sees. Checkout stays in the shop's language, because it is
Fourthwall's page.

## Freshness

```env
FOURTHWALL_CACHE_TTL=3600     # default: a read refetches anything older than an hour
FOURTHWALL_CACHE_TTL=never    # a read never fetches if anything is cached
```

With `never`, schedule the refresh, so a page render never waits on
Fourthwall:

```php
Schedule::command('fourthwall:refresh')->hourly();
```

`fourthwall:refresh` fetches one thing at a time and reports each one: the
shop, then each `--collection`, then each `--product`. A collection that
suddenly comes back empty does not overwrite the products it had. That is
far more often a bad afternoon at Fourthwall than an emptied shop.

### Webhooks

```env
FOURTHWALL_WEBHOOK_PATH=webhooks/fourthwall
FOURTHWALL_WEBHOOK_SECRET=…
```

The route exists only when the path is set. The signature
(`X-Fourthwall-Hmac-SHA256`: HMAC-SHA256 of the raw body, base64) is checked
in constant time before the body is read. Product and collection events drop
the cache. Every verified event is re-dispatched as
`FourthwallWebhookReceived`, including ORDER_PLACED, DONATION and
SUBSCRIPTION_*:

```php
Event::listen(fn (FourthwallWebhookReceived $e) => $e->type === 'DONATION' && …);
```

## Several shops

```php
Fourthwall::for(['shop' => 'https://their.shop', 'storefront_token' => $theirs])->products()->get();
```

Cache keys include the shop, so two shops never read each other's catalogue.
This is the start of a site where each user connects their own shop. The
rest, OAuth and per-shop credentials, is the host application's job and is
not in this package yet.

## Examples

[`examples/routes.php`](examples/routes.php) and
[`examples/shop.blade.php`](examples/shop.blade.php) cover a shop page with
featured collections above everything else, a filtered and paginated
catalogue, one product on your own site with related products, donations, a
pre-filled checkout link with a coupon, webhooks and the scheduler.

## Tests

```sh
composer install && vendor/bin/pest
```

The suite never touches the network. The public-feed fixtures were recorded
from a real shop on 2026-09-23. The Storefront fixture is built from
Fourthwall's API reference, because it was written without a token, and says
so in the file.

## Licence

MIT
