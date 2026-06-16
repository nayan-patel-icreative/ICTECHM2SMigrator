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

class RunNewsletterMigrationJob implements ShouldQueue
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
        $this->runId   = $runId;
        $this->page    = max(1, $page);
        $this->filter  = $filter;
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

            $perPage = 100;

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            // Scope to a store view if specified.
            $scopedFilter = $this->filter;

            $res = $magento->searchNewsletterRecipients($conn, $perPage, $this->page, $scopedFilter);
            $rows = $res['recipients'] ?? [];
            $total = (int) ($res['total'] ?? 0);

            if (!is_array($rows) || count($rows) === 0) {
                FinalizeNewsletterMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(10));
                return;
            }

            foreach ($rows as $r) {
                if (is_array($r)) {
                    ProcessNewsletterRecipientJob::dispatch($run->id, $r);
                }
            }

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $processedPageCount = count($rows);
            $hasMore = ($total > 0 && ($this->page * $perPage) < $total) || $processedPageCount >= $perPage;
            if ($hasMore) {
                self::dispatch($run->id, $this->page + 1, $this->filter);
                return;
            }

            FinalizeNewsletterMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(10));
        } catch (\Throwable $e) {
            Log::error('Newsletter migration run failed', [
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

        Log::error('Newsletter migration job exhausted retries', [
            'run_id' => $this->runId,
            'page' => $this->page,
            'error' => $e->getMessage(),
        ]);

        $run->status = 'failed';
        $run->finished_at = now();
        $run->save();
    }
}
