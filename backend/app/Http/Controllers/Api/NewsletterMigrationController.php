<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartNewsletterMigrationRequest;
use App\Models\Shop;
use App\Services\Migration\NewsletterMigrationService;
use App\Services\Migration\NewsletterRecipientFingerprint;
use App\Services\Migration\NewsletterRecipientPayloadMapper;
use App\Services\QueueHealthService;
use App\Services\Magento\MagentoClient;
use Illuminate\Http\Request;

class NewsletterMigrationController extends Controller
{
    private NewsletterMigrationService $service;

    public function __construct(NewsletterMigrationService $service)
    {
        $this->service = $service;
    }

    public function preview(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->magentoConnection : null;

        if (!$shop || !$conn) {
            return response()->json(['error' => 'Missing Magento connection'], 422);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'include_payload' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 5);
        $page = (int) ($validated['page'] ?? 1);
        $includePayload = (bool) ($validated['include_payload'] ?? false);

        $magento = app(MagentoClient::class);
        $mapper = app(NewsletterRecipientPayloadMapper::class);
        $fingerprints = app(NewsletterRecipientFingerprint::class);

        $res = $magento->searchNewsletterRecipients($conn, 50, $page);
        $rows = $res['recipients'] ?? [];

        if (!is_array($rows) || count($rows) === 0) {
            return response()->json([
                'page' => $page,
                'total' => (int) ($res['total'] ?? 0),
                'items' => [],
            ]);
        }

        $items = [];
        foreach (array_slice($rows, 0, $limit) as $r) {
            if (!is_array($r)) {
                continue;
            }

            $sourceId = trim((string) ($r['id'] ?? ''));
            $email = $mapper->email($r);
            $active = $mapper->isActiveRecipient($r);
            $payload = $mapper->mapToShopifyCustomerPayload($r, $active, $shop);
            $fp = $fingerprints->make($payload);

            $out = [
                'source_id' => $sourceId !== '' ? $sourceId : null,
                'email' => $email,
                'name' => trim(((string) ($r['firstName'] ?? '')).' '.((string) ($r['lastName'] ?? ''))),
                'active' => $active,
                'fingerprint' => $fp,
            ];

            if ($includePayload) {
                $out['payload'] = $payload;
                $out['shopware_raw'] = $r;
            }

            $items[] = $out;
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

        $prerequisites = $this->service->prerequisites($shop);

        if (!$run) {
            return response()->json([
                'run' => null,
                'prerequisites' => $prerequisites,
            ]);
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
            'prerequisites' => $prerequisites,
        ]);
    }

    public function start(StartNewsletterMigrationRequest $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $queueHealth = app(QueueHealthService::class);
        $workerOnline = $queueHealth->probe();
        if (!$workerOnline) {
            return response()->json([
                'error' => 'Queue worker is not running. Migration cannot start until the worker process is online.',
            ], 409);
        }

        $prerequisites = $this->service->prerequisites($shop);
        if (($prerequisites['ready'] ?? false) !== true) {
            return response()->json([
                'error' => 'Newsletter migration prerequisites are not ready.',
                'prerequisites' => $prerequisites,
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
