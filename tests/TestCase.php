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
        $app['config']->set('fourthwall.link_params', []);
    }
}
