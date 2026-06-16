<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationRun extends Model
{
    protected $fillable = [
        'shop_id',
        'type',
        'status',
        'shopify_location_gid',
        'report_path',
        'processed',
        'succeeded',
        'failed',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function items()
    {
        return $this->hasMany(MigrationItem::class);
    }
}
