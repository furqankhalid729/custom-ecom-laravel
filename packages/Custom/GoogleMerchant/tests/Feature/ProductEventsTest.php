<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\DeleteOfferJob;
use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\delete;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
});

it('queues sync when an admin creates a product', function () {
    $this->loginAsAdmin();
    Queue::fake();

    post(route('admin.catalog.products.store'), ['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'gmc-new-'.uniqid()]);

    $product = DB::table('products')->latest('id')->first();

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id && $job->targetId === $this->target->id);
});

it('queues sync on mass status update', function () {
    $this->loginAsAdmin();
    $products = [simpleProduct(), simpleProduct()];
    Queue::fake();

    postJson(route('admin.catalog.products.mass_update'), ['indices' => [$products[0]->id, $products[1]->id], 'value' => 0])->assertOk();

    Queue::assertPushed(SyncProductJob::class, 2);
});

it('queues one sync when the sku changes and logs the change', function () {
    $product = simpleProduct();
    $old = $product->sku;

    GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => $old, 'product_id' => $product->id, 'root_product_id' => $product->id, 'state' => 'synced']);

    Queue::fake();

    Event::dispatch('catalog.product.update.before', $product->id);
    DB::table('products')->where('id', $product->id)->update(['sku' => 'renamed-'.$product->id]);
    Event::dispatch('catalog.product.update.after', $product->fresh());

    Queue::assertPushed(SyncProductJob::class, 1);
    Queue::assertNotPushed(DeleteOfferJob::class);

    expect(GmcProduct::where('offer_id', $old)->value('root_product_id'))->toBe($product->id)
        ->and(DB::table('gmc_sync_logs')->where('offer_id', $old)->where('error_message', 'like', "%renamed-{$product->id}%")->exists())->toBeTrue();
});

it('captures offers before a configurable product is deleted', function () {
    $this->loginAsAdmin();
    $parent = configurableProduct(3);

    foreach ($parent->variants as $variant) {
        GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => $variant->sku, 'product_id' => $variant->id, 'root_product_id' => $parent->id, 'state' => 'synced', 'last_synced_at' => now(), 'product_input_name' => 'accounts/123456/productInputs/x'.$variant->id]);
    }

    Queue::fake();

    delete(route('admin.catalog.products.delete', $parent->id))->assertOk();

    expect(GmcProduct::where('root_product_id', $parent->id)->pluck('state')->unique()->all())->toBe(['deleting']);

    Queue::assertPushed(DeleteOfferJob::class, 3);
});

it('refreshes the parent when a variant is deleted', function () {
    $parent = configurableProduct(2);
    $variant = $parent->variants()->first();

    Queue::fake();

    Event::dispatch('catalog.product.delete.before', $variant->id);

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $parent->id);
});

it('never fails a product save when the google sync throws', function () {
    $this->loginAsAdmin();
    Exceptions::fake();
    $product = simpleProduct();

    $failing = Mockery::mock(SyncDispatcher::class);
    $failing->shouldReceive('queue')->andThrow(new RuntimeException('google is down'));
    app()->instance(SyncDispatcher::class, $failing);

    postJson(route('admin.catalog.products.mass_update'), ['indices' => [$product->id], 'value' => 0])->assertOk();

    expect(DB::table('product_flat')->where('product_id', $product->id)->value('status'))->toEqual(0);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'google is down');
});
