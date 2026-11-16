<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Models\GmcTargetProxy;

/**
 * Starts one run per active target (enabled, data source ready, account connected),
 * skipping a target whose previous run of the same type is still running.
 */
abstract class TargetRunStarter
{
    abstract protected function type(): RunType;

    /** A running run older than this is considered stuck and no longer blocks a new one. */
    abstract protected function busyMinutes(): int;

    abstract protected function dispatch(GmcTarget $target, GmcRun $run): void;

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
                ->where('type', $this->type()->value)
                ->where('status', RunStatus::Running->value)
                ->where('gmc_target_id', $target->id)
                ->where('started_at', '>', now()->subMinutes($this->busyMinutes()))
                ->exists();

            if ($busy) {
                continue;
            }

            $run = GmcRunProxy::modelClass()::create([
                'type' => $this->type()->value,
                'status' => RunStatus::Running->value,
                'gmc_target_id' => $target->id,
                'started_at' => now(),
            ]);

            $this->dispatch($target, $run);
            $started++;
        }

        return $started;
    }
}
