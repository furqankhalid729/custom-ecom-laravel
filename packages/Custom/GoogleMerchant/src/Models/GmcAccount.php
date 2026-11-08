<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcAccount as GmcAccountContract;
use Custom\GoogleMerchant\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connected Merchant Center account. Secrets are encrypted at rest and
 * hidden from serialisation; only TokenManager reads them.
 */
class GmcAccount extends Model implements GmcAccountContract
{
    protected $table = 'gmc_accounts';

    protected $fillable = [
        'merchant_id',
        'display_name',
        'auth_type',
        'google_email',
        'developer_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'service_account_json',
        'status',
        'last_error',
        'developer_registered_at',
        'connected_by',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'service_account_json'];

    protected $casts = [
        'access_token'            => 'encrypted',
        'refresh_token'           => 'encrypted',
        'service_account_json'    => 'encrypted',
        'token_expires_at'        => 'datetime',
        'developer_registered_at' => 'datetime',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(GmcTargetProxy::modelClass(), 'gmc_account_id');
    }

    public function isConnected(): bool
    {
        return $this->status === AccountStatus::Connected->value;
    }
}
