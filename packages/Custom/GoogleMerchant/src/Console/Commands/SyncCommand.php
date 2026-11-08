<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Services\BulkSyncService;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'gmc:sync {--product=* : Store product id(s)} {--all : Bulk sync the whole catalog} {--force : Resubmit even when unchanged}';

    protected $description = 'Queue chosen products for Google Merchant Center sync (use the admin "Bulk sync" page for the whole catalog)';

    public function handle(SyncDispatcher $dispatcher, BulkSyncService $bulk): int
    {
        if ($this->option('all')) {
            $runs = $bulk->start([], $this->option('force') ? 'full' : 'incremental', null);

            $this->info($runs ? 'Started '.count($runs).' bulk run(s); follow progress under Marketing → Google Merchant Center → Bulk sync.' : 'Nothing started: sync is disabled or no account is connected.');

            return self::SUCCESS;
        }

        $ids = array_map('intval', (array) $this->option('product'));

        if (! $ids) {
            $this->error('Pass --product=<id> (repeatable) or --all.');

            return self::FAILURE;
        }

        $count = $dispatcher->queue($ids, 'manual', 0, (bool) $this->option('force'));

        $this->info($count ? "Queued {$count} sync job(s)." : 'Nothing queued: sync is disabled or no Merchant Center account is connected.');

        return self::SUCCESS;
    }
}
