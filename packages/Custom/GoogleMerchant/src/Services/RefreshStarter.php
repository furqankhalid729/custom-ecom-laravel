<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Jobs\RefreshRunJob;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcTargetProxy;

/**
 * Starts one drift-check run per active target (nightly, after mapping changes, on demand).
 */
class RefreshStarter
{
    /** @return int runs started */
    public function start(): int
    {
        $targets = GmcTargetProxy::modelClass()::query()
            ->where('enabled', true)
            ->whereNotNull('data_source_name')
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Connected->value))
            ->get();

        foreach ($targets as $target) {
            $run = GmcRunProxy::modelClass()::create([
                'type'          => RunType::Refresh->value,
                'status'        => RunStatus::Running->value,
                'gmc_target_id' => $target->id,
                'started_at'    => now(),
            ]);

            RefreshRunJob::dispatch($run->id);
        }

        return $targets->count();
    }
}
