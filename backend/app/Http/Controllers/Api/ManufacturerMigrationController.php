<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Migration\ManufacturerFingerprint;
use App\Services\Migration\ManufacturerMigrationService;
use App\Services\QueueHealthService;
use App\Services\Magento\MagentoClient;
use Illuminate\Http\Request;

class ManufacturerMigrationController extends Controller
{
    private ManufacturerMigrationService $service;

    public function __construct(ManufacturerMigrationService $service)
    {
        $this->service = $service;
    }

    public function preview(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->magentoConnection : null;

        if (! $shop || ! $conn) {
            return response()->json(['error' => 'Missing Magento connection'], 422);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $magento = app(MagentoClient::class);
        $fingerprints = app(ManufacturerFingerprint::class);

        $res = $magento->searchManufacturers($conn, 50, $page);
        $rows = $res['manufacturers'] ?? [];

        $items = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, $limit) as $m) {
            if (! is_array($m)) {
                continue;
            }
            $sourceId = trim((string) data_get($m, 'id', ''));
            if ($sourceId === '') {
                continue;
            }
            $name = trim((string) (data_get($m, 'name') ?: ''));
            $items[] = [
                'source_id' => $sourceId,
                'name' => $name,
                'vendor' => $name,
                'has_media' => false,
                'fingerprint' => $fingerprints->make($m),
            ];
        }

        return response()->json([
            'page' => $page,
            'total' => (int) ($res['total'] ?? 0),
            'items' => $items,
        ]);
    }

    public function status(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $run = $this->service->status($shop);

        if (! $run) {
            return response()->json(['run' => null]);
        }

        $recentFailed = $run->items()
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'source_id', 'error_message', 'error_context', 'finished_at']);

        $recentFailedOut = [];
        foreach ($recentFailed as $it) {
            $recentFailedOut[] = [
                'id' => $it->id,
                'source_id' => $it->source_id,
                'error_message' => $it->error_message,
                'error_context' => $it->error_context,
                'finished_at' => $it->finished_at,
            ];
        }

        $durationSeconds = null;
        if ($run->started_at) {
            $end = $run->finished_at ?: now();
            $durationSeconds = max(0, $run->started_at->diffInSeconds($end));
        }

        return response()->json([
            'run' => [
                'id' => $run->id,
                'type' => $run->type,
                'status' => $run->status,
                'processed' => $run->processed,
                'succeeded' => $run->succeeded,
                'failed' => $run->failed,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'duration_seconds' => $durationSeconds,
                'report_available' => is_string($run->report_path) && trim((string) $run->report_path) !== '' && is_file((string) $run->report_path),
                'report_download_url' => '/api/migration/runs/' . $run->id . '/report',

            ],
            'recent_failed_items' => $recentFailedOut,
        ]);
    }

    public function start(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $queueHealth = app(QueueHealthService::class);
        if (! $queueHealth->probe()) {
            return response()->json([
                'error' => 'Queue worker is not running. Migration cannot start until the worker process is online.',
            ], 409);
        }

        $run = $this->service->start($shop);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
        ], 202);
    }

    public function cancel(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $cancelled = $this->service->cancel($shop);

        return response()->json(['cancelled' => $cancelled]);
    }
}
