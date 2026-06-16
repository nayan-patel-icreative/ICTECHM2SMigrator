<?php

namespace App\Services\Migration;

use App\Jobs\RunNewsletterMigrationJob;
use App\Models\MigrationRun;
use App\Models\Shop;

class NewsletterMigrationService
{
    /**
     * @return array{ready: bool, messages: array<int, string>, customers: array<string, mixed>}
     */
    public function prerequisites(Shop $shop): array
    {
        $customersRun = $this->latestRun($shop, 'customers');

        $messages = [];
        $customersReady = $this->runCompletedSuccessfully($customersRun);

        if (!$customersReady) {
            $messages[] = 'Complete customer migration successfully before migrating newsletter recipients.';
        }

        return [
            'ready' => $customersReady,
            'messages' => $messages,
            'customers' => [
                'run_status' => $customersRun ? $customersRun->status : null,
                'processed' => $customersRun ? (int) $customersRun->processed : 0,
                'succeeded' => $customersRun ? (int) $customersRun->succeeded : 0,
                'failed' => $customersRun ? (int) $customersRun->failed : 0,
                'ready' => $customersReady,
            ],
        ];
    }

    public function start(Shop $shop): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'newsletter')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'newsletter',
            'status' => 'queued',
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run);

        RunNewsletterMigrationJob::dispatch($run->id);

        return $run;
    }

    public function status(Shop $shop): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'newsletter')
            ->orderByDesc('id')
            ->first();
    }

    public function cancel(Shop $shop): bool
    {
        $run = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'newsletter')
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

    private function latestRun(Shop $shop, string $type): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', $type)
            ->orderByDesc('id')
            ->first();
    }

    private function runCompletedSuccessfully(?MigrationRun $run): bool
    {
        if (!$run || $run->status !== 'finished' || (int) $run->failed > 0) {
            return false;
        }

        return (int) $run->processed > 0;
    }
}
