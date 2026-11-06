<?php

namespace Custom\StorePolicy\Models;

use Custom\StorePolicy\Contracts\PolicyGeneration as PolicyGenerationContract;
use Illuminate\Database\Eloquent\Model;

class PolicyGeneration extends Model implements PolicyGenerationContract
{
    protected $table = 'store_policy_generations';

    public $timestamps = false;

    protected $fillable = [
        'cms_page_id',
        'locale',
        'template_key',
        'generated_html_hash',
        'settings_hash',
        'previous_html',
        'generated_at',
        'generated_by',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];
}
