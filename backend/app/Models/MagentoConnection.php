<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MagentoConnection extends Model
{
    protected $fillable = [
        'shop_id',
        'api_url',
        'access_token',
        'store_view_code',
        'store_view_name',
        'language_config',
        'files_path',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'language_config' => 'array',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
