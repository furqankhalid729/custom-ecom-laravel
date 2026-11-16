<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Diagnostics\StatusParser;
use Custom\GoogleMerchant\Enums\RunStatus;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Reads one products.list page of Google's processed products and stores each
 * offer's approval status and issues, then queues itself for the next page.
 * Offers the store does not know are left to reconciliation.
 */
class RefreshStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnGmcQueue;

    public const PAGE_SIZE = 1000;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public function __construct(
        public int $targetId,
        public ?string $pageToken = null,
        public ?int $runId = null,
    ) {
        $this->useGmcQueue();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function middleware(): array
    {
        return [new EnsureAccountReady];
    }

    public function handle(MerchantApiClient $client, StatusParser $parser): void
    {
        $target = GmcTargetProxy::modelClass()::with('account')->find($this->targetId);
        $run = $this->runId ? GmcRunProxy::modelClass()::find($this->runId) : null;

        if (! $target?->account || ($run && $run->status !== RunStatus::Running->value)) {
            return;
        }

        try {
            $page = $client->listProducts($target->account, $this->pageToken, self::PAGE_SIZE);
        } catch (TransientApiException $e) {
            $this->release($e->retryAfter + random_int(0, 15));

            return;
        } catch (AuthException) {
            $this->release(EnsureAccountReady::REAUTH_RELEASE_SECONDS);

            return;
        }

        $updated = 0;
        $now = now();

        foreach ($page['products'] as $product) {
            if (($product['contentLanguage'] ?? null) !== $target->content_language
                || ($product['feedLabel'] ?? null) !== $target->feed_label
                || ! isset($product['offerId'])) {
                continue;
            }

            $parsed = $parser->parse($product);
            $status = $parsed['google_status']->value; // enum value: safe to inline below

            $updated += GmcProductProxy::modelClass()::query()
                ->where('gmc_target_id', $target->id)
                ->where('offer_id', $product['offerId'])
                ->update([
                    // Must come before google_status: MySQL evaluates SET left to right.
                    'google_status_changed_at' => DB::raw("CASE WHEN google_status IS NULL OR google_status <> '{$status}' THEN '{$now->toDateTimeString()}' ELSE google_status_changed_at END"),
                    'google_status'     => $status,
                    'google_issues'     => json_encode($parsed['issues']),
                    'google_checked_at' => $now,
                    'updated_at'        => $now,
                ]);
        }

        $next = $page['nextPageToken'] ?? null;

        if ($run) {
            $run->forceFill(array_filter([
                'processed'   => $run->processed + $updated,
                'status'      => $next ? null : RunStatus::Completed->value,
                'finished_at' => $next ? null : $now,
            ]))->save();
        }

        if ($next) {
            static::dispatch($target->id, $next, $this->runId);
        }
    }
}
