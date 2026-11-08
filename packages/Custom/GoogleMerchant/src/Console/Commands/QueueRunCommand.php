<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Illuminate\Console\Command;

/**
 * Drains the gmc queue and exits (≤ 50 s), so cron can run it every minute.
 */
class QueueRunCommand extends Command
{
    protected $signature = 'gmc:queue-run {--max-time=50}';

    protected $description = 'Process queued Google Merchant Center jobs, then exit';

    public function handle(): int
    {
        return $this->call('queue:work', [
            'connection'       => config('google_merchant.queue.connection'),
            '--queue'          => config('google_merchant.queue.name'),
            '--stop-when-empty' => true,
            '--max-time'       => (int) $this->option('max-time'),
            '--sleep'          => 3,
            '--timeout'        => 120,
        ]);
    }
}
