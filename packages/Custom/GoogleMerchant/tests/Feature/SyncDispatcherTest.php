<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Faker\Helpers\Product as ProductFaker;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
});

it('dispatches one unique job per root and target', function () {
    Queue::fake();
    $product = simpleProduct();

    $count = app(SyncDispatcher::class)->queue([$product->id, $product->id, $product->id], 'save');

    expect($count)->toBe(1);

    Queue::assertPushed(SyncProductJob::class, 1);
    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id
        && $job->targetId === $this->target->id
        && $job->connection === 'database'
        && $job->queue === 'gmc'
        && $job->delay === 30);

    expect(GmcProduct::where('root_product_id', $product->id)->value('state'))->toBe('pending');
});

it('uses the configured debounce delay and no delay for manual syncs', function () {
    Queue::fake();
    gmcConfig(['settings.sync_delay' => 90]);
    $product = simpleProduct();

    app(SyncDispatcher::class)->queue([$product->id], 'save');
    app(SyncDispatcher::class)->queue([$product->id], 'manual', 0, true);

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->delay === 90 && ! $job->force);
    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->delay === 0 && $job->force);
});

it('resolves variants to their parent', function () {
    Queue::fake();
    $parent = configurableProduct(2);

    app(SyncDispatcher::class)->queue([$parent->variants()->first()->id], 'stock');

    Queue::assertPushed(SyncProductJob::class, 1);
    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $parent->id);
});

it('includes the bundles a product belongs to', function () {
    Queue::fake();
    $bundle = (new ProductFaker)->getBundleProductFactory()->create();

    $child = DB::table('product_bundle_option_products as bop')
        ->join('product_bundle_options as bo', 'bo.id', '=', 'bop.product_bundle_option_id')
        ->where('bo.product_id', $bundle->id)
        ->value('bop.product_id');

    expect(app(SyncDispatcher::class)->rootsFor([$child]))->toEqualCanonicalizing([$child, $bundle->id]);
});

it('resolves grouped products to their children', function () {
    $grouped = (new ProductFaker)->getGroupedProductFactory()->create();

    $children = DB::table('product_grouped_products')->where('product_id', $grouped->id)->pluck('associated_product_id')->all();

    expect(app(SyncDispatcher::class)->rootsFor([$grouped->id]))->toEqualCanonicalizing($children);
});

it('does nothing when disabled, auto-sync is off, or disconnected', function () {
    Queue::fake();
    $product = simpleProduct();

    gmcConfig(['settings.enabled' => 0]);
    expect(app(SyncDispatcher::class)->queue([$product->id], 'save'))->toBe(0);

    gmcConfig(['settings.enabled' => 1, 'settings.auto_sync' => 0]);
    expect(app(SyncDispatcher::class)->queue([$product->id], 'save'))->toBe(0)
        ->and(app(SyncDispatcher::class)->queue([$product->id], 'manual'))->toBe(1);

    gmcConfig(['settings.auto_sync' => 1]);
    $this->target->account->forceFill(['status' => 'disconnected'])->save();
    expect(app(SyncDispatcher::class)->queue([$product->id], 'save'))->toBe(0);

    Queue::assertPushed(SyncProductJob::class, 1);
});

it('drops duplicates while pending but allows one after processing starts', function () {
    Cache::flush();
    $product = simpleProduct();
    $jobs = fn () => DB::table('jobs')->where('queue', 'gmc')->count();
    $before = $jobs();

    app(SyncDispatcher::class)->queue([$product->id], 'save');
    app(SyncDispatcher::class)->queue([$product->id], 'save');

    expect($jobs() - $before)->toBe(1);

    // A worker picking the job up releases the lock (ShouldBeUniqueUntilProcessing).
    (new UniqueLock(Cache::driver()))->release(new SyncProductJob($product->id, $this->target->id));

    app(SyncDispatcher::class)->queue([$product->id], 'save');

    expect($jobs() - $before)->toBe(2);
});

it('bumps the revision on every change, even when the job is deduplicated', function () {
    Queue::fake();
    $product = simpleProduct();

    app(SyncDispatcher::class)->queue([$product->id], 'save');
    app(SyncDispatcher::class)->queue([$product->id], 'save');

    expect(GmcProduct::where('root_product_id', $product->id)->value('revision'))->toBe(2);
});
