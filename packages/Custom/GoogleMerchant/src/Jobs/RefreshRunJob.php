<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Mapping\ProductMapper;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Sync\Eligibility;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

/**
 * Nightly drift check, one chunk of root products per job (no PHP timeouts on
 * big catalogs). Queues a sync for every root whose Google data is missing,
 * changed (e.g. catalog-rule prices), dirty, failed/pending, or close to expiry.
 * Makes no Google calls itself.
 */
class RefreshRunJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsOnGmcQueue;

    public const CHUNK = 200;

    public int $timeout = 300;

    public function __construct(public int $runId)
    {
        $this->useGmcQueue();
    }

    public function handle(ProductMapper $mapper, Eligibility $eligibility, SyncDispatcher $dispatcher): void
    {
        $run = GmcRunProxy::modelClass()::with('target')->find($this->runId);

        if (! $run || $run->status !== RunStatus::Running->value || ! $run->target) {
            return;
        }

        $target = $run->target;

        if ($run->cursor === 0) {
            $run->forceFill(['total' => $this->roots()->count()])->save();

            $this->queueOrphans($run, $dispatcher);
        }

        $ids = $this->roots()->where('id', '>', $run->cursor)->orderBy('id')->limit(self::CHUNK)->pluck('id');

        $queued = 0;

        $products = ProductProxy::modelClass()::with(['attribute_values', 'images'])->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if ($this->needsSync($products[$id], $run, $mapper, $eligibility)) {
                $dispatcher->dispatchJob($id, $target->id, 0, false, $run->id);
                $queued++;
            }
        }

        $options = $run->options ?? [];
        $options['queued'] = ($options['queued'] ?? 0) + $queued;

        $run->forceFill([
            'cursor'    => (int) ($ids->last() ?? $run->cursor),
            'processed' => $run->processed + $ids->count(),
            'options'   => $options,
        ])->save();

        if ($ids->count() < self::CHUNK) {
            $run->forceFill(['status' => RunStatus::Completed->value, 'finished_at' => now()])->save();

            return;
        }

        static::dispatch($run->id);
    }

    protected function needsSync($product, GmcRun $run, ProductMapper $mapper, Eligibility $eligibility): bool
    {
        $target = $run->target;

        $rows = GmcProductProxy::modelClass()::query()
            ->where('gmc_target_id', $target->id)
            ->where('root_product_id', $product->id)
            ->get()
            ->keyBy('offer_id');

        if ($rows->contains(fn ($row) => $row->revision > $row->synced_revision || in_array($row->state, [SyncState::Pending->value, SyncState::Failed->value]))) {
            return true;
        }

        if ($eligibility->reason($product, $target)) {
            // Should not be in Google: only act if something is still there.
            return $rows->contains(fn ($row) => $row->state === SyncState::Synced->value);
        }

        $offers = $eligibility->eligibleOffers($mapper->map($product, $target), $target);

        if ($eligibility->stockReason($offers)) {
            return $rows->contains(fn ($row) => $row->state === SyncState::Synced->value);
        }

        $expiresBefore = now()->subDays(max(1, (int) config('google_merchant.resubmit_days', 25) - 1));

        foreach ($offers as $offer) {
            $row = $rows[$offer->offerId] ?? null;

            if (! $row || $row->payload_hash !== $offer->hash) {
                return true; // never seen, or the data changed
            }

            if ($row->state === SyncState::Invalid->value) {
                continue; // unchanged and still invalid: nothing to send
            }

            if ($row->state !== SyncState::Synced->value || ! $row->last_synced_at || $row->last_synced_at->lt($expiresBefore)) {
                return true; // excluded/deleted but eligible again, or close to expiring in Google
            }
        }

        // Offers that are no longer produced but still in Google.
        $current = array_map(fn ($o) => $o->offerId, $offers);

        return $rows->contains(fn ($row) => $row->state === SyncState::Synced->value && ! in_array($row->offer_id, $current, true));
    }

    /** Ledger rows whose root product no longer exists: their root's sync deletes them from Google. */
    protected function queueOrphans(GmcRun $run, SyncDispatcher $dispatcher): void
    {
        GmcProductProxy::modelClass()::query()
            ->where('gmc_target_id', $run->gmc_target_id)
            ->whereNotIn('state', [SyncState::Deleted->value, SyncState::Excluded->value])
            ->whereNotNull('root_product_id')
            ->whereNotExists(fn ($q) => $q->from('products')->whereColumn('products.id', 'gmc_products.root_product_id'))
            ->distinct()
            ->pluck('root_product_id')
            ->each(fn ($rootId) => $dispatcher->dispatchJob((int) $rootId, $run->gmc_target_id, 0, false, $run->id));
    }

    /** Products that own offers: top-level, not grouped. */
    protected function roots()
    {
        return DB::table('products')->whereNull('parent_id')->where('type', '!=', 'grouped');
    }
}
