<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'gmc:sync {--product=* : Store product id(s)} {--force : Resubmit even when unchanged}';

    protected $description = 'Queue chosen products for Google Merchant Center sync (use the admin "Bulk sync" page for the whole catalog)';

    public function handle(SyncDispatcher $dispatcher): int
    {
        $ids = array_map('intval', (array) $this->option('product'));

        if (! $ids) {
            $this->error('Pass at least one --product=<id>.');

            return self::FAILURE;
        }

        $count = $dispatcher->queue($ids, 'manual', 0, (bool) $this->option('force'));

        $this->info($count ? "Queued {$count} sync job(s)." : 'Nothing queued: sync is disabled or no Merchant Center account is connected.');

        return self::SUCCESS;
    }
}
