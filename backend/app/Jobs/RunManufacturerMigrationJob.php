<?php

namespace App\Jobs;

use App\Models\MigrationRun;
use App\Services\Magento\MagentoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunManufacturerMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    private int $runId;

    private int $page;

    public function __construct(int $runId, int $page = 1)
    {
        $this->runId = $runId;
        $this->page = max(1, $page);
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.magentoConnection')->find($this->runId);
        if (! $run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        try {
            $shop = $run->shop;
            $conn = $shop ? $shop->magentoConnection : null;

            if (! $shop || ! $conn) {
                $run->status = 'failed';
                $run->finished_at = now();
                $run->save();

                return;
            }

            $run->status = 'running';
            $run->save();

            $magento = app(MagentoClient::class);
            $perPage = 100;

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $res = $magento->searchManufacturers($conn, $perPage, $this->page);
            $rows = $res['manufacturers'] ?? [];

            if (! is_array($rows) || count($rows) === 0) {
                FinalizeManufacturerMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

                return;
            }

            foreach ($rows as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $sourceId = trim((string) data_get($m, 'id', ''));
                if ($sourceId === '') {
                    continue;
                }
                ProcessManufacturerMigrationItemJob::dispatch($run->id, $sourceId);
            }

            $total = (int) ($res['total'] ?? 0);
            $hasMore = ($this->page * $perPage) < $total;
            if ($hasMore) {
                self::dispatch($run->id, $this->page + 1);
            } else {
                FinalizeManufacturerMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));
            }
        } catch (\Throwable $e) {
            Log::error('Manufacturer migration run page failed', [
                'run_id' => $this->runId,
                'page' => $this->page,
                'error' => $e->getMessage(),
            ]);
            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();
        }
    }
}
