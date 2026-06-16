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

class FinalizeOrderMigrationRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    private int $runId;

    /**
     * SAFE CHANGE: Mark the order run as finished only once all queued/running order items are complete.
     */
    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->find($this->runId);
        if (!$run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $remaining = MigrationItem::query()
            ->where('migration_run_id', $run->id)
            ->where('entity_type', 'order')
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($remaining) {
            self::dispatch($run->id)->delay(now()->addSeconds(15));
            return;
        }

        $run->status = 'finished';
        $run->finished_at = now();
        $run->save();
        app(MigrationRunReportWriter::class)->finalize($run->id);
    }
}
