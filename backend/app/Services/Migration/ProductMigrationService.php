<?php

namespace App\Services\Migration;

use App\Jobs\RunProductMigrationJob;
use App\Models\MigrationRun;
use App\Models\Shop;

class ProductMigrationService
{
    public function start(Shop $shop, string $locationGid): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'products')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'products',
            'status' => 'queued',
            'shopify_location_gid' => $locationGid,
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run);

        RunProductMigrationJob::dispatch($run->id);

        return $run;
    }

    /**
     * @param array<int, mixed> $filter
     */
    public function startFiltered(Shop $shop, string $locationGid, array $filter): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'products')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'products',
            'status' => 'queued',
            'shopify_location_gid' => $locationGid,
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run, ['filters' => $filter]);

        RunProductMigrationJob::dispatch($run->id, 1, $filter);

        return $run;
    }

    public function status(Shop $shop): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'products')
            ->orderByDesc('id')
            ->first();
    }

    public function cancel(Shop $shop): bool
    {
        $run = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'products')
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')
            ->first();

        if (!$run) {
            return false;
        }

        $run->status = 'cancelled';
        $run->finished_at = now();
        $run->save();
        app(MigrationRunReportWriter::class)->finalize($run->id);

        return true;
    }
}
