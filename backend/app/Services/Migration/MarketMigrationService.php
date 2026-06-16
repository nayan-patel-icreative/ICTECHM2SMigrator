<?php

namespace App\Services\Migration;

use App\Jobs\RunMarketMigrationJob;
use App\Models\MigrationRun;
use App\Models\Shop;

class MarketMigrationService
{
    public function start(Shop $shop, ?array $channelIds = null): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'markets')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'markets',
            'status' => 'queued',
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run);

        RunMarketMigrationJob::dispatch($run->id, $channelIds);

        return $run;
    }

    public function status(Shop $shop): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'markets')
            ->orderByDesc('id')
            ->first();
    }

    public function cancel(Shop $shop): bool
    {
        $run = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'markets')
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
