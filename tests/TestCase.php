<?php

namespace Ombabush\Fourthwall\Tests;

use Ombabush\Fourthwall\FourthwallServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FourthwallServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('fourthwall.link_params', []);
        // Mounted in every test: without a storefront token it must answer 404.
        $app['config']->set('fourthwall.cart.path', 'shop/cart');
    }
}
