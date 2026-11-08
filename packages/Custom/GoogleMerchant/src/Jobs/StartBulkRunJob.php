<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Enums\RunType;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Services\BulkSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Lists one page of ids after the run's cursor, hands them out as chunk jobs,
 * moves the cursor and re-dispatches itself. Never loads models.
 */
class StartBulkRunJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsOnGmcQueue;

    public const PAGE = 500;

    public const CHUNK = 50;

    public function __construct(public int $runId)
    {
        $this->useGmcQueue();
    }

    public function handle(): void
    {
        $run = GmcRunProxy::modelClass()::find($this->runId);

        if (! $run || $run->status !== RunStatus::Running->value) {
            return;
        }

        $bulk = app(BulkSyncService::class);

        $deleting = $run->type === RunType::DeleteAll->value;

        $query = $deleting
            ? $bulk->deletableQuery($run->gmc_target_id)->select('gmc_products.id')
            : $bulk->rootQuery($run->options['filters'] ?? [])->select('products.id');

        $column = $deleting ? 'gmc_products.id' : 'products.id';

        $ids = $query->where($column, '>', $run->cursor)->orderBy($column)->limit(self::PAGE)->pluck('id')->map(fn ($id) => (int) $id);

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            BulkSyncChunkJob::dispatch($run->id, $chunk->values()->all());
        }

        $options = $run->options;
        $options['listed'] = $ids->count() < self::PAGE;

        $run->forceFill(['cursor' => $ids->last() ?? $run->cursor, 'options' => $options])->save();

        if (! $options['listed']) {
            static::dispatch($run->id);
        } elseif ($run->processed >= $run->total) {
            $run->forceFill(['status' => RunStatus::Completed->value, 'finished_at' => now()])->save();
        }
    }
}
