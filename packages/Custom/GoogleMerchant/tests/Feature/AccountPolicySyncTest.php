<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\PushAccountPoliciesJob;
use Custom\GoogleMerchant\Services\AccountPolicySync;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\post;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com', 'store.return_policy_url' => 'https://shop.example.com/returns']);

    $this->account = gmcAccount();
    $this->target = gmcTarget($this->account); // US / en / USD

    $this->channelId = core()->getDefaultChannel()->id;

    Queue::fake(); // saving policy settings dispatches the push job
    $this->policies = app(PolicySettingRepository::class);

    $settings = $this->policies->forChannel($this->channelId)->toSettingsArray();
    $settings['regions']['US']['shipping'] = array_merge($settings['regions']['US']['shipping'], [
        'handling_min_days' => 1,
        'handling_max_days' => 3,
        'methods'           => [['name' => 'Standard', 'carrier' => 'USPS', 'cost' => 5.99, 'free_over' => 50.0, 'min_days' => 3, 'max_days' => 5]],
    ]);
    $settings['regions']['US']['returns'] = array_merge($settings['regions']['US']['returns'], [
        'accepted'                => true,
        'window_days'             => 30,
        'method'                  => 'mail',
        'return_shipping_paid_by' => 'customer',
        'restocking_fee_percent'  => 10,
        'refund_days'             => 10,
        'exchanges'               => true,
    ]);
    $this->policies->saveForChannel($this->channelId, $settings);

    $this->sync = app(AccountPolicySync::class);

    // The job is unique per account: release the lock taken by the save above.
    $this->releasePushLock = fn () => \Illuminate\Support\Facades\Cache::lock('laravel_unique_job:'.PushAccountPoliciesJob::class.':'.$this->account->id)->forceRelease();
    ($this->releasePushLock)();

    $this->requests = [];

    Http::fake(function (Request $request) {
        $this->requests[] = [$request->method(), $request->url(), $request->data()];

        return match (true) {
            $request->method() === 'GET' && str_contains($request->url(), '/shippingSettings')       => Http::response(fixture('shipping-settings-get')),
            $request->method() === 'GET' && str_contains($request->url(), '/onlineReturnPolicies')   => Http::response(fixture('return-policies-list')),
            default                                                                                   => Http::response([]),
        };
    });
});

it('builds a shipping service per method for the target market', function () {
    expect($this->sync->shippingBody($this->target))->toBe([
        'services' => [[
            'serviceName'       => 'Standard',
            'active'            => true,
            'deliveryCountries' => ['US'],
            'currencyCode'      => 'USD',
            'deliveryTime'      => ['minHandlingDays' => 1, 'maxHandlingDays' => 3, 'minTransitDays' => 3, 'maxTransitDays' => 5],
            'rateGroups'        => [[
                'applicableShippingLabels' => [],
                'mainTable'                => [
                    'rowHeaders' => ['prices' => [
                        ['amountMicros' => '50000000', 'currencyCode' => 'USD'],
                        ['amountMicros' => '-1', 'currencyCode' => 'USD'],
                    ]],
                    'rows' => [
                        ['cells' => [['flatRate' => ['amountMicros' => '5990000', 'currencyCode' => 'USD']]]],
                        ['cells' => [['flatRate' => ['amountMicros' => '0', 'currencyCode' => 'USD']]]],
                    ],
                ],
            ]],
        ]],
    ]);
});

it('uses a single flat rate when there is no free-shipping threshold', function () {
    $settings = $this->policies->forChannel($this->channelId)->toSettingsArray();
    $settings['regions']['US']['shipping']['methods'][0]['free_over'] = null;
    $this->policies->saveForChannel($this->channelId, $settings);

    expect($this->sync->shippingBody($this->target)['services'][0]['rateGroups'])->toBe([[
        'applicableShippingLabels' => [],
        'singleValue'              => ['flatRate' => ['amountMicros' => '5990000', 'currencyCode' => 'USD']],
    ]]);
});

it('builds the return policy with the https policy page', function () {
    expect($this->sync->returnPolicyBody($this->target))->toBe([
        'label'             => 'store-sync-US',
        'countries'         => ['US'],
        'policy'            => ['type' => 'NUMBER_OF_DAYS_AFTER_DELIVERY', 'days' => '30'],
        'returnMethods'     => ['BY_MAIL'],
        'itemConditions'    => ['NEW'],
        'restockingFee'     => ['microPercent' => 10000000],
        'returnShippingFee' => ['type' => 'CUSTOMER_PAYING_ACTUAL_FEE'],
        'returnPolicyUri'   => 'https://shop.example.com/returns',
        'processRefundDays' => 10,
        'acceptExchange'    => true,
    ]);
});

it('pushes shipping without wiping services for other countries, and replaces its own return policy', function () {
    (new PushAccountPoliciesJob($this->account->id))->handle(app(AccountPolicySync::class));

    $insert = collect($this->requests)->first(fn ($r) => $r[0] === 'POST' && str_contains($r[1], 'shippingSettings:insert'));

    expect($insert)->not->toBeNull()
        ->and($insert[2]['etag'])->toBe('etag-1')
        ->and(collect($insert[2]['services'])->pluck('serviceName')->all())->toBe(['Canada post (set up in Merchant Center)', 'Standard']);

    $deletes = collect($this->requests)->filter(fn ($r) => $r[0] === 'DELETE')->pluck(1)->all();

    expect($deletes)->toHaveCount(1)
        ->and($deletes[0])->toContain('accounts/123456/onlineReturnPolicies/111');

    $create = collect($this->requests)->first(fn ($r) => $r[0] === 'POST' && str_ends_with(parse_url($r[1], PHP_URL_PATH), '/onlineReturnPolicies'));

    expect($create[2]['label'])->toBe('store-sync-US')
        ->and($create[2]['returnPolicyUri'])->toBe('https://shop.example.com/returns');

    expect($this->sync->lastResult($this->account))->toMatchArray(['status' => 'success']);
});

it('stops with a setup error and calls nothing when the return policy page is not public https', function () {
    gmcConfig(['store.return_policy_url' => 'http://shop.example.com/returns']);

    (new PushAccountPoliciesJob($this->account->id))->handle(app(AccountPolicySync::class));

    expect($this->requests)->toBe([])
        ->and($this->sync->lastResult($this->account))->toMatchArray(['status' => 'error'])
        ->and($this->sync->lastResult($this->account)['message'])->toContain(trans('google_merchant::app.policies.missing-return-url'));
});

it('queues a push when the store policy settings are saved', function () {
    Queue::fake();

    $this->policies->saveForChannel($this->channelId, $this->policies->forChannel($this->channelId)->toSettingsArray());

    Queue::assertPushed(PushAccountPoliciesJob::class, fn ($job) => $job->accountId === $this->account->id);
});

it('pushes from the store settings button, behind its own permission', function () {
    Queue::fake();

    $this->loginAsAdmin();

    post(route('admin.google_merchant.store.push_policies'))->assertRedirect();

    Queue::assertPushed(PushAccountPoliciesJob::class, 1);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['marketing.google_merchant.store']]);
    $this->loginAsAdmin(Admin::factory()->create(['role_id' => $role->id]));

    post(route('admin.google_merchant.store.push_policies'))->assertStatus(401);

    Queue::assertPushed(PushAccountPoliciesJob::class, 1);
});
