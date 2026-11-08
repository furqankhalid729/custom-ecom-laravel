<?php

namespace Custom\GoogleMerchant\Repositories;

use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Sync\IdentityConflict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sync ledger. One row per Google offer; a row is never re-keyed.
 */
class GmcProductRepository
{
    public function query(): Builder
    {
        return GmcProductProxy::modelClass()::query();
    }

    /**
     * Cheap, request-safe: called from listeners. Creates placeholder rows for
     * the root's offers and bumps the revision so a running sync knows to repeat.
     */
    public function markPending(int $rootId, int $targetId): void
    {
        $type = DB::table('products')->where('id', $rootId)->value('type');

        $offers = match ($type) {
            null, 'grouped'  => collect(),
            'configurable'   => DB::table('products')->where('parent_id', $rootId)->get(['id', 'sku']),
            default          => DB::table('products')->where('id', $rootId)->get(['id', 'sku']),
        };

        $now = now();
        $channelId = DB::table('gmc_targets')->where('id', $targetId)->value('channel_id');

        if ($offers->isNotEmpty()) {
            DB::table('gmc_products')->insertOrIgnore($offers->map(fn ($p) => [
                'gmc_target_id'   => $targetId,
                'offer_id'        => $p->sku,
                'product_id'      => $p->id,
                'root_product_id' => $rootId,
                'channel_id'      => $channelId,
                'state'           => SyncState::Pending->value,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->all());
        }

        $this->query()
            ->where('root_product_id', $rootId)
            ->where('gmc_target_id', $targetId)
            ->update([
                'revision'   => DB::raw('revision + 1'),
                'state'      => DB::raw("CASE WHEN state = 'deleting' THEN state ELSE 'pending' END"),
                'updated_at' => $now,
            ]);
    }

    /** @return Collection<int, GmcProduct> */
    public function forRoot(int $rootId, int $targetId): Collection
    {
        return $this->query()->where('root_product_id', $rootId)->where('gmc_target_id', $targetId)->get();
    }

    public function maxRevision(int $rootId, int $targetId): int
    {
        return (int) $this->query()->where('root_product_id', $rootId)->where('gmc_target_id', $targetId)->max('revision');
    }

    /** @return array<string, int> state => count */
    public function counts(int $targetId): array
    {
        return $this->query()
            ->where('gmc_target_id', $targetId)
            ->selectRaw('state, count(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Takes (or keeps) the ledger row for one Google identity.
     *
     * A row is reclaimed when it is being deleted / deleted, has no product, or its
     * product is gone or no longer carries this SKU. A row owned by another live
     * product that still carries the SKU is a conflict and is never touched.
     */
    public function claim(int $targetId, string $offerId, int $productId, int $rootId, ?int $channelId): GmcProduct|IdentityConflict
    {
        return DB::transaction(function () use ($targetId, $offerId, $productId, $rootId, $channelId) {
            $row = $this->query()
                ->where('gmc_target_id', $targetId)
                ->where('offer_id', $offerId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return $this->query()->create([
                    'gmc_target_id'   => $targetId,
                    'offer_id'        => $offerId,
                    'product_id'      => $productId,
                    'root_product_id' => $rootId,
                    'channel_id'      => $channelId,
                    'state'           => SyncState::Pending->value,
                ]);
            }

            if ($row->product_id !== $productId && $this->ownerStillHolds($row, $offerId)) {
                return new IdentityConflict($offerId, $row->product_id);
            }

            $reclaimed = $row->product_id !== $productId || in_array($row->state, [SyncState::Deleting->value, SyncState::Deleted->value]);

            $row->forceFill([
                'product_id'      => $productId,
                'root_product_id' => $rootId,
                'channel_id'      => $channelId,
            ] + ($reclaimed ? ['state' => SyncState::Pending->value] : []))->save();

            return $row;
        });
    }

    protected function ownerStillHolds(GmcProduct $row, string $offerId): bool
    {
        if (! $row->product_id || in_array($row->state, [SyncState::Deleting->value, SyncState::Deleted->value])) {
            return false;
        }

        return DB::table('products')->where('id', $row->product_id)->where('sku', $offerId)->exists();
    }
}
