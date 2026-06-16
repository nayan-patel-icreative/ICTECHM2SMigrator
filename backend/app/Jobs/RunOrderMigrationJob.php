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

class RunOrderMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 20;

    private int $runId;

    private int $page;

    /** @var array<int, mixed> */
    private array $filter;

    /**
     * @param array<int, mixed> $filter
     */
    public function __construct(int $runId, int $page = 1, array $filter = [])
    {
        $this->runId = $runId;
        $this->page = max(1, $page);
        $this->filter = $filter;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.magentoConnection')->find($this->runId);
        if (!$run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        try {
            $shop = $run->shop;
            $conn = $shop ? $shop->magentoConnection : null;

            if (!$shop || !$conn) {
                $run->status = 'failed';
                $run->finished_at = now();
                $run->save();
                return;
            }

            $run->status = 'running';
            $run->save();

            $magento = app(MagentoClient::class);

            $perPage = 50;

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $res = $magento->searchOrders($conn, $perPage, $this->page, $this->filter);
            $orders = $res['orders'] ?? [];
            $total = (int) ($res['total'] ?? 0);

            if (!is_array($orders) || count($orders) === 0) {
                // No more pages. Finalize once all queued/running items finish.
                FinalizeOrderMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(10));
                return;
            }

            foreach ($orders as $o) {
                // SAFE CHANGE: fan-out per order to enable controlled parallel processing.
                if (is_array($o)) {
                    ProcessOrderMigrationItemJob::dispatch($run->id, $o);
                }
            }

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $processedPageCount = count($orders);
            $hasMore = ($total > 0 && ($this->page * $perPage) < $total)
                || $processedPageCount >= $perPage;
            if ($hasMore) {
                self::dispatch($run->id, $this->page + 1, $this->filter);
                return;
            }

            // Last page dispatched. Finalize once all items finish.
            FinalizeOrderMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(10));
        } catch (\Throwable $e) {
            Log::error('Order migration run failed', [
                'run_id' => $run->id,
                'shop' => optional($run->shop)->shop_domain,
                'error' => $e->getMessage(),
            ]);

            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();
        }
    }

    public function failed(\Throwable $e): void
    {
        $run = MigrationRun::query()->find($this->runId);
        if (!$run || in_array($run->status, ['cancelled', 'finished'], true)) {
            return;
        }

        Log::error('Order migration job exhausted retries', [
            'run_id' => $this->runId,
            'page' => $this->page,
            'error' => $e->getMessage(),
        ]);

        $run->status = 'failed';
        $run->finished_at = now();
        $run->save();
    }
}
