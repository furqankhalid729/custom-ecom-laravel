<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Removes one offer marked `deleting` (product deleted). Idempotent: 404 = done,
 * a row reclaimed by a new product with the same SKU is skipped.
 */
class DeleteOfferJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnGmcQueue;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $uniqueFor = 3600;

    public ?int $targetId;

    public function __construct(public int $gmcProductId)
    {
        $this->targetId = GmcProductProxy::modelClass()::query()->whereKey($gmcProductId)->value('gmc_target_id');

        $this->useGmcQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->gmcProductId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function middleware(): array
    {
        return [new EnsureAccountReady];
    }

    public function handle(SyncService $sync): void
    {
        try {
            $sync->deleteOffer($this->gmcProductId);
        } catch (TransientApiException $e) {
            $this->release($e->retryAfter + random_int(0, 15));
        } catch (AuthException) {
            $this->release(EnsureAccountReady::REAUTH_RELEASE_SECONDS);
        }
    }
}
