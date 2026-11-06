<?php

namespace Custom\StorePolicy\Models;

use Custom\StorePolicy\Contracts\PolicySetting as PolicySettingContract;
use Illuminate\Database\Eloquent\Model;

class PolicySetting extends Model implements PolicySettingContract
{
    /**
     * Settings groups, each stored as a JSON column.
     */
    public const GROUPS = ['business', 'payment', 'privacy', 'terms', 'regions'];

    protected $table = 'store_policy_settings';

    protected $fillable = ['channel_id', 'business', 'payment', 'privacy', 'terms', 'regions'];

    protected $casts = [
        'business' => 'array',
        'payment'  => 'array',
        'privacy'  => 'array',
        'terms'    => 'array',
        'regions'  => 'array',
    ];

    /**
     * All settings groups as one array.
     */
    public function toSettingsArray(): array
    {
        $settings = [];

        foreach (self::GROUPS as $group) {
            $settings[$group] = $this->{$group} ?? [];
        }

        return $settings;
    }
}
