<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Services\Migration\MigrationRunReportWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeMarketMigrationRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    private int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->find($this->runId);
        if (! $run || in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $remaining = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'market')
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($remaining) {
            self::dispatch($run->id)->delay(now()->addSeconds(3));

            return;
        }

        $processed = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'market')
            ->whereIn('status', ['succeeded', 'failed', 'skipped'])
            ->count();

        $succeeded = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'market')
            ->where('status', 'succeeded')
            ->count();

        $failed = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'market')
            ->where('status', 'failed')
            ->count();

        $run->processed = $processed;
        $run->succeeded = $succeeded;
        $run->failed = $failed;
        $run->status = 'finished';
        $run->finished_at = now();
        $run->save();

        app(MigrationRunReportWriter::class)->finalize($run->id);
    }
}
