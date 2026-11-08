<?php

use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcTarget;
use Illuminate\Support\Facades\DB;

if (! function_exists('fixture')) {
    /**
     * Decoded JSON fixture from tests/Fixtures (recorded from the Merchant API v1 reference).
     */
    function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), true);
    }

    /**
     * Connected service-account account for merchant 123456 with a token valid for an hour.
     */
    function gmcAccount(array $overrides = []): GmcAccount
    {
        return GmcAccount::create(array_merge([
            'merchant_id'          => '123456',
            'display_name'         => 'Acme Store',
            'auth_type'            => 'service_account',
            'google_email'         => 'sync@project.iam.gserviceaccount.com',
            'access_token'         => 'test-token',
            'token_expires_at'     => now()->addHour(),
            'service_account_json' => json_encode(['type' => 'service_account', 'client_email' => 'sync@project.iam.gserviceaccount.com', 'private_key' => 'unused']),
            'status'               => 'connected',
            'developer_registered_at' => now(),
        ], $overrides));
    }

    /**
     * US / en / USD target with a provisioned API data source.
     */
    function gmcTarget(GmcAccount $account, array $overrides = []): GmcTarget
    {
        return GmcTarget::create(array_merge([
            'gmc_account_id'   => $account->id,
            'channel_id'       => core()->getDefaultChannel()->id,
            'locale'           => 'en',
            'content_language' => 'en',
            'feed_label'       => 'US',
            'countries'        => ['US'],
            'currency'         => 'USD',
            'data_source_name' => 'accounts/123456/dataSources/999',
            'enabled'          => true,
        ], $overrides));
    }

    /**
     * Store admin configuration values (Configure → Google Merchant Center).
     *
     * @param  array<string, mixed>  $values  keys relative to google_merchant.general., e.g. 'settings.enabled'
     */
    function gmcConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('core_config')->updateOrInsert(
                ['code' => 'google_merchant.general.'.$key],
                ['value' => is_array($value) ? implode(',', $value) : (string) $value, 'channel_code' => null, 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Bagisto caches CoreConfigRepository lookups; admin saves clear it, raw inserts don't.
        \Illuminate\Support\Facades\Cache::flush();
    }
}
