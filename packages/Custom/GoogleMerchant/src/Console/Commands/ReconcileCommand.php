<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Services\ReconcileStarter;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'gmc:reconcile';

    protected $description = 'Remove offers Google has but the store no longer does, then queue drifted or expiring products';

    public function handle(ReconcileStarter $starter): int
    {
        $this->info('Started '.$starter->start().' reconcile run(s).');

        return self::SUCCESS;
    }
}
