<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\RefreshRunJob;
use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('productinput-insert'))]);

    $this->products = collect([simpleProduct(), simpleProduct(), simpleProduct()]);

    $this->products->each(fn ($p) => app(SyncService::class)->syncRoot($p->id, $this->target->id));

    $this->runRefresh = function () {
        Queue::fake();

        Artisan::call('gmc:refresh');

        // Execute the chunked refresh run synchronously, chunk after chunk.
        while ($job = Queue::pushed(RefreshRunJob::class)->first(fn ($j) => ! isset($j->ran))) {
            $job->ran = true;
            $job->handle(app(\Custom\GoogleMerchant\Mapping\ProductMapper::class), app(\Custom\GoogleMerchant\Sync\Eligibility::class), app(\Custom\GoogleMerchant\Sync\SyncDispatcher::class));
        }

        return Queue::pushed(SyncProductJob::class)->pluck('rootProductId')->all();
    };
});

it('requeues only products whose google data drifted', function () {
    // Price changed without any event (e.g. direct DB edit / catalog rule reindex).
    setProductAttribute($this->products[1], 'price', 99);

    $queued = ($this->runRefresh)();

    expect($queued)->toContain($this->products[1]->id)
        ->not->toContain($this->products[0]->id)
        ->not->toContain($this->products[2]->id);
});

it('requeues offers that are about to expire in google', function () {
    GmcProduct::where('product_id', $this->products[2]->id)->update(['last_synced_at' => now()->subDays(25)]);

    expect(($this->runRefresh)())->toContain($this->products[2]->id)->not->toContain($this->products[0]->id);
});

it('catches up a change whose dispatch was swallowed', function () {
    // A listener failure: the revision moved but no job was queued.
    GmcProduct::where('product_id', $this->products[0]->id)->increment('revision');

    expect(($this->runRefresh)())->toContain($this->products[0]->id);
});

it('requeues the root of ledger rows whose product disappeared', function () {
    DB::table('products')->where('id', $this->products[0]->id)->delete();

    expect(($this->runRefresh)())->toContain($this->products[0]->id);
});

it('records a completed refresh run', function () {
    ($this->runRefresh)();

    $run = GmcRun::where('type', 'refresh')->latest('id')->first();

    expect($run->status)->toBe('completed')
        ->and($run->total)->toBeGreaterThanOrEqual(3)
        ->and($run->processed)->toBe($run->total)
        ->and($run->finished_at)->not->toBeNull();
});
