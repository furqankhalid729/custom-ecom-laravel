<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Support\Micros;
use Custom\GoogleMerchant\Support\StoreSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Pushes the Store Policies package's shipping methods and return policy to Merchant Center
 * (account-level shippingSettings + onlineReturnPolicies), so the policy pages, structured
 * data and Google agree. Only the store's own markets are touched: shipping services for
 * other countries and return policies with other labels are left as they are.
 */
class AccountPolicySync
{
    public const POLICY_SETTINGS = 'Custom\StorePolicy\Repositories\PolicySettingRepository';

    public const REGIONS = 'Custom\StorePolicy\Support\Regions';

    public function __construct(
        protected MerchantApiClient $client,
        protected StoreSettings $store,
        protected AuditLogger $audit,
    ) {}

    public static function available(): bool
    {
        return class_exists(self::POLICY_SETTINGS) && class_exists(self::REGIONS);
    }

    /** @return array{services: array<int, array>} one service per shipping method of the target's region */
    public function shippingBody(GmcTarget $target): array
    {
        $region = $this->region($target);
        $shipping = $region['shipping'] ?? [];
        $currency = $target->currency;

        $services = [];

        foreach (array_values($shipping['methods'] ?? []) as $i => $method) {
            $name = trim((string) ($method['name'] ?? '')) ?: (trim((string) ($method['carrier'] ?? '')) ?: 'Standard');
            $minTransit = (int) ($method['min_days'] ?? 1);
            $cost = Micros::price((float) ($method['cost'] ?? 0), $currency);
            $freeOver = $method['free_over'] ?? null;

            $services[] = [
                'serviceName'       => in_array($name, array_column($services, 'serviceName'), true) ? "$name ".($i + 1) : $name,
                'active'            => true,
                'deliveryCountries' => array_values($target->countries),
                'currencyCode'      => $currency,
                'deliveryTime'      => [
                    'minHandlingDays' => (int) ($shipping['handling_min_days'] ?? 0),
                    'maxHandlingDays' => (int) ($shipping['handling_max_days'] ?? ($shipping['handling_min_days'] ?? 0)),
                    'minTransitDays'  => $minTransit,
                    'maxTransitDays'  => max($minTransit, (int) ($method['max_days'] ?? $minTransit)),
                ],
                'rateGroups'        => [$freeOver !== null && (float) $freeOver > 0
                    ? [
                        // Orders up to the threshold (inclusive) pay the rate, above it ship free.
                        'applicableShippingLabels' => [],
                        'mainTable'                => [
                            'rowHeaders' => ['prices' => [
                                Micros::price((float) $freeOver, $currency),
                                ['amountMicros' => '-1', 'currencyCode' => $currency],
                            ]],
                            'rows' => [
                                ['cells' => [['flatRate' => $cost]]],
                                ['cells' => [['flatRate' => Micros::price(0, $currency)]]],
                            ],
                        ],
                    ]
                    : [
                        'applicableShippingLabels' => [],
                        'singleValue'              => ['flatRate' => $cost],
                    ]],
            ];
        }

        return ['services' => $services];
    }

    public function returnPolicyBody(GmcTarget $target): array
    {
        $returns = $this->region($target)['returns'] ?? [];

        $body = [
            'label'     => $this->label($target),
            'countries' => array_values($target->countries),
        ];

        if (! ($returns['accepted'] ?? true)) {
            return $body + [
                'policy'          => ['type' => 'NO_RETURNS'],
                'returnPolicyUri' => $this->returnUrl($target),
            ];
        }

        $fee = (float) ($returns['restocking_fee_percent'] ?? 0);

        return $body + array_filter([
            'policy'            => ['type' => 'NUMBER_OF_DAYS_AFTER_DELIVERY', 'days' => (string) (int) ($returns['window_days'] ?? 30)],
            'returnMethods'     => match ($returns['method'] ?? 'mail') {
                'store' => ['IN_STORE'],
                'both'  => ['BY_MAIL', 'IN_STORE'],
                default => ['BY_MAIL'],
            },
            'itemConditions'    => ['NEW'],
            'restockingFee'     => $fee > 0 ? ['microPercent' => (int) round($fee * 1_000_000)] : null,
            'returnShippingFee' => ($returns['return_shipping_paid_by'] ?? 'customer') === 'customer'
                ? ['type' => 'CUSTOMER_PAYING_ACTUAL_FEE']
                : ['type' => 'FIXED'], // no fixedFee = free for the customer
            'returnPolicyUri'   => $this->returnUrl($target),
            'processRefundDays' => isset($returns['refund_days']) ? (int) $returns['refund_days'] : null,
            'acceptExchange'    => (bool) ($returns['exchanges'] ?? false),
        ], fn ($v) => $v !== null);
    }

    /**
     * Validates everything first (no API call on a setup problem), then pushes shipping
     * (merged, with etag) and replaces the store's own return policies.
     */
    public function push(GmcAccount $account): void
    {
        $targets = $account->targets()->where('enabled', true)->orderBy('id')->get();

        $errors = $this->problems($targets);

        if ($errors) {
            $this->record($account, 'error', implode(' ', $errors));

            return;
        }

        try {
            $this->pushShipping($account, $targets);
            $this->pushReturnPolicies($account, $targets);
        } catch (GmcApiException $e) {
            $this->record($account, 'error', $e->getMessage());

            throw $e;
        }

        $this->record($account, 'success', trans('google_merchant::app.policies.pushed', ['count' => $targets->count()]));
    }

    /** @return array{status: string, message: string, at: string}|null */
    public function lastResult(GmcAccount $account): ?array
    {
        return Cache::get($this->resultKey($account));
    }

    /** @return string[] setup problems that block a push */
    protected function problems($targets): array
    {
        if (! self::available()) {
            return [trans('google_merchant::app.policies.package-missing')];
        }

        if ($targets->isEmpty()) {
            return [trans('google_merchant::app.policies.no-target')];
        }

        $errors = [];

        foreach ($targets as $target) {
            if (! $this->region($target)) {
                $errors[] = trans('google_merchant::app.policies.no-region', ['countries' => implode(', ', $target->countries)]);

                continue;
            }

            if (! $this->store->isPublicHttps($this->returnUrl($target))) {
                $errors[] = trans('google_merchant::app.policies.missing-return-url');
            }
        }

        return array_values(array_unique($errors));
    }

    protected function pushShipping(GmcAccount $account, $targets): void
    {
        $ours = $targets->flatMap(fn ($t) => $t->countries)->unique()->values()->all();
        $services = $targets->flatMap(fn ($t) => $this->shippingBody($t)['services'])->values()->all();

        if (! $services) {
            return; // no methods configured: leave Google's shipping alone rather than wipe it
        }

        $current = $this->client->getShippingSettings($account) ?? [];

        // Keep services for countries this store does not sync (set up by hand or by another tool).
        $kept = array_values(array_filter(
            $current['services'] ?? [],
            fn ($service) => ! array_intersect($service['deliveryCountries'] ?? [], $ours)
        ));

        $this->client->insertShippingSettings($account, [
            'services'   => [...$kept, ...$services],
            'warehouses' => $current['warehouses'] ?? [],
            'etag'       => $current['etag'] ?? '',
        ]);
    }

    protected function pushReturnPolicies(GmcAccount $account, $targets): void
    {
        $existing = collect($this->client->listOnlineReturnPolicies($account));

        foreach ($targets as $target) {
            $body = $this->returnPolicyBody($target);

            foreach ($existing->where('label', $body['label']) as $old) {
                $this->client->deleteOnlineReturnPolicy($account, $old['name']);
            }

            $this->client->createOnlineReturnPolicy($account, $body);
        }
    }

    /** The Store Policies region (US / EU) that covers the target's main country, if enabled. */
    protected function region(GmcTarget $target): ?array
    {
        if (! self::available()) {
            return null;
        }

        $country = strtoupper((string) ($target->countries[0] ?? ''));

        $code = collect((self::REGIONS)::definitions())
            ->search(fn ($definition) => in_array($country, $definition['countries'], true));

        if ($code === false) {
            return null;
        }

        $regions = app(self::POLICY_SETTINGS)->forChannel($target->channel_id)->regions ?? [];

        $region = $regions[$code] ?? null;

        return $region && ($region['enabled'] ?? true) ? $region : null;
    }

    protected function returnUrl(GmcTarget $target): ?string
    {
        return $this->store->policyUrls($target->channel_id)['return'] ?? null;
    }

    /** Immutable label identifying the store's own policy for this market. */
    protected function label(GmcTarget $target): string
    {
        return mb_substr('store-sync-'.$target->feed_label, 0, 50);
    }

    protected function record(GmcAccount $account, string $status, string $message): void
    {
        Cache::forever($this->resultKey($account), ['status' => $status, 'message' => $message, 'at' => now()->toIso8601String()]);

        $this->audit->record('google_merchant', 'policies.push.'.$status, ['account_id' => $account->id, 'message' => $message]);
    }

    protected function resultKey(GmcAccount $account): string
    {
        return "gmc:policy-push:{$account->id}";
    }
}
