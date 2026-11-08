<?php

namespace Custom\GoogleMerchant\Sync;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Jobs\DeleteOfferJob;
use Custom\GoogleMerchant\Jobs\SyncProductJob;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;
use Custom\GoogleMerchant\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only way work enters the gmc queue. Cheap enough for a request:
 * a few queries plus a queue insert, never a Google call.
 */
class SyncDispatcher
{
    /** Reasons that come from store events and obey "Sync automatically". */
    public const AUTOMATIC_REASONS = ['save', 'stock', 'import', 'delete'];

    public function __construct(
        protected Settings $settings,
        protected GmcProductRepository $ledger,
    ) {}

    /**
     * @param  int[]  $productIds  any store product ids (variants, bundle children, grouped...)
     * @return int jobs dispatched
     */
    public function queue(array $productIds, string $reason, ?int $delay = null, bool $force = false): int
    {
        if (! $this->settings->enabled()) {
            return 0;
        }

        if (in_array($reason, self::AUTOMATIC_REASONS, true) && ! $this->settings->bool('settings.auto_sync', true)) {
            return 0;
        }

        $targets = $this->targets();

        if ($targets->isEmpty()) {
            return 0;
        }

        $delay ??= max(0, $this->settings->int('settings.sync_delay', 30));

        $count = 0;

        foreach ($this->rootsFor($productIds) as $rootId) {
            foreach ($targets as $target) {
                $this->ledger->markPending($rootId, $target->id);

                $this->dispatchJob($rootId, $target->id, $delay, $force);

                $count++;
            }
        }

        return $count;
    }

    public function dispatchJob(int $rootId, int $targetId, ?int $delay = null, bool $force = false, ?int $runId = null): void
    {
        $delay ??= max(0, $this->settings->int('settings.sync_delay', 30));

        SyncProductJob::dispatch($rootId, $targetId, $force, $runId)->delay($delay)->afterCommit();
    }

    public function queueDelete(int $gmcProductId): void
    {
        DeleteOfferJob::dispatch($gmcProductId)->afterCommit();
    }

    /**
     * Store product ids → the roots that own their offers:
     * variant → configurable parent; grouped → its children; bundle children → also the bundle.
     *
     * @param  int[]  $productIds
     * @return int[]
     */
    public function rootsFor(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if (! $ids) {
            return [];
        }

        $products = DB::table('products')->whereIn('id', $ids)->get(['id', 'type', 'parent_id'])->keyBy('id');

        $roots = [];

        foreach ($ids as $id) {
            $product = $products[$id] ?? null;

            if (! $product) {
                $roots[] = $id; // deleted: its root's job cleans up

                continue;
            }

            if ($product->parent_id) {
                $roots[] = (int) $product->parent_id;
            } elseif ($product->type !== 'grouped') {
                $roots[] = $id;
            }
        }

        $grouped = $products->where('type', 'grouped')->keys()->all();

        if ($grouped) {
            array_push($roots, ...DB::table('product_grouped_products')->whereIn('product_id', $grouped)->pluck('associated_product_id')->map(fn ($i) => (int) $i)->all());
        }

        array_push($roots, ...DB::table('product_bundle_option_products as bop')
            ->join('product_bundle_options as bo', 'bo.id', '=', 'bop.product_bundle_option_id')
            ->whereIn('bop.product_id', $ids)
            ->pluck('bo.product_id')
            ->map(fn ($i) => (int) $i)
            ->all());

        return array_values(array_unique($roots));
    }

    /** Enabled targets with a data source on a connected account. */
    protected function targets(): Collection
    {
        return GmcTargetProxy::modelClass()::query()
            ->where('enabled', true)
            ->whereNotNull('data_source_name')
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Connected->value))
            ->get();
    }
}
