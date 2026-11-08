<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Support\StoreSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates all gmc tables with the unique offer key', function () {
    foreach (['gmc_accounts', 'gmc_targets', 'gmc_attribute_mappings', 'gmc_category_mappings', 'gmc_products', 'gmc_sync_logs', 'gmc_runs'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing $table");
    }

    expect(Schema::hasColumns('gmc_products', ['root_product_id', 'error_kind', 'retry_cycles', 'revision', 'synced_revision']))->toBeTrue();

    $target = gmcTarget(gmcAccount());

    GmcProduct::create(['gmc_target_id' => $target->id, 'offer_id' => 'sku-1', 'state' => 'pending']);

    GmcProduct::create(['gmc_target_id' => $target->id, 'offer_id' => 'sku-1', 'state' => 'pending']);
})->throws(UniqueConstraintViolationException::class);

it('encrypts tokens at rest', function () {
    $account = gmcAccount(['refresh_token' => 'abc', 'access_token' => 'live-token']);

    $raw = DB::table('gmc_accounts')->where('id', $account->id)->first();

    expect($raw->refresh_token)->not->toBe('abc')
        ->and($raw->access_token)->not->toBe('live-token')
        ->and(GmcAccount::find($account->id)->refresh_token)->toBe('abc')
        ->and($account->toArray())->not->toHaveKeys(['access_token', 'refresh_token', 'service_account_json']);
});

it('rejects non-public store urls', function (string $url, bool $expected) {
    expect(app(StoreSettings::class)->isPublicHttps($url))->toBe($expected);
})->with([
    ['http://localhost', false],
    ['https://localhost', false],
    ['https://shop.test', false],
    ['https://shop.local/x', false],
    ['https://192.168.1.4', false],
    ['https://127.0.0.1', false],
    ['http://example.com', false],
    ['https://example.com', true],
    ['https://shop.example.com/p/shirt?variant=a', true],
]);

it('registers menu and acl', function () {
    expect(collect(config('menu.admin'))->pluck('key'))->toContain('marketing.google_merchant')
        ->and(collect(config('acl'))->pluck('key'))->toContain('marketing.google_merchant.products.sync');
});

it('reads settings defaults from the admin configuration', function () {
    expect((int) core()->getConfigData('google_merchant.general.settings.sync_delay'))->toBe(30);

    gmcConfig(['settings.sync_delay' => 45]);

    expect((int) core()->getConfigData('google_merchant.general.settings.sync_delay'))->toBe(45);
});
