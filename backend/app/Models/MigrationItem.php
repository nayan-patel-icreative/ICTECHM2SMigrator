<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationItem extends Model
{
    protected $fillable = [
        'migration_run_id',
        'entity_type',
        'source_id',
        'shopify_gid',
        'status',
        'fingerprint',
        'error_message',
        'error_context',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'error_context' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(MigrationRun::class, 'migration_run_id');
    }
}
