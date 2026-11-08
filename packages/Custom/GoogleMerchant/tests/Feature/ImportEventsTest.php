<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Listeners\ImportListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
});

it('queues sync for imported batch skus', function () {
    $products = [simpleProduct(), simpleProduct()];
    Queue::fake();

    $batch = (object) [
        'import' => (object) ['type' => 'products'],
        'data'   => [['sku' => $products[0]->sku, 'name' => 'x'], ['sku' => $products[1]->sku], ['sku' => 'not-in-store']],
    ];

    app(ImportListener::class)->handle($batch);

    Queue::assertPushed(SyncProductJob::class, 2);
    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->delay === 60);
});

it('ignores non-product imports', function () {
    Queue::fake();

    app(ImportListener::class)->handle((object) [
        'import' => (object) ['type' => 'customers'],
        'data'   => [['email' => 'a@b.test']],
    ]);

    Queue::assertNothingPushed();
});

it('listens to product import batches', function () {
    Event::fake();

    Event::assertListening('data_transfer.imports.batch.import.after', [ImportListener::class, 'handle']);
});
