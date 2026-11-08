<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Custom\GoogleMerchant\Support\Settings;

/**
 * Finds or creates the API data source that receives a target's products.
 */
class DataSourceProvisioner
{
    public function __construct(
        protected MerchantApiClient $client,
        protected Settings $settings,
    ) {}

    public function ensure(GmcTarget $target): string
    {
        $account = $target->account;

        $sources = $this->client->listDataSources($account);

        // Keep the source already in use while it still exists in Merchant Center.
        if ($target->data_source_name && collect($sources)->contains('name', $target->data_source_name)) {
            return $target->data_source_name;
        }

        foreach ($sources as $source) {
            if ($this->matches($source, $target)) {
                return $this->save($target, $source['name']);
            }
        }

        $created = $this->client->createDataSource($account, [
            'displayName'              => "Bagisto {$target->feed_label}/{$target->content_language}",
            'primaryProductDataSource' => [
                'contentLanguage' => $target->content_language,
                'feedLabel'       => $target->feed_label,
                'countries'       => array_values($target->countries),
            ],
        ]);

        return $this->save($target, $created['name']);
    }

    /**
     * The single store market from Configure → Google Merchant Center → Store details.
     */
    public function ensureDefaultTarget(GmcAccount $account): GmcTarget
    {
        $existing = $account->targets()->orderBy('id')->first();

        if ($existing) {
            return $existing;
        }

        $country = strtoupper((string) $this->settings->get('store.country', 'US'));
        $language = strtolower((string) $this->settings->get('store.language', 'en'));

        return GmcTargetProxy::modelClass()::create([
            'gmc_account_id'   => $account->id,
            'channel_id'       => core()->getDefaultChannel()->id,
            'locale'           => $language,
            'content_language' => $language,
            'feed_label'       => $country,
            'countries'        => [$country],
            'currency'         => strtoupper((string) $this->settings->get('store.currency', 'USD')),
            'enabled'          => true,
        ]);
    }

    /** Only API-input primary sources with the same language, label and countries can be reused. */
    protected function matches(array $source, GmcTarget $target): bool
    {
        $primary = $source['primaryProductDataSource'] ?? null;

        if (! $primary || ($source['input'] ?? null) !== 'API') {
            return false;
        }

        $countries = $primary['countries'] ?? [];
        sort($countries);

        $wanted = array_values($target->countries);
        sort($wanted);

        return ($primary['contentLanguage'] ?? null) === $target->content_language
            && ($primary['feedLabel'] ?? null) === $target->feed_label
            && $countries === $wanted;
    }

    protected function save(GmcTarget $target, string $name): string
    {
        $target->forceFill(['data_source_name' => $name])->save();

        return $name;
    }
}
