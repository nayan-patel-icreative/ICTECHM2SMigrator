<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartOrderMigrationRequest;
use App\Models\Shop;
use App\Services\Migration\OrderFingerprint;
use App\Services\Migration\OrderMigrationService;
use App\Services\Migration\OrderPayloadMapper;
use App\Services\QueueHealthService;
use App\Services\Magento\MagentoClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class OrderMigrationController extends Controller
{
    private OrderMigrationService $service;

    public function __construct(OrderMigrationService $service)
    {
        $this->service = $service;
    }

    public function previewFiltered(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->magentoConnection : null;

        if (!$shop || !$conn) {
            return response()->json(['error' => 'Missing Magento connection'], 422);
        }

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:after,between'],
            'after' => ['nullable', 'date_format:Y-m-d'],
            'before' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'include_payload' => ['nullable', 'boolean'],
        ]);

        $mode = (string) $validated['mode'];
        $after = isset($validated['after']) ? (string) $validated['after'] : null;
        $before = isset($validated['before']) ? (string) $validated['before'] : null;
        $filter = $this->buildCreatedAtFilter($mode, $after, $before);
        if (isset($filter['error'])) {
            return response()->json(['error' => $filter['error']], 422);
        }

        $limit = (int) ($validated['limit'] ?? 5);
        $page = (int) ($validated['page'] ?? 1);
        $includePayload = (bool) ($validated['include_payload'] ?? false);

        $magento = app(MagentoClient::class);
        $mapper = app(OrderPayloadMapper::class);
        $fingerprints = app(OrderFingerprint::class);

        $res = $magento->searchOrders($conn, 50, $page, $filter);
        $orders = $res['orders'] ?? [];
        if (!is_array($orders) || count($orders) === 0) {
            return response()->json([
                'page' => $page,
                'total' => (int) ($res['total'] ?? 0),
                'items' => [],
            ]);
        }

        $items = [];
        foreach (array_slice($orders, 0, $limit) as $o) {
            $sourceId = (string) ($o['entity_id'] ?? $o['id'] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $mapped = $mapper->mapOrder($shop, $o);
            $payload = $mapped['order'];
            $fp = $fingerprints->make($payload);

            $out = [
                'source_id' => $sourceId,
                'order_number' => (string) ($o['increment_id'] ?? $o['orderNumber'] ?? ''),
                'email' => (string) data_get($payload, 'email', ''),
                'amount_total' => data_get($o, 'grand_total') ?? data_get($o, 'amountTotal'),
                'currency' => (string) data_get($payload, 'currency', ''),
                'line_items_count' => is_array(data_get($payload, 'lineItems')) ? count((array) data_get($payload, 'lineItems')) : 0,
                'fingerprint' => $fp,
            ];

            if ($includePayload) {
                $out['payload'] = $payload;
                $out['shopware_metafields'] = [];
                $out['shopware_raw'] = $mapped['magento_raw'] ?? [];
            }

            $items[] = $out;
        }

        return response()->json([
            'page' => $page,
            'total' => (int) ($res['total'] ?? 0),
            'items' => $items,
        ]);
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
        $mapper = app(OrderPayloadMapper::class);
        $fingerprints = app(OrderFingerprint::class);

        $res = $magento->searchOrders($conn, 50, $page);
        $orders = $res['orders'] ?? [];
        if (!is_array($orders) || count($orders) === 0) {
            return response()->json([
                'page' => $page,
                'total' => (int) ($res['total'] ?? 0),
                'items' => [],
            ]);
        }

        $items = [];
        foreach (array_slice($orders, 0, $limit) as $o) {
            $sourceId = (string) ($o['entity_id'] ?? $o['id'] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $mapped = $mapper->mapOrder($shop, $o);
            $payload = $mapped['order'];
            $fp = $fingerprints->make($payload);

            $out = [
                'source_id' => $sourceId,
                'order_number' => (string) ($o['increment_id'] ?? $o['orderNumber'] ?? ''),
                'email' => (string) data_get($payload, 'email', ''),
                'amount_total' => data_get($o, 'grand_total') ?? data_get($o, 'amountTotal'),
                'currency' => (string) data_get($payload, 'currency', ''),
                'line_items_count' => is_array(data_get($payload, 'lineItems')) ? count((array) data_get($payload, 'lineItems')) : 0,
                'fingerprint' => $fp,
            ];

            if ($includePayload) {
                $out['payload'] = $payload;
                $out['shopware_metafields'] = [];
                $out['shopware_raw'] = $mapped['magento_raw'] ?? [];
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

        if (!$run) {
            return response()->json([
                'run' => null,
                'prerequisites' => $this->service->prerequisites($shop),
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
            'prerequisites' => $this->service->prerequisites($shop),
        ]);
    }

    public function start(StartOrderMigrationRequest $request)
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
        if ($prerequisites['ready'] !== true) {
            return response()->json([
                'error' => 'Order migration prerequisites are not ready.',
                'prerequisites' => $prerequisites,
            ], 409);
        }

        $locationGid = (string) $request->validated('location_gid');
        $run = $this->service->start($shop, $locationGid);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
            'shopify_location_gid' => $run->shopify_location_gid,
        ], 202);
    }

    public function startFiltered(Request $request)
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
        if ($prerequisites['ready'] !== true) {
            return response()->json([
                'error' => 'Order migration prerequisites are not ready.',
                'prerequisites' => $prerequisites,
            ], 409);
        }

        $validated = $request->validate([
            'location_gid' => ['required', 'string', 'max:255', 'regex:/^gid:\/\/shopify\/Location\/[0-9]+$/'],
            'mode' => ['required', 'string', 'in:after,between'],
            'after' => ['nullable', 'date_format:Y-m-d'],
            'before' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $mode = (string) $validated['mode'];
        $after = isset($validated['after']) ? (string) $validated['after'] : null;
        $before = isset($validated['before']) ? (string) $validated['before'] : null;
        $filter = $this->buildCreatedAtFilter($mode, $after, $before);
        if (isset($filter['error'])) {
            return response()->json(['error' => $filter['error']], 422);
        }

        $locationGid = (string) $validated['location_gid'];
        $run = $this->service->startFiltered($shop, $filter, $locationGid);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
            'shopify_location_gid' => $run->shopify_location_gid,
        ], 202);
    }

    public function cancel(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $cancelled = $this->service->cancel($shop);

        return response()->json(['cancelled' => $cancelled]);
    }

    /**
     * @return array<int, mixed>|array{error: string}
     */
    private function buildCreatedAtFilter(string $mode, ?string $after, ?string $before): array
    {
        $mode = trim($mode);

        if ($mode === 'after') {
            if (!$after) {
                return ['error' => 'The after date is required for mode=after'];
            }

            $gte = CarbonImmutable::createFromFormat('Y-m-d', $after)
                ->startOfDay()
                ->toDateTimeString();
            return [[
                'field' => 'created_at',
                'type' => 'greater_than_equals',
                'value' => $gte,
            ]];
        }

        if ($mode === 'between') {
            if (!$after || !$before) {
                return ['error' => 'Both after and before dates are required for mode=between'];
            }

            $from = CarbonImmutable::createFromFormat('Y-m-d', $after)->startOfDay();
            $to = CarbonImmutable::createFromFormat('Y-m-d', $before)->endOfDay();
            if ($from->greaterThan($to)) {
                return ['error' => 'The after date must be before or equal to the before date'];
            }

            return [
                [
                    'field' => 'created_at',
                    'type' => 'greater_than_equals',
                    'value' => $from->toDateTimeString(),
                ],
                [
                    'field' => 'created_at',
                    'type' => 'less_than_equals',
                    'value' => $to->toDateTimeString(),
                ]
            ];
        }

        return ['error' => 'Invalid mode'];
    }

}

