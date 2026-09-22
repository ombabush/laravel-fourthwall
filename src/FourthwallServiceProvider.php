<?php

namespace Ombabush\Fourthwall;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ombabush\Fourthwall\Commands\FourthwallCheckCommand;
use Ombabush\Fourthwall\Commands\FourthwallRefreshCommand;
use Ombabush\Fourthwall\Http\WebhookController;

class FourthwallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fourthwall.php', 'fourthwall');

        $this->app->singleton(Fourthwall::class, fn ($app) => Fourthwall::fromConfig(
            $app['config']['fourthwall'],
            $app['cache']->store($app['config']['fourthwall.cache.store']),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'fourthwall');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'fourthwall');

        // <x-fourthwall::shelf />, <x-fourthwall::card />, … — the host's
        // published copies first, so `vendor:publish --tag=fourthwall-views`
        // really does let a site rewrite any of them.
        if (is_dir($published = resource_path('views/vendor/fourthwall/components'))) {
            Blade::anonymousComponentPath($published, 'fourthwall');
        }
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'fourthwall');

        // Nothing is mounted unless asked for: a package that adds URLs to a
        // site by being installed is a package that surprises someone.
        if ($path = config('fourthwall.webhook.path')) {
            Route::post($path, WebhookController::class)->name('fourthwall.webhook');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/fourthwall.php' => config_path('fourthwall.php')], 'fourthwall-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/fourthwall')], 'fourthwall-views');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/fourthwall')], 'fourthwall-lang');
            $this->publishes([__DIR__.'/../resources/css/fourthwall.css' => public_path('vendor/fourthwall/fourthwall.css')], 'fourthwall-assets');

            $this->commands([FourthwallCheckCommand::class, FourthwallRefreshCommand::class]);
        }
    }

    /** The optional stylesheet, for <x-fourthwall::styles /> or your own build. */
    public static function css(): string
    {
        return (string) file_get_contents(__DIR__.'/../resources/css/fourthwall.css');
    }
}
