<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Jobs\RefreshStatusesJob;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcTarget;

/** Google status refresh per target (every 6 h and from Diagnostics). */
class StatusesStarter extends TargetRunStarter
{
    protected function type(): RunType
    {
        return RunType::Statuses;
    }

    protected function busyMinutes(): int
    {
        return 60;
    }

    protected function dispatch(GmcTarget $target, GmcRun $run): void
    {
        RefreshStatusesJob::dispatch($target->id, null, $run->id);
    }
}
