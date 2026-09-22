<?php

use Illuminate\Support\Facades\Http;
use Ombabush\Fourthwall\Fourthwall;
use Ombabush\Fourthwall\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function fixture(string $name): string
{
    return file_get_contents(__DIR__.'/fixtures/'.$name);
}

/**
 * shop.ombabush.com's own public feeds, as recorded on 2026-09-23:
 * three t-shirts, 117 variants, some of them out of stock.
 */
function fakeFeeds(): void
{
    Http::fake([
        'shop.test/collections/all.json' => Http::response(fixture('feed-collection-all.json')),
        'shop.test/collections/all/2.json' => Http::response(['products' => [], 'current_page' => 2]),
        'shop.test/.well-known/merchant-center/rss.xml' => Http::response(fixture('feed-merchant-center.xml')),
        '*' => Http::response('unexpected request', 500),
    ]);
}

function fakeStorefront(): void
{
    Http::fake([
        'storefront.test/v1/shop*' => Http::response(['id' => 'sh_1', 'name' => 'Test Shop', 'domain' => 'test-shop', 'publicDomain' => 'shop.test']),
        'storefront.test/v1/collections/archive/products*' => Http::response(json_decode(fixture('storefront-collection-products.json'), true)),
        'storefront.test/v1/collections?*' => Http::response(['results' => [['id' => 'col_1', 'slug' => 'archive', 'name' => 'Sniff.Ru архив', 'description' => '']], 'paging' => ['hasNextPage' => false]]),
        '*' => Http::response('unexpected request', 500),
    ]);
}

function fourthwall(array $config = []): Fourthwall
{
    config(['fourthwall' => array_replace(config('fourthwall'), $config)]);
    app()->forgetInstance(Fourthwall::class);

    return app(Fourthwall::class);
}
