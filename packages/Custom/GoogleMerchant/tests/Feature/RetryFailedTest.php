<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Services\ConnectionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->row = fn ($product, array $attrs) => GmcProduct::create(array_merge([
        'gmc_target_id' => $this->target->id, 'offer_id' => $product->sku, 'product_id' => $product->id, 'root_product_id' => $product->id,
    ], $attrs));
});

it('retries transient failures and counts the cycle', function () {
    $product = simpleProduct();
    $row = ($this->row)($product, ['state' => 'failed', 'error_kind' => 'transient']);
    Queue::fake();

    Artisan::call('gmc:retry-failed');

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id);

    expect($row->fresh()->retry_cycles)->toBe(1);
});

it('does not retry permanent failures', function () {
    $product = simpleProduct();
    ($this->row)($product, ['state' => 'failed', 'error_kind' => 'permanent']);
    Queue::fake();

    Artisan::call('gmc:retry-failed');

    Queue::assertNotPushed(SyncProductJob::class);
});

it('stops after five retry cycles', function () {
    $product = simpleProduct();
    ($this->row)($product, ['state' => 'failed', 'error_kind' => 'quota', 'retry_cycles' => 5]);
    Queue::fake();

    Artisan::call('gmc:retry-failed');

    Queue::assertNotPushed(SyncProductJob::class);
});

it('requeues pending work when the account is reconnected', function () {
    $product = simpleProduct();
    ($this->row)($product, ['state' => 'pending']);

    $this->target->account->forceFill(['status' => 'needs_reauth'])->save();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa', 'expires_in' => 3600])]);
    Queue::fake();

    app(ConnectionService::class)->connectServiceAccount(['type' => 'service_account', 'client_email' => 'x@p.iam.gserviceaccount.com', 'private_key' => $pem]);

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id);
});

it('syncs chosen products from the command line', function () {
    $product = simpleProduct();
    Queue::fake();

    Artisan::call('gmc:sync', ['--product' => [$product->id], '--force' => true]);

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id && $job->force && $job->delay === 0);
});
