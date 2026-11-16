<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Jobs\RefreshStatusesJob;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcTargetProxy;

/**
 * Starts one Google status refresh per active target (every 6 h and from Diagnostics).
 * A target whose previous refresh is still running (started within the hour) is skipped.
 */
class StatusesStarter
{
    /** @return int runs started */
    public function start(): int
    {
        $targets = GmcTargetProxy::modelClass()::query()
            ->where('enabled', true)
            ->whereNotNull('data_source_name')
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Connected->value))
            ->get();

        $started = 0;

        foreach ($targets as $target) {
            $busy = GmcRunProxy::modelClass()::query()
                ->where('type', RunType::Statuses->value)
                ->where('status', RunStatus::Running->value)
                ->where('gmc_target_id', $target->id)
                ->where('started_at', '>', now()->subHour())
                ->exists();

            if ($busy) {
                continue;
            }

            $run = GmcRunProxy::modelClass()::create([
                'type'          => RunType::Statuses->value,
                'status'        => RunStatus::Running->value,
                'gmc_target_id' => $target->id,
                'started_at'    => now(),
            ]);

            RefreshStatusesJob::dispatch($target->id, null, $run->id);
            $started++;
        }

        return $started;
    }
}
