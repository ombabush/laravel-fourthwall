<?php

namespace Ombabush\Fourthwall;

/**
 * What a configured shop can do on this site, given the credentials it has.
 *
 * Components ask instead of assuming, and degrade: without a storefront token
 * there is no cart, so «add to cart» becomes «buy»; without the API user
 * there are no promotions to advertise, so the promo banner renders nothing.
 *
 *   Fourthwall::supports(Capability::Carts)
 *   Fourthwall::capabilities()   // every one that is on, for a debug page
 */
enum Capability: string
{
    /** Products can be read at all. */
    case Catalogue = 'catalogue';

    /** Collections can be LISTED (the public feeds can only fetch named ones). */
    case Collections = 'collections';

    /** Stock counts, not just in-stock yes/no. */
    case Stock = 'stock';

    /** Links straight into checkout, with coupons and UTM tags. */
    case CheckoutLinks = 'checkout_links';

    /** A cart held on our site, handed to checkout by id. Storefront token. */
    case Carts = 'carts';

    /** A donation form handed to the shop's donation page. */
    case Donations = 'donations';

    /** The shop's live promotions, for a banner. Platform API user. */
    case Promotions = 'promotions';

    /** Recent donations — names and messages only — for a wall. Platform API user. */
    case Supporters = 'supporters';

    /** The shop can tell us when things change. Webhook path + secret. */
    case Webhooks = 'webhooks';
}
