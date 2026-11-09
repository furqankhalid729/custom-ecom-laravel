<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcProduct as GmcProductContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\ProductProxy;

/**
 * Sync ledger: one row per Google offer (product × target). The row keeps the
 * offer_id / product_input_name that were actually sent, so deletes never
 * depend on the current state of the store product.
 */
class GmcProduct extends Model implements GmcProductContract
{
    protected $table = 'gmc_products';

    protected $fillable = [
        'product_id',
        'root_product_id',
        'gmc_target_id',
        'channel_id',
        'offer_id',
        'product_input_name',
        'state',
        'payload_hash',
        'field_hashes',
        'revision',
        'synced_revision',
        'last_synced_at',
        'last_attempt_at',
        'attempts',
        'last_error',
        'error_kind',
        'retry_cycles',
        'validation_errors',
        'google_status',
        'google_issues',
        'google_checked_at',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'field_hashes'      => 'array',
        'google_issues'     => 'array',
        'last_synced_at'    => 'datetime',
        'last_attempt_at'   => 'datetime',
        'google_checked_at' => 'datetime',
        'revision'          => 'integer',
        'synced_revision'   => 'integer',
        'attempts'          => 'integer',
        'retry_cycles'      => 'integer',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(GmcTargetProxy::modelClass(), 'gmc_target_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }
}
