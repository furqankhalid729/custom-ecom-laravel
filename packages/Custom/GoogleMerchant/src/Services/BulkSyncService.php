<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Jobs\StartBulkRunJob;
use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Sync all / selected / by category / status / date", full or incremental,
 * resumable and safe for large catalogs (ids only, chunked jobs).
 */
class BulkSyncService
{
    public const MODES = ['full', 'incremental'];

    /**
     * @param  array{category_ids?: int[], product_status?: string, updated_from?: string, updated_to?: string, product_ids?: int[], sync_state?: string[], target_id?: int}  $filters
     * @return GmcRun[] one run per target
     */
    public function start(array $filters, string $mode, ?int $adminId): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown sync mode [$mode].");
        }

        return $this->targets($filters['target_id'] ?? null)->map(function ($target) use ($filters, $mode, $adminId) {
            $run = GmcRunProxy::modelClass()::create([
                'type'          => RunType::Bulk->value,
                'status'        => RunStatus::Running->value,
                'gmc_target_id' => $target->id,
                'options'       => ['mode' => $mode, 'filters' => $filters],
                'total'         => $this->rootQuery($filters)->count(),
                'started_by'    => $adminId,
                'started_at'    => now(),
            ]);

            StartBulkRunJob::dispatch($run->id);

            return $run;
        })->all();
    }

    /** @return GmcRun[] */
    public function startDeleteAll(GmcAccount $account, ?int $adminId): array
    {
        return $account->targets()->get()->flatMap(fn ($target) => $this->startDeleteAllForTarget($target, $adminId))->all();
    }

    /** @return GmcRun[] */
    public function startDeleteAllForTarget(GmcTarget $target, ?int $adminId): array
    {
        $run = GmcRunProxy::modelClass()::create([
            'type'          => RunType::DeleteAll->value,
            'status'        => RunStatus::Running->value,
            'gmc_target_id' => $target->id,
            'options'       => ['mode' => 'delete'],
            'total'         => $this->deletableQuery($target->id)->count(),
            'started_by'    => $adminId,
            'started_at'    => now(),
        ]);

        StartBulkRunJob::dispatch($run->id);

        return [$run];
    }

    public function pause(GmcRun $run): void
    {
        if ($run->status === RunStatus::Running->value) {
            $run->forceFill(['status' => RunStatus::Paused->value])->save();
        }
    }

    public function resume(GmcRun $run): void
    {
        if ($run->status === RunStatus::Paused->value) {
            $run->forceFill(['status' => RunStatus::Running->value])->save();

            StartBulkRunJob::dispatch($run->id);
        }
    }

    /**
     * Root products (top level, not grouped) matching the filters.
     */
    public function rootQuery(array $filters): Builder
    {
        $query = DB::table('products')->whereNull('products.parent_id')->where('products.type', '!=', 'grouped');

        if (! empty($filters['product_ids'])) {
            $query->whereIn('products.id', array_map('intval', $filters['product_ids']));
        }

        if (! empty($filters['category_ids'])) {
            $query->whereExists(fn ($q) => $q->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', array_map('intval', $filters['category_ids'])));
        }

        if (in_array($filters['product_status'] ?? 'any', ['enabled', 'disabled'], true)) {
            $statusId = DB::table('attributes')->where('code', 'status')->value('id');
            $wanted = $filters['product_status'] === 'enabled';

            $exists = fn ($q) => $q->from('product_attribute_values as pav')
                ->whereColumn('pav.product_id', 'products.id')
                ->where('pav.attribute_id', $statusId)
                ->where('pav.boolean_value', 1);

            $wanted ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }

        if (! empty($filters['updated_from'])) {
            $query->where('products.updated_at', '>=', $filters['updated_from'].' 00:00:00');
        }

        if (! empty($filters['updated_to'])) {
            $query->where('products.updated_at', '<=', $filters['updated_to'].' 23:59:59');
        }

        if (! empty($filters['sync_state'])) {
            $query->whereExists(fn ($q) => $q->from('gmc_products')
                ->whereColumn('gmc_products.root_product_id', 'products.id')
                ->whereIn('gmc_products.state', (array) $filters['sync_state']));
        }

        return $query;
    }

    /** Ledger rows that are (or may be) in Google for a target. */
    public function deletableQuery(int $targetId): Builder
    {
        return DB::table('gmc_products')
            ->where('gmc_target_id', $targetId)
            ->whereNotNull('product_input_name')
            ->whereNotNull('last_synced_at')
            ->whereNotIn('state', [SyncState::Deleted->value]);
    }

    protected function targets(?int $targetId): Collection
    {
        return GmcTargetProxy::modelClass()::query()
            ->when($targetId, fn ($q) => $q->whereKey($targetId))
            ->where('enabled', true)
            ->whereNotNull('data_source_name')
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Connected->value))
            ->get();
    }
}
