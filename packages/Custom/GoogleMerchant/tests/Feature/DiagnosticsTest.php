<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Diagnostics\AccountIssues;
use Custom\GoogleMerchant\Jobs\RefreshStatusesJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1]);

    $this->account = gmcAccount();
    $this->target = gmcTarget($this->account);

    $issue = fn (string $code, string $attribute, string $description) => [
        'code' => $code, 'severity' => 'DISAPPROVED', 'attribute' => $attribute, 'description' => $description,
        'detail' => null, 'documentation' => 'https://support.google.com/merchants', 'resolution' => 'merchant_action',
    ];

    $this->row = fn (string $offerId, array $attrs) => GmcProduct::create(array_merge(
        ['gmc_target_id' => $this->target->id, 'offer_id' => $offerId, 'state' => 'synced'],
        $attrs
    ));

    ($this->row)('IMG-1', ['google_status' => 'disapproved', 'google_issues' => [$issue('image_too_small', 'image_link', 'Image too small')]]);
    ($this->row)('IMG-2', ['google_status' => 'disapproved', 'google_issues' => [$issue('image_too_small', 'image_link', 'Image too small')]]);
    ($this->row)('GTIN-1', ['google_status' => 'partial', 'google_issues' => [$issue('missing_gtin', 'gtin', 'Missing value [gtin]')]]);
    ($this->row)('GTIN-2', ['google_status' => 'partial', 'google_issues' => [$issue('invalid_upc', 'gtin', 'Invalid UPC value')]]);
    ($this->row)('PRICE-1', ['google_status' => 'disapproved', 'google_issues' => [$issue('mismatched_price', 'price', 'Mismatched value (page crawl) [price]')]]);
    ($this->row)('POLICY-1', ['google_status' => 'disapproved', 'google_issues' => [$issue('policy_violation', '', 'Violation of Shopping ads policy')]]);
    ($this->row)('PENDING-1', ['google_status' => 'pending', 'google_issues' => []]);
    ($this->row)('APPROVED-1', ['google_status' => 'approved', 'google_issues' => []]);
    ($this->row)('BRAND-1', ['state' => 'invalid', 'validation_errors' => ['brand' => 'The product has no brand.']]);
    ($this->row)('AWAIT-1', ['google_status' => null]);

    $this->googleDown = false;

    Http::fake(function (Request $request) {
        if ($this->googleDown) {
            return Http::response(fixture('error-503'), 503);
        }

        return match (true) {
            str_contains($request->url(), '/accounts/v1/accounts/123456/issues') => Http::response(fixture('account-issues')),
            str_contains($request->url(), '/quota/v1/accounts/123456/quotas') => Http::response(fixture('quotas-list')),
            default => Http::response([], 404),
        };
    });

    Cache::flush();
});

it('groups product issues by type with counts, google\'s text, a hint and a grid link', function () {
    $this->loginAsAdmin();

    $response = get(route('admin.google_merchant.diagnostics.index'))->assertOk();

    $groups = $response->viewData('groups');

    expect($groups['image'][0])->toMatchArray(['code' => 'image_too_small', 'count' => 2, 'description' => 'Image too small'])
        ->and($groups['image'][0]['hint'])->toContain('Images')
        ->and($groups['image'][0]['link'])->toContain('image_too_small')
        ->and($groups['identifier'][0])->toMatchArray(['code' => 'invalid_upc', 'count' => 1])
        ->and(collect($groups['missing'])->pluck('code')->all())->toContain('missing_gtin', 'store:brand')
        ->and(collect($groups)->flatten(1)->where('code', 'missing_gtin'))->toHaveCount(1)
        ->and($groups['price'][0])->toMatchArray(['code' => 'mismatched_price', 'count' => 1])
        ->and($groups['policy'][0])->toMatchArray(['code' => 'policy_violation', 'count' => 1]);

    $response->assertSeeText('Image too small')
        ->assertSeeText('The product has no brand.')
        ->assertSeeText(trans('google_merchant::app.diagnostics.groups.image'));
});

it('counts google statuses and keeps submitted-but-unreviewed products out of approved', function () {
    $this->loginAsAdmin();

    $summary = get(route('admin.google_merchant.diagnostics.index'))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.google-status.awaiting'))
        ->viewData('summary');

    expect($summary)->toMatchArray([
        'approved' => 1,
        'disapproved' => 4,
        'partial' => 2,
        'pending' => 1,
        'awaiting' => 1,
    ]);
});

it('shows account-level issues from google', function () {
    $this->loginAsAdmin();

    get(route('admin.google_merchant.diagnostics.index'))
        ->assertOk()
        ->assertSeeText('Missing return policy')
        ->assertSeeText('Add a return policy for the United States in Merchant Center.');
});

it('shows quota usage', function () {
    $this->loginAsAdmin();

    get(route('admin.google_merchant.diagnostics.index'))
        ->assertOk()
        ->assertSeeText('products.insert')
        ->assertSeeText('4,200')
        ->assertSeeText('10,000');
});

it('caches account issues for six hours', function () {
    AccountIssues::get($this->account);
    AccountIssues::get($this->account);

    Http::assertSentCount(1);

    $this->travel(6)->hours();
    $this->travel(1)->minutes();

    AccountIssues::get($this->account);

    Http::assertSentCount(2);
});

it('still renders when google cannot be reached', function () {
    $this->googleDown = true;

    $this->loginAsAdmin();

    get(route('admin.google_merchant.diagnostics.index'))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.diagnostics.google-unavailable'));
});

it('refreshes statuses and clears the cached google data', function () {
    Queue::fake();

    AccountIssues::get($this->account);

    $this->loginAsAdmin();

    post(route('admin.google_merchant.diagnostics.refresh'))->assertRedirect(route('admin.google_merchant.diagnostics.index'));

    Queue::assertPushed(RefreshStatusesJob::class, fn ($job) => $job->targetId === $this->target->id);

    expect(Cache::has("gmc:account-issues:{$this->account->id}"))->toBeFalse();
});

it('protects diagnostics and the refresh with their own permissions', function () {
    Queue::fake();

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    $this->loginAsAdmin(Admin::factory()->create(['role_id' => $role->id]));

    get(route('admin.google_merchant.diagnostics.index'))->assertStatus(401);
    post(route('admin.google_merchant.diagnostics.refresh'))->assertStatus(401);

    Queue::assertNotPushed(RefreshStatusesJob::class);
});

it('filters the products grid by a google issue code from the diagnostics link', function () {
    $this->loginAsAdmin();

    $records = \Pest\Laravel\getJson(route('admin.google_merchant.products.index', ['issue' => 'image_too_small']), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->json('records');

    expect(collect($records)->pluck('offer_id')->sort()->values()->all())->toBe(['IMG-1', 'IMG-2']);

    get(route('admin.google_merchant.products.index', ['issue' => 'image_too_small']))
        ->assertOk()
        ->assertSee('issue=image_too_small', false);
});
