<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Jobs\RefreshRunJob;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Illuminate\Console\Command;

class RefreshCommand extends Command
{
    protected $signature = 'gmc:refresh';

    protected $description = 'Find products whose Google data drifted, expired or was missed, and queue them';

    public function handle(): int
    {
        $targets = GmcTargetProxy::modelClass()::query()
            ->where('enabled', true)
            ->whereNotNull('data_source_name')
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Connected->value))
            ->get();

        foreach ($targets as $target) {
            $run = GmcRunProxy::modelClass()::create([
                'type'          => RunType::Refresh->value,
                'status'        => RunStatus::Running->value,
                'gmc_target_id' => $target->id,
                'started_at'    => now(),
            ]);

            RefreshRunJob::dispatch($run->id);
        }

        $this->info("Started {$targets->count()} refresh run(s).");

        return self::SUCCESS;
    }
}
