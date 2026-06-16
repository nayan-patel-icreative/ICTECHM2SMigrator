<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = [
        'shop_domain',
        'access_token',
        'scopes',
        'installed_at',
        'uninstalled_at',
        'price_mode',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    public function magentoConnection()
    {
        return $this->hasOne(MagentoConnection::class);
    }
}
