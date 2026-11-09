<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Services\RefreshStarter;
use Illuminate\Console\Command;

class RefreshCommand extends Command
{
    protected $signature = 'gmc:refresh';

    protected $description = 'Find products whose Google data drifted, expired or was missed, and queue them';

    public function handle(RefreshStarter $starter): int
    {
        $this->info('Started '.$starter->start().' refresh run(s).');

        return self::SUCCESS;
    }
}
