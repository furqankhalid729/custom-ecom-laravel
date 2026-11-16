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

    /** reconcile_time (HH:MM, default 01:00) × reconcile_frequency (daily | twicedaily, 12 h apart). */
    protected function reconcileCron(): string
    {
        $time = (string) $this->settings->get('settings.reconcile_time', '01:00');

        [$hour, $minute] = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $m) ? [(int) $m[1], (int) $m[2]] : [1, 0];

        $hours = [$hour];

        if ($this->settings->get('settings.reconcile_frequency', 'daily') === 'twicedaily') {
            $hours[] = ($hour + 12) % 24;
            sort($hours);
        }

        return $minute.' '.implode(',', $hours).' * * *';
    }

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

        // Default 01:00: after Bagisto's 00:01 catalog-rule price reindex, which fires no events.
        // Reconcile = orphan cleanup in Google + the drift/stale pass (gmc:refresh alone stays manual).
        $schedule->command('gmc:reconcile')
            ->cron($this->reconcileCron())
            ->when($enabled)
            ->name('gmc:reconcile');

        $schedule->command('gmc:statuses')
            ->cron('0 */6 * * *')
            ->when($enabled)
            ->name('gmc:statuses');

        $schedule->command('gmc:retry-failed')
            ->hourly()
            ->when($enabled)
            ->name('gmc:retry-failed');
    }
}
