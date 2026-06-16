<?php

namespace App\Services\Migration;

use App\Jobs\RunCustomerMigrationJob;
use App\Models\MigrationRun;
use App\Models\Shop;

class CustomerMigrationService
{
    public function start(Shop $shop): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'customers')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'customers',
            'status' => 'queued',
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run);

        RunCustomerMigrationJob::dispatch($run->id);

        return $run;
    }

    /**
     * @param array<int, mixed> $filter
     */
    public function startFiltered(Shop $shop, array $filter): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'customers')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'customers',
            'status' => 'queued',
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run, ['filters' => $filter]);

        RunCustomerMigrationJob::dispatch($run->id, 1, $filter);

        return $run;
    }

    public function status(Shop $shop): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'customers')
            ->orderByDesc('id')
            ->first();
    }

    public function cancel(Shop $shop): bool
    {
        $run = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'customers')
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
