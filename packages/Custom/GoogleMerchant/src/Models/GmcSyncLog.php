<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcSyncLog as GmcSyncLogContract;
use Illuminate\Database\Eloquent\Model;

class GmcSyncLog extends Model implements GmcSyncLogContract
{
    protected $table = 'gmc_sync_logs';

    const UPDATED_AT = null;

    protected $fillable = [
        'gmc_product_id',
        'product_id',
        'gmc_target_id',
        'offer_id',
        'operation',
        'status',
        'http_status',
        'response_excerpt',
        'error_message',
        'attempt',
        'duration_ms',
        'gmc_run_id',
    ];
}
