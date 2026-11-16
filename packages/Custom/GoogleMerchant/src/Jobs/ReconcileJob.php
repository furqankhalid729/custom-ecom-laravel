<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\Exceptions\NotFoundException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Enums\Operation;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcSyncLogProxy;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Support\ProductInputName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation, part 1: walks Google's offers in the target's own data source one
 * page per job and deletes orphans (offers the store no longer has). After the last
 * page it hands the same run to RefreshRunJob for the hash-drift / stale pass.
 * Offers in other data sources (other tools, other markets) are never touched.
 */
class ReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnGmcQueue;

    public const PAGE_SIZE = 1000;

    /** Ledger states meaning "the store still wants this offer in Google". */
    public const LIVE_STATES = ['synced', 'pending', 'failed', 'invalid'];

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public function __construct(
        public int $targetId,
        public ?string $pageToken,
        public int $runId,
    ) {
        $this->useGmcQueue();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function middleware(): array
    {
        return [new EnsureAccountReady];
    }

    public function handle(MerchantApiClient $client): void
    {
        $run = GmcRunProxy::modelClass()::with('target.account')->find($this->runId);
        $target = $run?->target;

        if (! $run || $run->status !== RunStatus::Running->value || ! $target?->account || ! $target->data_source_name) {
            return;
        }

        $deleted = 0;

        // A transient/auth error retries the whole page; orphans already deleted are
        // either gone from the listing or answer 404, which counts as done.
        try {
            $page = $client->listProducts($target->account, $this->pageToken, self::PAGE_SIZE);

            $own = array_values(array_filter(
                $page['products'],
                fn (array $p) => ($p['dataSource'] ?? null) === $target->data_source_name && isset($p['offerId'])
            ));

            foreach ($this->orphans($target, $own) as $product) {
                $deleted += (int) $this->deleteOrphan($client, $target, $product);
            }
        } catch (TransientApiException $e) {
            $this->release($e->retryAfter + random_int(0, 15));

            return;
        } catch (AuthException) {
            $this->release(EnsureAccountReady::REAUTH_RELEASE_SECONDS);

            return;
        }

        $options = $run->options ?? [];
        $options['google_offers'] = ($options['google_offers'] ?? 0) + count($own);
        $options['orphans_deleted'] = ($options['orphans_deleted'] ?? 0) + $deleted;
        $run->forceFill(['options' => $options])->save();

        if ($next = $page['nextPageToken'] ?? null) {
            static::dispatch($target->id, $next, $run->id);

            return;
        }

        // Part 2: hash drift, stale offers, missed changes (same run; RefreshRunJob completes it).
        RefreshRunJob::dispatch($run->id);
    }

    /** Google offers with no live ledger row and no store product with that SKU. */
    protected function orphans(GmcTarget $target, array $products): array
    {
        $offerIds = array_column($products, 'offerId');

        if (! $offerIds) {
            return [];
        }

        $known = GmcProductProxy::modelClass()::query()
            ->where('gmc_target_id', $target->id)
            ->whereIn('offer_id', $offerIds)
            ->whereIn('state', self::LIVE_STATES)
            ->pluck('offer_id')
            ->merge(DB::table('products')->whereIn('sku', $offerIds)->pluck('sku'))
            ->flip();

        return array_values(array_filter($products, fn (array $p) => ! isset($known[$p['offerId']])));
    }

    protected function deleteOrphan(MerchantApiClient $client, GmcTarget $target, array $product): bool
    {
        $name = ProductInputName::encode($target->account->merchant_id, $product['contentLanguage'], $product['feedLabel'], $product['offerId']);
        $started = microtime(true);
        $error = null;

        try {
            $client->deleteProductInput($target->account, $name, $target->data_source_name);
        } catch (NotFoundException) {
            // Already gone: that is the goal.
        } catch (TransientApiException|AuthException $e) {
            throw $e; // let the page be retried
        } catch (GmcApiException $e) {
            $error = $e;
        }

        GmcSyncLogProxy::modelClass()::create([
            'gmc_target_id'    => $target->id,
            'offer_id'         => $product['offerId'],
            'operation'        => Operation::Delete->value,
            'context'          => 'orphan',
            'status'           => $error ? 'error' : 'success',
            'http_status'      => $error?->httpStatus,
            'response_excerpt' => $error?->excerpt,
            'error_message'    => $error ? mb_strcut($error->getMessage(), 0, 1000) : null,
            'attempt'          => 1,
            'duration_ms'      => (int) round((microtime(true) - $started) * 1000),
            'gmc_run_id'       => $this->runId,
        ]);

        return ! $error;
    }
}
