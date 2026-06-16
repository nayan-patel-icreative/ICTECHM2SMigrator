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

class RunMarketMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    private int $runId;

    /** @var string[]|null */
    private ?array $channelIds;

    public function __construct(int $runId, ?array $channelIds = null)
    {
        $this->runId = $runId;
        $this->channelIds = $channelIds;
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

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $channels = $magento->getStoreViews($conn);

            // Apply channel ID filter if provided
            if (is_array($this->channelIds) && count($this->channelIds) > 0) {
                $allowedIds = array_flip($this->channelIds);
                $channels = array_values(array_filter($channels, function ($c) use ($allowedIds) {
                    return isset($allowedIds[trim((string) ($c['id'] ?? ''))]);
                }));
            }

            if (empty($channels)) {
                FinalizeMarketMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

                return;
            }

            foreach ($channels as $c) {
                $sourceId = trim((string) ($c['id'] ?? ''));
                if ($sourceId === '') {
                    continue;
                }
                ProcessMarketMigrationItemJob::dispatch($run->id, $sourceId);
            }

            FinalizeMarketMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

        } catch (\Throwable $e) {
            Log::error('Market migration run failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();
        }
    }
}
