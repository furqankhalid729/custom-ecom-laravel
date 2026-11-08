<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcTarget as GmcTargetContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One market (country set + language + currency) = one Merchant API data source.
 */
class GmcTarget extends Model implements GmcTargetContract
{
    protected $table = 'gmc_targets';

    protected $fillable = [
        'gmc_account_id',
        'channel_id',
        'locale',
        'content_language',
        'feed_label',
        'countries',
        'currency',
        'data_source_name',
        'enabled',
    ];

    protected $casts = [
        'countries' => 'array',
        'enabled'   => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GmcAccountProxy::modelClass(), 'gmc_account_id');
    }
}
