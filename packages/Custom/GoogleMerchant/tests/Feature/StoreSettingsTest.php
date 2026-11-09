<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\StartBulkRunJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Services\UrlProbe;
use Custom\GoogleMerchant\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\get;
use function Pest\Laravel\put;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1]);

    $this->target = gmcTarget(gmcAccount());

    $this->valid = fn (array $overrides = []) => array_merge([
        'website_url'       => 'https://shop.example.com',
        'country'           => 'US',
        'language'          => 'en',
        'currency'          => 'USD',
        'return_policy_url' => 'https://shop.example.com/page/return-policy',
        'refund_policy_url' => 'https://shop.example.com/page/refund-policy',
        'terms_url'         => 'https://shop.example.com/page/terms-conditions',
        'privacy_url'       => 'https://shop.example.com/page/privacy-policy',
        'shipping_note'     => 'Flat rate in Merchant Center',
        'tax_note'          => '',
    ], $overrides);

    Http::fake(['shop.example.com*' => Http::response('ok')]);
});

it('shows the store settings page', function () {
    $this->loginAsAdmin();

    get(route('admin.google_merchant.store.edit'))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.store.title'));
});

it('saves the store settings and reports reachable urls', function () {
    $this->loginAsAdmin();

    put(route('admin.google_merchant.store.update'), ($this->valid)())
        ->assertRedirect(route('admin.google_merchant.store.edit'))
        ->assertSessionHas('gmc_url_report');

    $settings = app(Settings::class);

    expect($settings->get('store.website_url'))->toBe('https://shop.example.com')
        ->and($settings->get('store.return_policy_url'))->toBe('https://shop.example.com/page/return-policy')
        ->and(collect(session('gmc_url_report'))->every(fn ($r) => $r['ok']))->toBeTrue()
        ->and(DB::table('google_audit_logs')->where('action', 'store_settings')->exists())->toBeTrue();
});

it('rejects a currency or language the channel does not offer', function () {
    $this->loginAsAdmin();

    put(route('admin.google_merchant.store.update'), ($this->valid)(['currency' => 'EUR', 'language' => 'fr']))
        ->assertSessionHasErrors(['currency', 'language']);

    $this->putJson(route('admin.google_merchant.store.update'), ($this->valid)(['currency' => 'EUR']))->assertStatus(422);
});

it('requires https policy and website urls', function () {
    $this->loginAsAdmin();

    put(route('admin.google_merchant.store.update'), ($this->valid)(['return_policy_url' => 'http://shop.example.com/page/return-policy']))
        ->assertSessionHasErrors(['return_policy_url' => trans('google_merchant::app.store.must-be-https')]);
});

it('probes urls', function () {
    Http::fake([
        'a.example.com/missing'  => Http::response('', 404),
        'a.example.com/ok'       => Http::response('', 200),
        'a.example.com/moved'    => Http::response('', 301, ['Location' => 'https://a.example.com/ok']),
        'a.example.com/away'     => Http::response('', 301, ['Location' => 'https://elsewhere.example.org/']),
        'a.example.com/no-head'  => Http::sequence()->push('', 405)->push('fine', 200),
        'down.example.com/*'     => Http::failedConnection(),
    ]);

    $probe = app(UrlProbe::class);

    expect($probe->check('https://a.example.com/missing'))->toMatchArray(['ok' => false, 'status' => 404])
        ->and($probe->check('https://a.example.com/ok')['ok'])->toBeTrue()
        ->and($probe->check('https://a.example.com/moved')['ok'])->toBeTrue()
        ->and($probe->check('https://a.example.com/away')['ok'])->toBeFalse()
        ->and($probe->check('https://a.example.com/no-head')['ok'])->toBeTrue()
        ->and($probe->check('https://down.example.com/x'))->toMatchArray(['ok' => false, 'status' => null]);
});

it('asks for confirmation before moving synced offers to another market', function () {
    $this->loginAsAdmin();
    GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => 'x-'.uniqid(), 'state' => 'synced', 'last_synced_at' => now(), 'product_input_name' => 'accounts/123456/productInputs/x']);

    put(route('admin.google_merchant.store.update'), ($this->valid)(['country' => 'DE']))
        ->assertSessionHasErrors('confirm_resync');

    expect($this->target->fresh()->countries)->toBe(['US']);
});

it('moves to a new market: new data source, old offers removed, full sync started', function () {
    $this->loginAsAdmin();
    GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => 'x-'.uniqid(), 'state' => 'synced', 'last_synced_at' => now(), 'product_input_name' => 'accounts/123456/productInputs/x']);

    Http::fake([
        'merchantapi.googleapis.com/datasources/v1/accounts/123456/dataSources?*' => Http::response(['dataSources' => []]),
        'merchantapi.googleapis.com/datasources/v1/accounts/123456/dataSources'   => Http::response(['name' => 'accounts/123456/dataSources/777', 'input' => 'API']),
    ]);
    Queue::fake();

    put(route('admin.google_merchant.store.update'), ($this->valid)(['country' => 'DE', 'confirm_resync' => '1']))
        ->assertRedirect(route('admin.google_merchant.store.edit'));

    $new = GmcTarget::where('feed_label', 'DE')->first();

    expect($this->target->fresh()->enabled)->toBeFalse()
        ->and($new->countries)->toBe(['DE'])
        ->and($new->data_source_name)->toBe('accounts/123456/dataSources/777')
        ->and(GmcRun::where('type', 'delete_all')->where('gmc_target_id', $this->target->id)->exists())->toBeTrue()
        ->and(GmcRun::where('type', 'bulk')->where('gmc_target_id', $new->id)->value('options')['mode'])->toBe('full');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/dataSources') && $r['primaryProductDataSource']['feedLabel'] === 'DE');
    Queue::assertPushed(StartBulkRunJob::class, 2);
});
