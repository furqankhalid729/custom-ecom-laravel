<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Support\StoreSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Everything that must be true before products can reach Google.
 */
class SetupChecklist
{
    public const HEARTBEAT_KEY = 'gmc:worker:heartbeat';

    public const SCHEDULE_KEY = 'gmc:schedule:last';

    public function __construct(protected StoreSettings $store) {}

    /**
     * @return array<string, array{ok: bool, message: string}>
     */
    public function run(?GmcAccount $account): array
    {
        $channelId = core()->getDefaultChannel()->id;
        $website = $this->store->websiteUrl($channelId);
        $policies = $this->store->policyUrls($channelId);
        $target = $account?->targets()->orderBy('id')->first();

        $checks = [
            'credentials_configured' => filled(config('google_merchant.client_id')) && filled(config('google_merchant.client_secret'))
                || $account?->auth_type === 'service_account',
            'connected'            => (bool) $account?->isConnected(),
            'merchant_selected'    => filled($account?->merchant_id),
            'developer_registered' => (bool) $account?->developer_registered_at,
            'data_source'          => filled($target?->data_source_name),
            'public_https'         => $this->store->isPublicHttps($website),
            'policy_urls'          => collect($policies)->every(fn ($url) => $this->store->isPublicHttps($url)),
            'queue_runner'         => $this->recent(self::HEARTBEAT_KEY, 600),
            'scheduler'            => $this->recent(self::SCHEDULE_KEY, 300),
        ];

        return collect($checks)->map(fn (bool $ok, string $key) => [
            'ok'      => $ok,
            'message' => trans("google_merchant::app.checklist.{$key}".($ok ? '' : '-fix'), ['url' => $website]),
        ])->all();
    }

    public function allPassed(?GmcAccount $account): bool
    {
        return collect($this->run($account))->every(fn ($check) => $check['ok']);
    }

    protected function recent(string $key, int $seconds): bool
    {
        $at = Cache::get($key);

        return $at && time() - (int) $at <= $seconds;
    }
}
