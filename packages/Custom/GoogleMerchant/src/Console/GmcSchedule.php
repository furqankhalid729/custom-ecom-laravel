<?php

namespace Custom\GoogleMerchant\Console;

use Custom\GoogleMerchant\Jobs\HeartbeatJob;
use Custom\GoogleMerchant\Services\SetupChecklist;
use Custom\GoogleMerchant\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/**
 * Everything runs from `php artisan schedule:run` (one cron / Task Scheduler entry).
 * In the default "scheduler" runner mode the gmc queue is drained every minute,
 * so no permanently running worker or process manager is needed.
 */
class GmcSchedule
{
    public function __construct(protected Settings $settings) {}

    public function define(Schedule $schedule): void
    {
        $enabled = fn () => $this->settings->enabled();

        $schedule->call(fn () => Cache::put(SetupChecklist::SCHEDULE_KEY, time(), 3600))
            ->everyMinute()
            ->name('gmc:scheduler-heartbeat');

        if (config('google_merchant.queue.runner', 'scheduler') === 'scheduler') {
            $schedule->command('gmc:queue-run')
                ->everyMinute()
                ->withoutOverlapping(2)
                ->runInBackground()
                ->name('gmc:queue-run');
        }

        $schedule->job(new HeartbeatJob)
            ->everyFiveMinutes()
            ->when($enabled)
            ->name('gmc:worker-heartbeat');

        // After Bagisto's 00:01 catalog-rule price reindex, which fires no events.
        $schedule->command('gmc:refresh')
            ->dailyAt('01:00')
            ->when($enabled)
            ->withoutOverlapping(120)
            ->name('gmc:refresh');

        $schedule->command('gmc:retry-failed')
            ->hourly()
            ->when($enabled)
            ->name('gmc:retry-failed');
    }
}
