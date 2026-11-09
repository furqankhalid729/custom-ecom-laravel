<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Custom\GoogleMerchant\Support\Settings;
use Webkul\Core\Repositories\CoreConfigRepository;

/**
 * Store facts for Google (Configure → Google Merchant Center → Store details)
 * and the default market (gmc_targets) they describe.
 */
class StoreSettingsService
{
    public const FIELDS = [
        'website_url', 'country', 'language', 'currency',
        'return_policy_url', 'refund_policy_url', 'terms_url', 'privacy_url',
        'shipping_note', 'tax_note',
    ];

    public function __construct(
        protected CoreConfigRepository $coreConfig,
        protected Settings $settings,
        protected DataSourceProvisioner $provisioner,
        protected BulkSyncService $bulk,
    ) {}

    /** @return array<string, ?string> */
    public function current(): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => $this->settings->get("store.$f")])->all();
    }

    /** True when the account's default target would move to a different country or language. */
    public function changesMarket(?GmcAccount $account, array $data): bool
    {
        $target = $account?->targets()->where('enabled', true)->orderBy('id')->first();

        return $target && (strtoupper($data['country']) !== $target->feed_label || strtolower($data['language']) !== $target->content_language);
    }

    public function hasOffersInGoogle(?GmcAccount $account): bool
    {
        $target = $account?->targets()->where('enabled', true)->orderBy('id')->first();

        return $target && $target->account
            && \Custom\GoogleMerchant\Models\GmcProductProxy::modelClass()::query()
                ->where('gmc_target_id', $target->id)
                ->where('state', SyncState::Synced->value)
                ->exists();
    }

    /**
     * Saves the values and moves the market when needed.
     *
     * @return array{market_changed: bool, runs: int}
     */
    public function save(array $data, ?GmcAccount $account, bool $resync, ?int $adminId): array
    {
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;

            if (in_array($field, ['country', 'currency'])) {
                $value = strtoupper((string) $value);
            } elseif ($field === 'language') {
                $value = strtolower((string) $value);
            }

            // Through the repository so Bagisto's config cache is cleared.
            $this->coreConfig->updateOrCreate(
                ['code' => Settings::PREFIX.'store.'.$field, 'channel_code' => null, 'locale_code' => null],
                ['value' => (string) ($value ?? '')],
            );
        }

        $target = $account?->isConnected() ? $account->targets()->where('enabled', true)->orderBy('id')->first() : null;

        if (! $target) {
            return ['market_changed' => false, 'runs' => 0];
        }

        $country = strtoupper($data['country']);
        $language = strtolower($data['language']);
        $currency = strtoupper($data['currency']);

        if ($country === $target->feed_label && $language === $target->content_language) {
            $currencyChanged = $currency !== $target->currency;

            $target->forceFill(['currency' => $currency])->save();

            $runs = $currencyChanged && $resync ? count($this->bulk->start(['target_id' => $target->id], 'full', $adminId)) : 0;

            return ['market_changed' => false, 'runs' => $runs];
        }

        return ['market_changed' => true, 'runs' => $this->moveMarket($target, $country, $language, $currency, $adminId)];
    }

    /**
     * A new market gets its own target + data source. The old target is
     * switched off and its offers removed (that run still knows the old data source).
     */
    protected function moveMarket(GmcTarget $old, string $country, string $language, string $currency, ?int $adminId): int
    {
        $new = GmcTargetProxy::modelClass()::query()->firstOrNew([
            'gmc_account_id'   => $old->gmc_account_id,
            'content_language' => $language,
            'feed_label'       => $country,
        ]);

        $new->forceFill([
            'channel_id' => $old->channel_id,
            'locale'     => $language,
            'countries'  => [$country],
            'currency'   => $currency,
            'enabled'    => true,
        ])->save();

        $this->provisioner->ensure($new);

        $old->forceFill(['enabled' => false])->save();

        $runs = count($this->bulk->startDeleteAllForTarget($old, $adminId));
        $runs += count($this->bulk->start(['target_id' => $new->id], 'full', $adminId));

        return $runs;
    }
}
