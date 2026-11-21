<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcRun as GmcRunContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmcRun extends Model implements GmcRunContract
{
    protected $table = 'gmc_runs';

    protected $fillable = [
        'type',
        'status',
        'gmc_target_id',
        'options',
        'total',
        'processed',
        'succeeded',
        'failed',
        'cursor',
        'started_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total' => 'integer',
        'processed' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'cursor' => 'integer',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(GmcTargetProxy::modelClass(), 'gmc_target_id');
    }
}
