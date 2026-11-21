<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Console\GmcSchedule;
use Custom\GoogleMerchant\Jobs\ReconcileJob;
use Custom\GoogleMerchant\Jobs\RefreshRunJob;
use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Mapping\ProductMapper;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcSyncLog;
use Custom\GoogleMerchant\Support\ProductInputName;
use Custom\GoogleMerchant\Sync\Eligibility;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->deleted = [];
    $this->googlePages = [];

    Http::fake(function (Request $request) {
        if ($request->method() === 'DELETE') {
            $this->deleted[] = $request->url();

            return Http::response([]);
        }

        if (str_contains($request->url(), '/products/v1/accounts/123456/products')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response($this->googlePages[$query['pageToken'] ?? null]);
        }

        return Http::response(fixture('productinput-insert'));
    });

    $this->products = collect([simpleProduct(), simpleProduct()]);
    $this->products->each(fn ($p) => app(SyncService::class)->syncRoot($p->id, $this->target->id));

    $offer = fn (string $offerId, string $dataSource = 'accounts/123456/dataSources/999') => [
        'name' => "accounts/123456/products/en~US~$offerId",
        'offerId' => $offerId,
        'contentLanguage' => 'en',
        'feedLabel' => 'US',
        'dataSource' => $dataSource,
    ];

    // Google has: both store products, a ghost offer, and an offer owned by another data source.
    $this->googlePages = [
        null => ['products' => [$offer($this->products[0]->sku), $offer('GHOST-1')], 'nextPageToken' => 'p2'],
        'p2' => ['products' => [$offer($this->products[1]->sku), $offer('OTHER-DS-1', 'accounts/123456/dataSources/555')]],
    ];

    /** Runs gmc:reconcile synchronously (Google pages, then the drift pass). Returns queued product syncs. */
    $this->runReconcile = function (): array {
        Queue::fake();

        Artisan::call('gmc:reconcile');

        while (true) {
            $job = Queue::pushed(ReconcileJob::class)->first(fn ($j) => ! isset($j->ran))
                ?? Queue::pushed(RefreshRunJob::class)->first(fn ($j) => ! isset($j->ran));

            if (! $job) {
                break;
            }

            $job->ran = true;

            $job instanceof ReconcileJob
                ? $job->handle(app(MerchantApiClient::class))
                : $job->handle(app(ProductMapper::class), app(Eligibility::class), app(SyncDispatcher::class));
        }

        return Queue::pushed(SyncProductJob::class)->pluck('rootProductId')->all();
    };
});

it('deletes a google offer that has no store product', function () {
    ($this->runReconcile)();

    $ghost = ProductInputName::encode('123456', 'en', 'US', 'GHOST-1');

    expect($this->deleted)->toHaveCount(1)
        ->and($this->deleted[0])->toContain('products/v1/'.$ghost)
        ->and($this->deleted[0])->toContain('dataSource='.rawurlencode('accounts/123456/dataSources/999'));

    $log = GmcSyncLog::where('offer_id', 'GHOST-1')->first();

    expect($log)->not->toBeNull()
        ->and($log->operation)->toBe('delete')
        ->and($log->status)->toBe('success')
        ->and($log->context)->toBe('orphan')
        ->and($log->gmc_target_id)->toBe($this->target->id);
});

it('never touches offers from another data source', function () {
    ($this->runReconcile)();

    expect(collect($this->deleted)->filter(fn ($url) => str_contains($url, ProductInputName::encode('123456', 'en', 'US', 'OTHER-DS-1'))))->toBeEmpty()
        ->and(GmcSyncLog::where('offer_id', 'OTHER-DS-1')->exists())->toBeFalse();
});

it('keeps an offer whose store product exists even without a ledger row', function () {
    DB::table('gmc_products')->where('product_id', $this->products[1]->id)->delete();

    ($this->runReconcile)();

    expect($this->deleted)->toHaveCount(1); // only GHOST-1
});

it('requeues a product that changed without an event', function () {
    setProductAttribute($this->products[0], 'price', 77);

    expect(($this->runReconcile)())->toContain($this->products[0]->id)
        ->not->toContain($this->products[1]->id);
});

it('completes a reconcile run with counts', function () {
    ($this->runReconcile)();

    $run = GmcRun::where('type', 'reconcile')->latest('id')->first();

    expect($run->status)->toBe('completed')
        ->and($run->gmc_target_id)->toBe($this->target->id)
        ->and($run->options['google_offers'])->toBe(3) // own data source only
        ->and($run->options['orphans_deleted'])->toBe(1)
        ->and($run->processed)->toBe($run->total)
        ->and($run->finished_at)->not->toBeNull();
});

it('schedules reconciliation at the configured time and frequency', function () {
    $event = function () {
        $schedule = new Schedule;
        app(GmcSchedule::class)->define($schedule);

        return collect($schedule->events())->firstWhere('description', 'gmc:reconcile');
    };

    expect($event()->expression)->toBe('0 1 * * *');

    gmcConfig(['settings.reconcile_time' => '02:30', 'settings.reconcile_frequency' => 'twicedaily']);

    expect($event()->expression)->toBe('30 2,14 * * *');
});
