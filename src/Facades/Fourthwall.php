<?php

namespace Ombabush\Fourthwall\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ombabush\Fourthwall\ProductQuery products(string|array|null $collections = null)
 * @method static \Ombabush\Fourthwall\Data\Product|null product(string $slug)
 * @method static \Illuminate\Support\Collection collections()
 * @method static \Ombabush\Fourthwall\Data\ShopCollection|null collection(string $slug)
 * @method static \Ombabush\Fourthwall\Data\Shop|null shop()
 * @method static string|null shopUrl()
 * @method static string checkoutUrl(array $lines, ?string $coupon = null, ?string $currency = null, array $params = [])
 * @method static string donationUrl(int|float|string|null $amount = null, ?string $donor = null, ?string $message = null, array $options = [], ?string $currency = null)
 * @method static \Ombabush\Fourthwall\Platform platform()
 * @method static \Ombabush\Fourthwall\Fourthwall for(array $config)
 * @method static array refresh(?string $collection = null, ?string $product = null)
 * @method static void forget(?string $what = null)
 * @method static bool configured()
 *
 * @see \Ombabush\Fourthwall\Fourthwall
 */
class Fourthwall extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ombabush\Fourthwall\Fourthwall::class;
    }
}
