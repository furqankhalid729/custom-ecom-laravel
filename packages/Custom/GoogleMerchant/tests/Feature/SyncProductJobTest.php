<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Api\Exceptions\QuotaExhaustedException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Jobs\DeleteOfferJob;
use Custom\GoogleMerchant\Jobs\HeartbeatJob;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;
use Custom\GoogleMerchant\Services\SetupChecklist;
use Custom\GoogleMerchant\Sync\SyncOutcome;
use Custom\GoogleMerchant\Sync\SyncResult;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->fakeSync = function (callable $behaviour) {
        $fake = Mockery::mock(SyncService::class);
        $fake->shouldReceive('syncRoot')->andReturnUsing($behaviour);
        app()->instance(SyncService::class, $fake);
    };
});

it('declares retry policy and uniqueness', function () {
    $job = new SyncProductJob(5, $this->target->id);

    expect($job->uniqueId())->toBe("{$this->target->id}:5")
        ->and($job->uniqueFor)->toBe(3600)
        ->and($job->tries)->toBe(0)
        ->and($job->backoff())->toBe([30, 120, 600, 1800, 3600])
        ->and($job->retryUntil()->greaterThan(now()->addHours(23)))->toBeTrue()
        ->and($job->middleware()[0])->toBeInstanceOf(EnsureAccountReady::class);
});

it('releases with retry-after plus jitter on transient errors', function () {
    ($this->fakeSync)(fn () => throw new TransientApiException('busy', 429, null, 120));

    $job = (new SyncProductJob(5, $this->target->id))->withFakeQueueInteractions();
    $job->handle(app(SyncService::class), app(\Custom\GoogleMerchant\Sync\SyncDispatcher::class));

    expect($job->job->isReleased())->toBeTrue()
        ->and($job->job->releaseDelay)->toBeGreaterThanOrEqual(120)
        ->and($job->job->releaseDelay)->toBeLessThanOrEqual(135);
});

it('releases for the quota reset on quota errors', function () {
    ($this->fakeSync)(fn () => throw new QuotaExhaustedException('quota', 429, null, 3600));

    $job = (new SyncProductJob(5, $this->target->id))->withFakeQueueInteractions();
    $job->handle(app(SyncService::class), app(\Custom\GoogleMerchant\Sync\SyncDispatcher::class));

    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(3600);
});

it('releases while the account needs reauth and deletes when disconnected', function () {
    $middleware = new EnsureAccountReady;

    $this->target->account->forceFill(['status' => 'needs_reauth'])->save();
    $job = (new SyncProductJob(5, $this->target->id))->withFakeQueueInteractions();
    $ran = false;
    $middleware->handle($job, function () use (&$ran) { $ran = true; });

    expect($ran)->toBeFalse()->and($job->job->releaseDelay)->toBe(900);

    $this->target->account->forceFill(['status' => 'disconnected'])->save();
    $job = (new SyncProductJob(5, $this->target->id))->withFakeQueueInteractions();
    $middleware->handle($job, function () use (&$ran) { $ran = true; });

    expect($ran)->toBeFalse()->and($job->job->isDeleted())->toBeTrue();

    $this->target->account->forceFill(['status' => 'connected'])->save();
    $job = (new SyncProductJob(5, $this->target->id))->withFakeQueueInteractions();
    $middleware->handle($job, function () use (&$ran) { $ran = true; });

    expect($ran)->toBeTrue();
});

it('re-dispatches when the product changed during the job', function () {
    Queue::fake();
    $product = simpleProduct();

    ($this->fakeSync)(fn () => new SyncResult(SyncOutcome::Synced, true));
    (new SyncProductJob($product->id, $this->target->id))->handle(app(SyncService::class), app(\Custom\GoogleMerchant\Sync\SyncDispatcher::class));

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id && $job->delay === 30);

    Queue::fake();
    ($this->fakeSync)(fn () => new SyncResult(SyncOutcome::Synced, false));
    (new SyncProductJob($product->id, $this->target->id))->handle(app(SyncService::class), app(\Custom\GoogleMerchant\Sync\SyncDispatcher::class));

    Queue::assertNothingPushed();
});

it('marks rows failed when the job finally fails', function () {
    $product = simpleProduct();

    app(GmcProductRepository::class)->markPending($product->id, $this->target->id);

    (new SyncProductJob($product->id, $this->target->id))->failed(new TransientApiException('Google unavailable', 503));

    $row = GmcProduct::where('root_product_id', $product->id)->first();

    expect($row->state)->toBe('failed')
        ->and($row->error_kind)->toBe('transient')
        ->and($row->last_error)->toContain('Google unavailable');
});

it('runs delete jobs through the sync service', function () {
    $fake = Mockery::mock(SyncService::class);
    $fake->shouldReceive('deleteOffer')->once()->with(42)->andReturn(SyncOutcome::Deleted);
    app()->instance(SyncService::class, $fake);

    $job = new DeleteOfferJob(42);

    expect($job->connection)->toBe('database')->and($job->queue)->toBe('gmc');

    $job->handle(app(SyncService::class));
});

it('records the worker heartbeat', function () {
    Cache::forget(SetupChecklist::HEARTBEAT_KEY);

    (new HeartbeatJob)->handle();

    expect(Cache::get(SetupChecklist::HEARTBEAT_KEY))->toBeGreaterThan(time() - 5);
});
