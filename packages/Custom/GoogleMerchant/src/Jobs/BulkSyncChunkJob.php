<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Sync\SyncOutcome;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Syncs (or, for delete-all runs, removes) up to 50 ids, counting each once.
 * On a Google back-off it hands the not-yet-processed ids to a new delayed
 * job and stops, so nothing is counted twice and nothing is lost.
 */
class BulkSyncChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnGmcQueue;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public ?int $targetId;

    /** @param  int[]  $productIds  root product ids (or gmc_products ids for delete-all runs) */
    public function __construct(public int $runId, public array $productIds)
    {
        $this->targetId = GmcRunProxy::modelClass()::query()->whereKey($runId)->value('gmc_target_id');

        $this->useGmcQueue();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function middleware(): array
    {
        return [new EnsureAccountReady];
    }

    public function handle(SyncService $sync): void
    {
        $run = GmcRunProxy::modelClass()::find($this->runId);

        if (! $run || $run->status === RunStatus::Failed->value) {
            return;
        }

        $deleting = $run->type === RunType::DeleteAll->value;
        $force = ($run->options['mode'] ?? 'incremental') === 'full';

        foreach ($this->productIds as $index => $id) {
            try {
                if ($deleting) {
                    GmcProductProxy::modelClass()::query()->whereKey($id)->update(['state' => SyncState::Deleting->value]);

                    $ok = $sync->deleteOffer($id, $run->id) !== SyncOutcome::Failed;
                } else {
                    $outcome = $sync->syncRoot($id, $run->gmc_target_id, $force, $run->id)->outcome;

                    $ok = ! in_array($outcome, [SyncOutcome::Failed, SyncOutcome::Invalid], true);
                }
            } catch (TransientApiException|AuthException $e) {
                $delay = $e instanceof TransientApiException ? $e->retryAfter + random_int(0, 15) : EnsureAccountReady::REAUTH_RELEASE_SECONDS;

                static::dispatch($run->id, array_slice($this->productIds, $index))->delay($delay);

                return;
            }

            DB::table('gmc_runs')->where('id', $run->id)->update([
                'processed'                 => DB::raw('processed + 1'),
                $ok ? 'succeeded' : 'failed' => DB::raw(($ok ? 'succeeded' : 'failed').' + 1'),
                'updated_at'                => now(),
            ]);
        }

        $this->completeIfDone();
    }

    protected function completeIfDone(): void
    {
        $run = GmcRunProxy::modelClass()::find($this->runId);

        if ($run && ($run->options['listed'] ?? false) && $run->processed >= $run->total && $run->status === RunStatus::Running->value) {
            $run->forceFill(['status' => RunStatus::Completed->value, 'finished_at' => now()])->save();
        }
    }
}
