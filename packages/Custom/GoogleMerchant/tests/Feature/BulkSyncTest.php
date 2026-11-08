<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\BulkSyncChunkJob;
use Custom\GoogleMerchant\Jobs\StartBulkRunJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Services\BulkSyncService;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->start = fn (array $filters = [], string $mode = 'incremental') => app(BulkSyncService::class)->start($filters, $mode, null)[0];

    /** Runs every queued bulk job until none is left (what the worker does). */
    $this->drain = function () {
        $done = [];

        while ($job = collect(Queue::pushed(StartBulkRunJob::class))->merge(Queue::pushed(BulkSyncChunkJob::class))
            ->first(fn ($j) => ! in_array(spl_object_id($j), $done, true))) {
            $done[] = spl_object_id($job);

            $job instanceof StartBulkRunJob ? $job->handle() : $job->handle(app(SyncService::class));
        }
    };

    $this->bareProducts = function (int $count): array {
        $now = now();

        $rows = array_map(fn ($i) => [
            'sku' => 'bulk-'.uniqid().'-'.$i, 'type' => 'simple', 'attribute_family_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, $count));

        DB::table('products')->insert($rows);

        return DB::table('products')->whereIn('sku', array_column($rows, 'sku'))->orderBy('id')->pluck('id')->all();
    };
});

it('chunks 250 products into 5 chunk jobs of 50', function () {
    $ids = ($this->bareProducts)(250);
    Queue::fake();

    $run = ($this->start)(['product_ids' => $ids]);

    Queue::pushed(StartBulkRunJob::class)->first()->handle();

    $chunks = Queue::pushed(BulkSyncChunkJob::class);

    expect($run->fresh()->total)->toBe(250)
        ->and($chunks)->toHaveCount(5)
        ->and($chunks->map(fn ($c) => count($c->productIds))->unique()->all())->toBe([50])
        ->and($run->fresh()->cursor)->toBe(end($ids));
});

it('filters by category, status and date', function () {
    $ids = ($this->bareProducts)(5);
    Queue::fake();

    $categoryId = DB::table('categories')->whereNotNull('parent_id')->value('id');
    DB::table('product_categories')->insert(array_map(fn ($id) => ['product_id' => $id, 'category_id' => $categoryId], array_slice($ids, 0, 3)));

    expect(($this->start)(['product_ids' => $ids, 'category_ids' => [$categoryId]])->total)->toBe(3);

    DB::table('products')->whereIn('id', array_slice($ids, 0, 2))->update(['updated_at' => now()->subYear()]);

    expect(($this->start)(['product_ids' => $ids, 'updated_from' => now()->subMonth()->toDateString()])->total)->toBe(3);

    $enabled = simpleProduct();
    $disabled = simpleProduct(['status' => 0]);

    expect(($this->start)(['product_ids' => [$enabled->id, $disabled->id], 'product_status' => 'disabled'])->total)->toBe(1);
});

it('incremental skips unchanged products, full resubmits them', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('productinput-insert'))]);
    $ids = [simpleProduct()->id, simpleProduct()->id];
    $inserts = fn () => collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), ':insert'))->count();

    Queue::fake();
    ($this->start)(['product_ids' => $ids], 'full');
    ($this->drain)();
    expect($inserts())->toBe(2);

    Queue::fake();
    $run = ($this->start)(['product_ids' => $ids], 'incremental');
    ($this->drain)();
    expect($inserts())->toBe(2)
        ->and($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->succeeded)->toBe(2);

    Queue::fake();
    ($this->start)(['product_ids' => $ids], 'full');
    ($this->drain)();
    expect($inserts())->toBe(4);
});

it('resumes a chunk after 429 without double counting', function () {
    $ids = [simpleProduct()->id, simpleProduct()->id, simpleProduct()->id];

    Http::fake(['merchantapi.googleapis.com/*' => Http::sequence()
        ->push(fixture('productinput-insert'))
        ->push(fixture('error-429'), 429, ['Retry-After' => '30'])
        ->whenEmpty(Http::response(fixture('productinput-insert')))]);

    Queue::fake();
    $run = ($this->start)(['product_ids' => $ids], 'full');

    Queue::pushed(StartBulkRunJob::class)->first()->handle();
    Queue::pushed(BulkSyncChunkJob::class)->first()->handle(app(SyncService::class));

    $retry = Queue::pushed(BulkSyncChunkJob::class)->last();

    expect($retry->productIds)->toBe([$ids[1], $ids[2]])
        ->and($retry->delay)->toBeGreaterThanOrEqual(30)
        ->and($run->fresh()->processed)->toBe(1);

    $retry->handle(app(SyncService::class));

    $run->refresh();

    expect($run->processed)->toBe(3)
        ->and($run->succeeded)->toBe(3)
        ->and($run->failed)->toBe(0)
        ->and($run->status)->toBe('completed');
});

it('counts invalid products as failed in the run', function () {
    Http::fake();
    $product = simpleProduct();
    DB::table('product_images')->where('product_id', $product->id)->delete();

    Queue::fake();
    $run = ($this->start)(['product_ids' => [$product->id]], 'full');
    ($this->drain)();

    expect($run->fresh()->failed)->toBe(1)->and($run->fresh()->status)->toBe('completed');
});

it('pauses and resumes from the cursor', function () {
    $ids = ($this->bareProducts)(3);
    Queue::fake();

    $run = ($this->start)(['product_ids' => $ids]);
    app(BulkSyncService::class)->pause($run);

    Queue::pushed(StartBulkRunJob::class)->first()->handle();

    expect(Queue::pushed(BulkSyncChunkJob::class))->toHaveCount(0)
        ->and($run->fresh()->status)->toBe('paused');

    app(BulkSyncService::class)->resume($run->fresh());

    expect($run->fresh()->status)->toBe('running');

    Queue::pushed(StartBulkRunJob::class)->last()->handle();

    expect(Queue::pushed(BulkSyncChunkJob::class))->toHaveCount(1);
});

it('removes every synced offer from google with a delete-all run', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response('')]);
    $product = simpleProduct();

    $row = GmcProduct::create([
        'gmc_target_id' => $this->target->id, 'offer_id' => $product->sku, 'product_id' => $product->id, 'root_product_id' => $product->id,
        'state' => 'synced', 'last_synced_at' => now(), 'product_input_name' => 'accounts/123456/productInputs/abc',
    ]);

    Queue::fake();
    $run = app(BulkSyncService::class)->startDeleteAll($this->target->account, null)[0];
    ($this->drain)();

    expect($row->fresh()->state)->toBe('deleted')
        ->and($run->fresh()->status)->toBe('completed');

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'productInputs/abc'));
});

it('starts a run from the admin and reports progress as json', function () {
    $this->loginAsAdmin();
    $ids = ($this->bareProducts)(2);
    Queue::fake();

    post(route('admin.google_merchant.runs.store'), ['mode' => 'full', 'product_ids' => implode(',', $ids)])
        ->assertRedirect(route('admin.google_merchant.runs.index'));

    $run = GmcRun::latest('id')->first();

    expect($run->options['mode'])->toBe('full')
        ->and(DB::table('google_audit_logs')->where('action', 'bulk_sync')->exists())->toBeTrue();

    getJson(route('admin.google_merchant.runs.show', $run->id))
        ->assertOk()
        ->assertJson(['total' => 2, 'processed' => 0, 'pending' => 2, 'status' => 'running']);

    postJson(route('admin.google_merchant.runs.pause', $run->id))->assertOk();
    expect($run->fresh()->status)->toBe('paused');
});

it('rejects an unknown sync mode', function () {
    $this->loginAsAdmin();

    post(route('admin.google_merchant.runs.store'), ['mode' => 'everything'])->assertSessionHasErrors('mode');
});

it('starts a bulk run from the command line', function () {
    Queue::fake();

    Artisan::call('gmc:sync', ['--all' => true]);

    expect(GmcRun::where('type', 'bulk')->latest('id')->first()?->options['mode'])->toBe('incremental');

    Queue::assertPushed(StartBulkRunJob::class);
});

it('renders the bulk sync page', function () {
    $this->loginAsAdmin();

    \Pest\Laravel\get(route('admin.google_merchant.runs.index'))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.runs.start'))
        ->assertSeeText(trans('google_merchant::app.runs.delete-all'));
});
