<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Enums\ErrorKind;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Syncs one root product to one target. Unique until a worker picks it up, so
 * a burst of saves becomes one job, while a save during the run queues a follow-up.
 */
class SyncProductJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnGmcQueue;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $uniqueFor = 3600;

    public int $timeout = 120;

    public function __construct(
        public int $rootProductId,
        public int $targetId,
        public bool $force = false,
        public ?int $runId = null,
    ) {
        $this->useGmcQueue();
    }

    /** A forced (manual / full) sync must not be swallowed by a pending debounced one. */
    public function uniqueId(): string
    {
        return "{$this->targetId}:{$this->rootProductId}".($this->force ? ':force' : '');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    /** Used for unexpected exceptions; API back-off uses release() below. */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function middleware(): array
    {
        return [new EnsureAccountReady];
    }

    public function handle(SyncService $sync, SyncDispatcher $dispatcher): void
    {
        try {
            $result = $sync->syncRoot($this->rootProductId, $this->targetId, $this->force, $this->runId);
        } catch (TransientApiException $e) {
            $this->release($e->retryAfter + random_int(0, 15));

            return;
        } catch (AuthException) {
            $this->release(EnsureAccountReady::REAUTH_RELEASE_SECONDS);

            return;
        }

        if ($result->dirty) {
            $dispatcher->dispatchJob($this->rootProductId, $this->targetId);
        }
    }

    public function failed(?\Throwable $e): void
    {
        GmcProductProxy::modelClass()::query()
            ->where('root_product_id', $this->rootProductId)
            ->where('gmc_target_id', $this->targetId)
            ->where('state', SyncState::Pending->value)
            ->update([
                'state'      => SyncState::Failed->value,
                'error_kind' => ErrorKind::Transient->value,
                'last_error' => mb_strcut('Gave up after repeated errors: '.($e?->getMessage() ?? 'unknown'), 0, 1000),
                'updated_at' => now(),
            ]);
    }
}
