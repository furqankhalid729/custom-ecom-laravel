<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->ledger = fn ($product, array $attrs = []) => GmcProduct::create(array_merge([
        'gmc_target_id' => $this->target->id, 'offer_id' => $product->sku, 'product_id' => $product->id,
        'root_product_id' => $product->id, 'state' => 'failed', 'last_error' => 'Invalid GTIN value.', 'retry_cycles' => 3,
    ], $attrs));

    $this->gmcSync = fn ($product) => DB::table('product_attribute_values')
        ->where('product_id', $product->id)
        ->where('attribute_id', DB::table('attributes')->where('code', 'gmc_sync')->value('id'))
        ->value('boolean_value');
});

it('renders the products page and grid data', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    ($this->ledger)($product);

    get(route('admin.google_merchant.products.index'))->assertOk()->assertSeeText(trans('google_merchant::app.products.title'));

    $json = getJson(route('admin.google_merchant.products.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json();

    $record = collect($json['records'])->firstWhere('offer_id', $product->sku);

    expect($record)->not->toBeNull()
        ->and($record['product_name'])->toBe('Arctic Cozy Knit Beanie')
        ->and(strip_tags($record['state']))->toBe(trans('google_merchant::app.states.failed'));
});

it('forces a sync of one offer', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    $row = ($this->ledger)($product);
    Queue::fake();

    postJson(route('admin.google_merchant.products.sync', $row->id))->assertOk()->assertJsonStructure(['message']);

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id && $job->force);
});

it('retries a failed offer and resets its retry budget', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    $row = ($this->ledger)($product);
    Queue::fake();

    postJson(route('admin.google_merchant.products.retry', $row->id))->assertOk();

    expect($row->fresh()->retry_cycles)->toBe(0);

    Queue::assertPushed(SyncProductJob::class, 1);
});

it('excludes and includes through the product attribute and audits it', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    $row = ($this->ledger)($product);
    Queue::fake();

    postJson(route('admin.google_merchant.products.exclude', $row->id))->assertOk();

    expect(($this->gmcSync)($product))->toEqual(0)
        ->and(DB::table('google_audit_logs')->where('action', 'exclude')->exists())->toBeTrue();

    Queue::assertPushed(SyncProductJob::class);

    postJson(route('admin.google_merchant.products.include', $row->id))->assertOk();

    expect(($this->gmcSync)($product))->toEqual(1);
});

it('removes an offer from google by excluding it', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    $row = ($this->ledger)($product);
    Queue::fake();

    deleteJson(route('admin.google_merchant.products.remove', $row->id))->assertOk();

    expect(($this->gmcSync)($product))->toEqual(0);
});

it('mass retries the selected offers', function () {
    $this->loginAsAdmin();
    $rows = [($this->ledger)(simpleProduct()), ($this->ledger)(simpleProduct())];
    Queue::fake();

    postJson(route('admin.google_merchant.products.mass_retry'), ['indices' => [$rows[0]->id, $rows[1]->id]])->assertOk();

    Queue::assertPushed(SyncProductJob::class, 2);
});

it('syncs from the product edit page', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    Queue::fake();

    post(route('admin.google_merchant.product.sync', $product->id))->assertRedirect();

    Queue::assertPushed(SyncProductJob::class, fn ($job) => $job->rootProductId === $product->id && $job->force);
});

it('shows the sync panel on the product edit page', function () {
    $this->loginAsAdmin();
    $product = simpleProduct();
    ($this->ledger)($product);

    get(route('admin.catalog.products.edit', $product->id))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.panel.title'))
        ->assertSeeText('Invalid GTIN value.');
});

it('blocks admins without the sync permission', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['marketing.google_merchant.products']]);
    $this->loginAsAdmin(Admin::factory()->create(['role_id' => $role->id]));
    $row = ($this->ledger)(simpleProduct());
    Queue::fake();

    postJson(route('admin.google_merchant.products.sync', $row->id))->assertStatus(401);

    Queue::assertNothingPushed();
});
