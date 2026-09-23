# Changelog

## 0.2.2 — 2026-09-23

- **A wrong token no longer empties the shop.** A storefront token must look
  like one (`ptkn_…`), or it is ignored and the public feeds are read. A real
  token that Fourthwall refuses (revoked, from another shop) now falls back to
  the feeds too, and the failure is logged.
- `fourthwall:check` no longer prints a value from the token variable that is
  not a storefront token. That is usually a password on the wrong line, and
  the command now says so and asks for it to be rotated.

## 0.2.1 — 2026-09-23

- **Laravel 12 only.** Packagist now blocks every Laravel 11 release on open
  security advisories, so 11 cannot be installed without switching that check
  off. CI runs PHP 8.2, 8.3 and 8.4 on Laravel 12.

## 0.2.0 — 2026-09-23

- **A cart on your own site.** `Fourthwall::cart()` holds a Storefront API cart
  whose id lives in the visitor's session. `add`, `change` and `remove` are
  available, and checkout hands over with `/cart/checkout?cartId=`. The item
  count is kept in the session, so a header icon costs no request. Cart
  metadata (the site, the page that sold it) comes back on the order.
  Mounted with `FOURTHWALL_CART_PATH`; needs the storefront token.
- Components: `<x-fourthwall::add-to-cart>` (falls back to a direct «buy»
  without a cart), `<x-fourthwall::cart-icon>` and `<x-fourthwall::cart>`.
- **Promotions** (`Fourthwall::promotions()`, `<x-fourthwall::promo>`): the
  shop's live, public promotions, for a banner that disappears when the
  promotion ends. Needs the Platform API user.
- **Supporters** (`Fourthwall::supporters()`, `<x-fourthwall::supporters>`):
  recent completed donations with name, amount and message. The donor's
  e-mail address is dropped before anything is cached.
- **Capabilities**: `Fourthwall::supports(Capability::Carts)` and
  `capabilities()` report what the configured credentials allow. Components
  use them to degrade. `fourthwall:check` prints them.
- Webhooks: `PROMOTION_*` and `DONATION` refresh promotions and supporters.
  `fourthwall:refresh` refreshes them too when the API user is set.
- Colour swatches are accepted only as hex colours, because they are printed
  into a `style` attribute.
- CI on PHP 8.2–8.4.

## 0.1.1 — 2026-09-23

- Signed imgproxy URLs are no longer resized. Changing the width broke the
  signature, and every picture failed with a 400.
- The stylesheet reads `--fw-*` and never sets them, so a host can theme
  from any ancestor.

## 0.1.0 — 2026-09-23

- The first release. It reads the catalogue from the public feeds, the
  Storefront API or an array, maps it onto plain objects with integer-cent
  `Money`, and filters in memory. The cache keeps the last good copy. It adds
  Blade components (shelf, card, product, banner, filters, price, donate),
  signed webhooks and `fourthwall:check`/`fourthwall:refresh`.
