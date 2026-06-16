<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyIdMapping extends Model
{
    protected $fillable = [
        'shop_id',
        'entity_type',
        'source_id',
        'shopify_gid',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
