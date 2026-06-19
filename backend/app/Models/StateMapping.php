<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StateMapping extends Model
{
    protected $fillable = [
        'shop_id',
        'state_type',
        'magento_state',
        'shopify_status',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
