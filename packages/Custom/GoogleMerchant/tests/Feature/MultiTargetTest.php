<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/** EUR as a real channel currency at 1 USD = 0.9 EUR. */
function addEuro(float $rate = 0.9): void
{
    $id = DB::table('currencies')->where('code', 'EUR')->value('id')
        ?? DB::table('currencies')->insertGetId(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal' => 2, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('currency_exchange_rates')->updateOrInsert(['target_currency' => $id], ['rate' => $rate, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('channel_currencies')->updateOrInsert(['channel_id' => core()->getDefaultChannel()->id, 'currency_id' => $id]);
}

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    addEuro();

    $this->account = gmcAccount();
    $this->us = gmcTarget($this->account);
    $this->de = gmcTarget($this->account, [
        'feed_label' => 'DE', 'countries' => ['DE'], 'currency' => 'EUR', 'data_source_name' => 'accounts/123456/dataSources/1000',
    ]);

    $this->inserts = [];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'productInputs:insert')) {
            $this->inserts[] = $request->data();
        }

        return match (true) {
            str_contains($request->url(), '/dataSources?') => Http::response(['dataSources' => []]),
            $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/dataSources') => Http::response(['name' => 'accounts/123456/dataSources/777', 'input' => 'API']),
            default => Http::response(fixture('productinput-insert')),
        };
    });

    $this->product = simpleProduct();
    setProductAttribute($this->product, 'price', 100);
    forgetGmcSideEffects([$this->product->id]);
    $this->inserts = [];
});

it('sends one product to every enabled market with its own feed label and currency', function () {
    Queue::fake();

    app(SyncDispatcher::class)->queue([$this->product->id], 'manual', 0);

    $jobs = Queue::pushed(SyncProductJob::class);

    expect($jobs->pluck('targetId')->sort()->values()->all())->toBe(collect([$this->us->id, $this->de->id])->sort()->values()->all());

    $jobs->each(fn ($job) => $job->handle(app(SyncService::class), app(SyncDispatcher::class)));

    expect(GmcProduct::where('product_id', $this->product->id)->pluck('gmc_target_id')->sort()->values()->all())
        ->toBe(collect([$this->us->id, $this->de->id])->sort()->values()->all());

    $byLabel = collect($this->inserts)->keyBy('feedLabel');

    expect($byLabel->keys()->sort()->values()->all())->toBe(['DE', 'US'])
        ->and($byLabel['US']['productAttributes']['price'])->toBe(['amountMicros' => '100000000', 'currencyCode' => 'USD'])
        ->and($byLabel['DE']['productAttributes']['price'])->toBe(['amountMicros' => '90000000', 'currencyCode' => 'EUR']);
});

it('stops dispatching to a disabled market but keeps its ledger', function () {
    app(SyncService::class)->syncRoot($this->product->id, $this->de->id);

    $this->de->forceFill(['enabled' => false])->save();

    Queue::fake();

    app(SyncDispatcher::class)->queue([$this->product->id], 'manual', 0);

    expect(Queue::pushed(SyncProductJob::class)->pluck('targetId')->all())->toBe([$this->us->id])
        ->and(GmcProduct::where('gmc_target_id', $this->de->id)->exists())->toBeTrue();
});

it('creates a market with a data source and a first full sync', function () {
    Queue::fake();
    $this->loginAsAdmin();

    $this->de->delete();

    post(route('admin.google_merchant.targets.store'), [
        'locale' => 'en',
        'currency' => 'EUR',
        'countries' => 'de, at',
    ])->assertRedirect(route('admin.google_merchant.targets.index'));

    $target = GmcTarget::where('feed_label', 'DE')->first();

    expect($target)->not->toBeNull()
        ->and($target->countries)->toBe(['DE', 'AT'])
        ->and($target->content_language)->toBe('en')
        ->and($target->currency)->toBe('EUR')
        ->and($target->data_source_name)->toBe('accounts/123456/dataSources/777')
        ->and(GmcRun::where('gmc_target_id', $target->id)->where('type', 'bulk')->exists())->toBeTrue();
});

it('refuses a currency the channel does not sell in, and bad country codes', function () {
    $this->loginAsAdmin();

    post(route('admin.google_merchant.targets.store'), ['locale' => 'en', 'currency' => 'GBP', 'countries' => 'GB'])
        ->assertSessionHasErrors('currency');

    post(route('admin.google_merchant.targets.store'), ['locale' => 'en', 'currency' => 'EUR', 'countries' => 'Germany'])
        ->assertSessionHasErrors('countries');

    post(route('admin.google_merchant.targets.store'), ['locale' => 'fr', 'currency' => 'EUR', 'countries' => 'FR'])
        ->assertSessionHasErrors('locale');
});

it('refuses a currency without an exchange rate', function () {
    DB::table('currency_exchange_rates')->delete();

    $this->loginAsAdmin();

    post(route('admin.google_merchant.targets.store'), ['locale' => 'en', 'currency' => 'EUR', 'countries' => 'FR'])
        ->assertSessionHasErrors('currency');
});

it('pauses and resumes a market', function () {
    $this->loginAsAdmin();

    put(route('admin.google_merchant.targets.update', $this->de->id), ['enabled' => 0])->assertRedirect();
    expect($this->de->fresh()->enabled)->toBeFalse();

    put(route('admin.google_merchant.targets.update', $this->de->id), ['enabled' => 1])->assertRedirect();
    expect($this->de->fresh()->enabled)->toBeTrue();
});

it('removes a market by deleting its offers from google first', function () {
    Queue::fake();
    $this->loginAsAdmin();

    delete(route('admin.google_merchant.targets.destroy', $this->de->id))->assertRedirect();

    expect($this->de->fresh()->enabled)->toBeFalse()
        ->and(GmcRun::where('gmc_target_id', $this->de->id)->where('type', 'delete_all')->exists())->toBeTrue();
});

it('lists markets and shows each market\'s figures on the overview', function () {
    $this->loginAsAdmin();

    get(route('admin.google_merchant.targets.index'))->assertOk()->assertSeeText('DE')->assertSeeText('EUR');

    get(route('admin.google_merchant.overview', ['target' => $this->de->id]))
        ->assertOk()
        ->assertViewHas('target', fn ($t) => $t->id === $this->de->id);

    get(route('admin.google_merchant.diagnostics.index', ['target' => $this->de->id]))
        ->assertOk()
        ->assertViewHas('target', fn ($t) => $t->id === $this->de->id);
});

it('protects market management', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['marketing.google_merchant.overview']]);
    $this->loginAsAdmin(Admin::factory()->create(['role_id' => $role->id]));

    get(route('admin.google_merchant.targets.index'))->assertStatus(401);
    post(route('admin.google_merchant.targets.store'), ['locale' => 'en', 'currency' => 'EUR', 'countries' => 'FR'])->assertStatus(401);
});
