<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartCustomerMigrationRequest;
use App\Models\Shop;
use App\Services\Migration\CustomerFingerprint;
use App\Services\Migration\CustomerMigrationService;
use App\Services\Migration\CustomerPayloadMapper;
use App\Services\QueueHealthService;
use App\Services\Magento\MagentoClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class CustomerMigrationController extends Controller
{
    private CustomerMigrationService $service;

    public function __construct(CustomerMigrationService $service)
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
            'mode' => ['required', 'string', 'in:after,before,between'],
            'after' => ['nullable', 'date_format:Y-m-d'],
            'before' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
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
        $mapper = app(CustomerPayloadMapper::class);
        $fingerprints = app(CustomerFingerprint::class);

        $res = $magento->searchCustomers($conn, 50, $page, $filter);
        $customers = $res['customers'] ?? [];
        if (!is_array($customers) || count($customers) === 0) {
            return response()->json([
                'page' => $page,
                'total' => (int) ($res['total'] ?? 0),
                'items' => [],
            ]);
        }

        $items = [];
        foreach (array_slice($customers, 0, $limit) as $c) {
            $sourceId = (string) ($c['id'] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $payload = $mapper->mapCustomer($c, $shop);
            $fp = $fingerprints->make($payload);

            $out = [
                'source_id' => $sourceId,
                'email' => (string) ($payload['email'] ?? ''),
                'first_name' => (string) ($payload['firstName'] ?? ''),
                'last_name' => (string) ($payload['lastName'] ?? ''),
                'addresses_count' => is_array($payload['addresses'] ?? null) ? count($payload['addresses']) : 0,
                'fingerprint' => $fp,
            ];

            if ($includePayload) {
                $out['payload'] = $payload;
                $out['shopware_metafields'] = []; // Magento metafields or custom attributes mapped as needed
                $out['shopware_raw'] = $c;
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
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'include_payload' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 5);
        $page = (int) ($validated['page'] ?? 1);
        $includePayload = (bool) ($validated['include_payload'] ?? false);

        $magento = app(MagentoClient::class);
        $mapper = app(CustomerPayloadMapper::class);
        $fingerprints = app(CustomerFingerprint::class);

        $res = $magento->searchCustomers($conn, 50, $page);
        $customers = $res['customers'] ?? [];
        if (!is_array($customers) || count($customers) === 0) {
            return response()->json([
                'page' => $page,
                'total' => (int) ($res['total'] ?? 0),
                'items' => [],
            ]);
        }

        $items = [];
        foreach (array_slice($customers, 0, $limit) as $c) {
            $sourceId = (string) ($c['id'] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $payload = $mapper->mapCustomer($c, $shop);
            $fp = $fingerprints->make($payload);

            $out = [
                'source_id' => $sourceId,
                'email' => (string) ($payload['email'] ?? ''),
                'first_name' => (string) ($payload['firstName'] ?? ''),
                'last_name' => (string) ($payload['lastName'] ?? ''),
                'addresses_count' => is_array($payload['addresses'] ?? null) ? count($payload['addresses']) : 0,
                'fingerprint' => $fp,
            ];

            if ($includePayload) {
                $out['payload'] = $payload;
                $out['shopware_metafields'] = []; // Magento metafields or custom attributes mapped as needed
                $out['shopware_raw'] = $c;
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

    public function start(StartCustomerMigrationRequest $request)
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

        $run = $this->service->start($shop);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
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

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:after,before,between'],
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

        $run = $this->service->startFiltered($shop, $filter);

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
                ->addDay()
                ->startOfDay()
                ->toIso8601String();
            return [[
                'type' => 'range',
                'field' => 'createdAt',
                'parameters' => ['gte' => $gte],
            ]];
        }

        if ($mode === 'before') {
            if (!$before) {
                return ['error' => 'The before date is required for mode=before'];
            }
            $lte = CarbonImmutable::createFromFormat('Y-m-d', $before)->endOfDay()->toIso8601String();
            return [[
                'type' => 'range',
                'field' => 'createdAt',
                'parameters' => ['lte' => $lte],
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

            return [[
                'type' => 'range',
                'field' => 'createdAt',
                'parameters' => [
                    'gte' => $from->toIso8601String(),
                    'lte' => $to->toIso8601String(),
                ],
            ]];
        }

        return ['error' => 'Invalid mode'];
    }
}
