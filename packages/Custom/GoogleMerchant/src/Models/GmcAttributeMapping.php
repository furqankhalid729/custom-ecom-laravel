<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcAttributeMapping as GmcAttributeMappingContract;
use Illuminate\Database\Eloquent\Model;

class GmcAttributeMapping extends Model implements GmcAttributeMappingContract
{
    protected $table = 'gmc_attribute_mappings';

    protected $fillable = ['gmc_target_id', 'google_field', 'source_type', 'source_value'];
}
