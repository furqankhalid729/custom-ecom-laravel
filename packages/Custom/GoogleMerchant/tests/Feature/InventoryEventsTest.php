<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Listeners\InventoryListener;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
});

/** Minimal order/refund/shipment shape: items with product_id and an optional child item. */
function salesEntity(array $productIds, ?int $childId = null): object
{
    return (object) ['items' => collect($productIds)->map(fn ($id) => (object) [
        'product_id' => $id,
        'child'      => $childId ? (object) ['product_id' => $childId] : null,
    ])];
}

it('listens to every stock-changing sales event', function (string $event) {
    Event::fake();

    Event::assertListening($event, [InventoryListener::class, 'handle']);
})->with(['checkout.order.save.after', 'sales.order.cancel.after', 'sales.refund.save.after', 'sales.shipment.save.after']);

it('queues stock sync for the sold products', function () {
    $product = simpleProduct();
    Queue::fake();

    app(InventoryListener::class)->handle(salesEntity([$product->id]));

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id);
});

it('resolves a configurable order item to its parent', function () {
    $parent = configurableProduct(2);
    $variant = $parent->variants()->first();
    Queue::fake();

    app(InventoryListener::class)->handle(salesEntity([$parent->id], $variant->id));

    Queue::assertPushed(SyncProductJob::class, 1);
    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $parent->id);
});

it('turns stock to OUT_OF_STOCK when the last unit sells and back after a refund', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('productinput-insert'))]);
    $product = simpleProduct();

    $runQueued = function () {
        Queue::pushed(SyncProductJob::class)->each(function ($job) {
            (new \Illuminate\Bus\UniqueLock(\Illuminate\Support\Facades\Cache::driver()))->release($job); // as a worker does

            $job->handle(app(SyncService::class), app(SyncDispatcher::class));
        });
    };

    Queue::fake();
    setStock($product, 0);
    app(InventoryListener::class)->handle(salesEntity([$product->id]));
    $runQueued();

    Queue::fake();
    setStock($product, 1);
    app(InventoryListener::class)->handle(salesEntity([$product->id]));
    $runQueued();

    $availability = collect(Http::recorded())->map(fn ($pair) => $pair[0]['productAttributes']['availability'] ?? null)->all();

    expect($availability)->toBe(['OUT_OF_STOCK', 'IN_STOCK']);
});
