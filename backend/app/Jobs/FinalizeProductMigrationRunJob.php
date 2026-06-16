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

class FinalizeProductMigrationRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    private int $runId;

    /**
     * SAFE CHANGE: Finishes the run only when there are no remaining queued/running items.
     * This allows RunProductMigrationJob to fan-out work in parallel without marking the run
     * finished too early.
     */
    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $remaining = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'product')
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($remaining) {
            self::dispatch($run->id)->delay(now()->addSeconds(3));

            return;
        }

        $processed = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'product')
            ->whereIn('status', ['succeeded', 'failed', 'skipped'])
            ->count();

        $succeeded = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'product')
            ->where('status', 'succeeded')
            ->count();

        $failed = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'product')
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
