<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Services\DashboardStats;

use function Pest\Laravel\get;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->rows = function (string $state, int $count, array $extra = []) {
        foreach (range(1, $count) as $i) {
            GmcProduct::create(array_merge(['gmc_target_id' => $this->target->id, 'offer_id' => "$state-$i-".uniqid(), 'state' => $state], $extra));
        }
    };
});

it('counts ledger states and google verdicts', function () {
    ($this->rows)('synced', 2, ['last_synced_at' => now()->subHour(), 'google_status' => 'approved']);
    ($this->rows)('failed', 1);
    ($this->rows)('invalid', 3);

    $stats = app(DashboardStats::class)->forTarget($this->target);

    expect($stats['synced'])->toBe(2)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['invalid'])->toBe(3)
        ->and($stats['pending'])->toBe(0)
        ->and($stats['approved'])->toBe(2)
        ->and($stats['last_success_at']->diffInMinutes(now()))->toBeGreaterThanOrEqual(59);
});

it('shows the overview with merchant id and counters', function () {
    $this->loginAsAdmin();
    ($this->rows)('synced', 2, ['last_synced_at' => now()]);

    get(route('admin.google_merchant.overview'))
        ->assertOk()
        ->assertSeeText('123456')
        ->assertSeeText(trans('google_merchant::app.overview.synced'))
        ->assertSeeText(trans('google_merchant::app.overview.submitted-note'));
});

it('warns when the account needs reconnecting', function () {
    $this->loginAsAdmin();
    $this->target->account->forceFill(['status' => 'needs_reauth'])->save();

    get(route('admin.google_merchant.overview'))->assertSeeText(trans('google_merchant::app.banner.reauth'));
});

it('points to the connection page when nothing is connected', function () {
    $this->loginAsAdmin();
    $this->target->account->delete();

    get(route('admin.google_merchant.overview'))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.overview.not-connected'));
});
