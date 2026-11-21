<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes sync history older than log_retention_days, 1,000 rows per statement
 * so a large backlog never locks the table for long.
 */
class PruneLogsJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsOnGmcQueue;

    public const CHUNK = 1000;

    public int $timeout = 600;

    public function __construct()
    {
        $this->useGmcQueue();
    }

    public function handle(Settings $settings): void
    {
        $cutoff = now()->subDays(max(1, $settings->int('settings.log_retention_days', 30)));

        do {
            $deleted = DB::table('gmc_sync_logs')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->delete();
        } while ($deleted === self::CHUNK);
    }
}
