<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Services\SetupChecklist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Queued every 5 minutes; when it runs, a worker is draining the gmc queue.
 */
class HeartbeatJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsOnGmcQueue;

    public function __construct()
    {
        $this->useGmcQueue();
    }

    public function handle(): void
    {
        Cache::put(SetupChecklist::HEARTBEAT_KEY, time(), 3600);
    }
}
