<?php

namespace Custom\GoogleMerchant\Listeners;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Jobs\PushAccountPoliciesJob;
use Custom\GoogleMerchant\Models\GmcAccountProxy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Store Policies saved for a channel → queue a policy push for every connected
 * account that syncs that channel. Never breaks the admin's save.
 */
class PolicySettingsListener
{
    public function saved(Model $settings): void
    {
        try {
            GmcAccountProxy::modelClass()::query()
                ->where('status', AccountStatus::Connected->value)
                ->whereNotNull('merchant_id')
                ->whereHas('targets', fn ($q) => $q->where('enabled', true)->where('channel_id', $settings->channel_id))
                ->pluck('id')
                ->each(fn ($id) => PushAccountPoliciesJob::dispatch((int) $id));
        } catch (\Throwable $e) {
            Log::warning('Google Merchant: policy push could not be queued: '.$e->getMessage());
        }
    }
}
