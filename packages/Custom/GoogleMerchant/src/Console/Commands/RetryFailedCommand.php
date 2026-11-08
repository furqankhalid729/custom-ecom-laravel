<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Enums\ErrorKind;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Console\Command;

/**
 * Failures caused by Google (outage, quota, auth) are retried hourly, up to
 * MAX_CYCLES times. Data errors wait until the product changes or an admin retries.
 */
class RetryFailedCommand extends Command
{
    public const MAX_CYCLES = 5;

    protected $signature = 'gmc:retry-failed';

    protected $description = 'Re-queue products that failed because of temporary Google errors';

    public function handle(GmcProductRepository $ledger, SyncDispatcher $dispatcher): int
    {
        $rows = $ledger->query()
            ->where('state', SyncState::Failed->value)
            ->whereIn('error_kind', [ErrorKind::Transient->value, ErrorKind::Auth->value, ErrorKind::Quota->value])
            ->where('retry_cycles', '<', self::MAX_CYCLES)
            ->whereNotNull('root_product_id')
            ->get(['id', 'root_product_id', 'gmc_target_id']);

        $ledger->query()->whereIn('id', $rows->pluck('id'))->increment('retry_cycles');

        $rows->unique(fn ($row) => $row->gmc_target_id.':'.$row->root_product_id)
            ->each(fn ($row) => $dispatcher->dispatchJob($row->root_product_id, $row->gmc_target_id, 0));

        $this->info("Re-queued {$rows->count()} failed offer(s).");

        return self::SUCCESS;
    }
}
