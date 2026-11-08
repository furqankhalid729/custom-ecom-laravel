<?php

namespace Custom\GoogleMerchant\Models;

use Custom\GoogleMerchant\Contracts\GmcCategoryMapping as GmcCategoryMappingContract;
use Illuminate\Database\Eloquent\Model;

class GmcCategoryMapping extends Model implements GmcCategoryMappingContract
{
    protected $table = 'gmc_category_mappings';

    protected $fillable = ['category_id', 'google_product_category', 'excluded'];

    protected $casts = ['excluded' => 'boolean'];
}
