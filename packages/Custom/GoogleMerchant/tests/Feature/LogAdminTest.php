<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcSyncLog;
use Custom\GoogleMerchant\Support\ErrorExplainer;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
    $this->row = GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => 'log-sku-'.uniqid(), 'state' => 'failed']);

    $this->log = fn (array $attrs) => GmcSyncLog::create(array_merge([
        'gmc_product_id' => $this->row->id, 'gmc_target_id' => $this->target->id, 'offer_id' => $this->row->offer_id,
        'operation' => 'upsert', 'status' => 'success', 'attempt' => 1, 'duration_ms' => 120,
    ], $attrs));

    $this->grid = fn (array $query = []) => collect(getJson(route('admin.google_merchant.logs.index', $query), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->json('records'))->where('offer_id', $this->row->offer_id)->values();
});

it('lists sync attempts newest first', function () {
    $this->loginAsAdmin();

    $old = ($this->log)(['status' => 'success']);
    DB::table('gmc_sync_logs')->where('id', $old->id)->update(['created_at' => now()->subHour()]);
    $new = ($this->log)(['status' => 'error', 'error_message' => 'Invalid GTIN value.']);

    get(route('admin.google_merchant.logs.index'))->assertOk()->assertSeeText(trans('google_merchant::app.logs.title'));

    expect(($this->grid)()->pluck('id')->all())->toBe([$new->id, $old->id]);
});

it('filters by status', function () {
    $this->loginAsAdmin();

    ($this->log)(['status' => 'success']);
    ($this->log)(['status' => 'error']);

    expect(($this->grid)(['filters' => ['status' => ['error']]])->pluck('status')->map(fn ($s) => strip_tags($s))->unique()->all())
        ->toBe([trans('google_merchant::app.logs.status.error')]);
});

it('shows one attempt with the redacted response and a readable explanation', function () {
    $this->loginAsAdmin();

    $log = ($this->log)([
        'status'           => 'error',
        'http_status'      => 400,
        'error_message'    => '[productAttributes.gtins] Invalid GTIN value.',
        'response_excerpt' => app(\Custom\GoogleMerchant\Api\ResponseRedactor::class)->redact(json_encode(fixture('error-400-invalid-argument') + ['access_token' => 'ya29.secret'])),
    ]);

    $json = getJson(route('admin.google_merchant.logs.show', $log->id))->assertOk()->json();

    expect($json['response_excerpt'])->not->toContain('ya29.secret')
        ->and($json['explanation']['hint'])->toContain('GTIN');

    get(route('admin.google_merchant.logs.show', $log->id))
        ->assertOk()
        ->assertSeeText($json['explanation']['title'])
        ->assertDontSeeText('ya29.secret');
});

it('explains google errors in admin language', function () {
    $explainer = app(ErrorExplainer::class);

    $gtin = $explainer->explain(new GmcSyncLog([
        'status' => 'error', 'http_status' => 400,
        'response_excerpt' => json_encode(fixture('error-400-invalid-argument')),
        'error_message' => '[productAttributes.gtins] Invalid GTIN value.',
    ]));

    expect($gtin['hint'])->toContain('GTIN');

    expect($explainer->explain(new GmcSyncLog(['status' => 'error', 'http_status' => 429]))['title'])
        ->toBe(trans('google_merchant::app.hints.http.429.title'));

    $unknown = $explainer->explain(new GmcSyncLog(['status' => 'error', 'http_status' => 400, 'error_message' => 'Something new from Google.']));

    expect($unknown['hint'])->toBe('Something new from Google.');

    $invalid = $explainer->explain(new GmcProduct(['state' => 'invalid', 'validation_errors' => ['imageLink' => 'Upload at least one product image.']]));

    expect($invalid['hint'])->toContain('Upload at least one product image.');
});

it('explains failures in the products grid instead of showing raw google text', function () {
    $this->loginAsAdmin();

    $this->row->forceFill(['last_error' => 'Request had invalid authentication credentials. (productAttributes.gtins: Invalid GTIN value.)', 'error_kind' => 'permanent'])->save();

    $record = collect(getJson(route('admin.google_merchant.products.index'), ['X-Requested-With' => 'XMLHttpRequest'])->json('records'))
        ->firstWhere('id', $this->row->id);

    expect($record['last_error'])->toContain(trans('google_merchant::app.hints.fields.gtin.title'));
});

it('protects the log with its own permission', function () {
    $role = \Webkul\User\Models\Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    $this->loginAsAdmin(\Webkul\User\Models\Admin::factory()->create(['role_id' => $role->id]));

    get(route('admin.google_merchant.logs.index'))->assertStatus(401);
});
