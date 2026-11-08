<?php

namespace Custom\GoogleMerchant\Sync;

use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Enums\Operation;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcSyncLogProxy;

/**
 * Append-only history of every sync attempt (gmc_sync_logs).
 */
class SyncLogger
{
    public function log(
        GmcProduct $row,
        Operation $operation,
        string $status,
        ?\Throwable $error = null,
        int $durationMs = 0,
        ?int $runId = null,
        ?string $message = null,
    ): void {
        GmcSyncLogProxy::modelClass()::create([
            'gmc_product_id'   => $row->id,
            'product_id'       => $row->product_id,
            'gmc_target_id'    => $row->gmc_target_id,
            'offer_id'         => $row->offer_id,
            'operation'        => $operation->value,
            'status'           => $status,
            'http_status'      => $error instanceof GmcApiException ? $error->httpStatus : null,
            'response_excerpt' => $error instanceof GmcApiException ? $error->excerpt : null,
            'error_message'    => $message ?? ($error ? mb_strcut($error->getMessage(), 0, 1000) : null),
            'attempt'          => (int) $row->attempts,
            'duration_ms'      => $durationMs,
            'gmc_run_id'       => $runId,
        ]);
    }
}
