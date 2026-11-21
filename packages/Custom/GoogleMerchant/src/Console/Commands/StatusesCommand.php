<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Services\StatusesStarter;
use Illuminate\Console\Command;

class StatusesCommand extends Command
{
    protected $signature = 'gmc:statuses';

    protected $description = 'Fetch approval status and issues for every product from Google';

    public function handle(StatusesStarter $starter): int
    {
        $this->info('Started '.$starter->start().' status refresh run(s).');

        return self::SUCCESS;
    }
}
