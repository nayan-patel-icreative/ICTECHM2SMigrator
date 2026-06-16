<?php

namespace App\Services\Migration;

use App\Jobs\RunManufacturerMigrationJob;
use App\Models\MigrationRun;
use App\Models\Shop;

class ManufacturerMigrationService
{
    public function start(Shop $shop): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'manufacturers')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'manufacturers',
            'status' => 'queued',
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run);

        RunManufacturerMigrationJob::dispatch($run->id);

        return $run;
    }

    public function status(Shop $shop): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'manufacturers')
            ->orderByDesc('id')
            ->first();
    }

    public function cancel(Shop $shop): bool
    {
        $run = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'manufacturers')
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return false;
        }

        $run->status = 'cancelled';
        $run->finished_at = now();
        $run->save();
        app(MigrationRunReportWriter::class)->finalize($run->id);

        return true;
    }
}
