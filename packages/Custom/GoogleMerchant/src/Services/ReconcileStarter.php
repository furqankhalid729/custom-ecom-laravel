<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Jobs\ReconcileJob;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcTarget;

/** Full reconciliation per target: orphan cleanup, then the drift/stale pass. */
class ReconcileStarter extends TargetRunStarter
{
    protected function type(): RunType
    {
        return RunType::Reconcile;
    }

    protected function busyMinutes(): int
    {
        return 6 * 60;
    }

    protected function dispatch(GmcTarget $target, GmcRun $run): void
    {
        ReconcileJob::dispatch($target->id, null, $run->id);
    }
}
