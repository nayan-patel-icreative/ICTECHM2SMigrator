<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Migration\MarketMigrationService;
use App\Services\QueueHealthService;
use App\Services\Magento\MagentoClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketMigrationController extends Controller
{
    private MarketMigrationService $service;

    public function __construct(MarketMigrationService $service)
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $page = (int) ($validated['page'] ?? 1);

        $magento = app(MagentoClient::class);

        $channels = $magento->getStoreViews($conn);
        $total = count($channels);

        $items = [];
        $offset = ($page - 1) * $limit;
        $sliced = array_slice($channels, $offset, $limit);

        foreach ($sliced as $c) {
            $extractedSuffix = preg_replace('/[^a-z0-9-]/i', '', strtolower($c['code']));
            if ($extractedSuffix === '') {
                $extractedSuffix = 'market-' . substr(md5($c['name']), 0, 5);
            }

            $items[] = [
                'source_id' => $c['id'],
                'name' => $c['name'],
                'default_country' => 'None',
                'default_locale' => $c['locale'] ?? 'en-US',
                'countries' => 1,
                'domains' => 1,
                'proposed_subfolder' => '/' . $extractedSuffix,
            ];
        }

        return response()->json([
            'page' => $page,
            'total' => $total,
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

        $validated = $request->validate([
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['string'],
        ]);

        $channelIds = isset($validated['channel_ids']) && is_array($validated['channel_ids'])
            ? array_values(array_filter(array_map('trim', $validated['channel_ids'])))
            : null;

        $run = $this->service->start($shop, $channelIds);

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
