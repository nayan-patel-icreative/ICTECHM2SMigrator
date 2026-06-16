<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StateMapping;
use App\Services\Magento\MagentoClient;
use Illuminate\Http\Request;

class StateMappingController extends Controller
{
    /**
     * Default state mappings used when no custom mapping is saved.
     * These mirror the hardcoded logic in OrderPayloadMapper.
     */
    public static function defaults(): array
    {
        return [
            'order_state' => [
                'pending'                 => 'open',
                'pending_payment'         => 'open',
                'processing'              => 'open',
                'complete'                => 'open',
                'closed'                  => 'open',
                'canceled'                => 'cancelled',
                'holded'                  => 'open',
                'payment_review'          => 'open',
                'fraud'                   => 'open',
            ],
            'transaction_state' => [
                'pending'                 => 'pending',
                'pending_payment'         => 'pending',
                'processing'              => 'paid',
                'complete'                => 'paid',
                'closed'                  => 'refunded',
                'canceled'                => 'voided',
                'holded'                  => 'pending',
                'payment_review'          => 'pending',
                'fraud'                   => 'pending',
            ],
            'delivery_state' => [
                'pending'                 => 'unfulfilled',
                'pending_payment'         => 'unfulfilled',
                'processing'              => 'unfulfilled',
                'complete'                => 'fulfilled',
                'closed'                  => 'fulfilled',
                'canceled'                => 'unfulfilled',
                'holded'                  => 'unfulfilled',
                'payment_review'          => 'unfulfilled',
                'fraud'                   => 'unfulfilled',
            ],
            'payment_methods' => [],
            'shipping_methods' => [],
        ];
    }

    public function show(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $saved = StateMapping::query()
            ->where('shop_id', $shop->id)
            ->get(['state_type', 'shopware_state', 'shopify_status']);

        $defaults = self::defaults();

        // Merge saved over defaults
        $result = $defaults;
        foreach ($saved as $row) {
            $result[$row->state_type][$row->shopware_state] = $row->shopify_status;
        }

        // Auto-populate order_state, transaction_state, delivery_state, payment_methods, and shipping_methods dynamically from Magento if connected
        $conn = $shop->magentoConnection;
        if ($conn) {
            $magento = app(MagentoClient::class);

            // Fetch dynamic order statuses by reading from recent orders
            try {
                // Fetch recent 200 orders to find all unique order statuses in use
                $ordersRes = $magento->searchOrders($conn, 200, 1);
                $orders = $ordersRes['orders'] ?? [];
                
                foreach ($orders as $order) {
                    $status = isset($order['status']) ? strtolower(trim((string) $order['status'])) : '';
                    if ($status === '') {
                        continue;
                    }

                    // Auto-register order_state status
                    if (!isset($result['order_state'][$status])) {
                        $shopifyStatus = 'open';
                        if ($status === 'canceled') {
                            $shopifyStatus = 'cancelled';
                        }
                        StateMapping::query()->firstOrCreate(
                            ['shop_id' => $shop->id, 'state_type' => 'order_state', 'shopware_state' => $status],
                            ['shopify_status' => $shopifyStatus]
                        );
                        $result['order_state'][$status] = $shopifyStatus;
                    }

                    // Auto-register transaction_state status
                    if (!isset($result['transaction_state'][$status])) {
                        $shopifyStatus = 'pending';
                        if ($status === 'complete' || $status === 'processing') {
                            $shopifyStatus = 'paid';
                        } elseif ($status === 'closed') {
                            $shopifyStatus = 'refunded';
                        } elseif ($status === 'canceled') {
                            $shopifyStatus = 'voided';
                        }
                        StateMapping::query()->firstOrCreate(
                            ['shop_id' => $shop->id, 'state_type' => 'transaction_state', 'shopware_state' => $status],
                            ['shopify_status' => $shopifyStatus]
                        );
                        $result['transaction_state'][$status] = $shopifyStatus;
                    }

                    // Auto-register delivery_state status
                    if (!isset($result['delivery_state'][$status])) {
                        $shopifyStatus = 'unfulfilled';
                        if ($status === 'complete') {
                            $shopifyStatus = 'fulfilled';
                        }
                        StateMapping::query()->firstOrCreate(
                            ['shop_id' => $shop->id, 'state_type' => 'delivery_state', 'shopware_state' => $status],
                            ['shopify_status' => $shopifyStatus]
                        );
                        $result['delivery_state'][$status] = $shopifyStatus;
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to fetch dynamic Magento order statuses', ['error' => $e->getMessage()]);
            }

            // Payment methods
            try {
                $methods = ['checkmo', 'purchaseorder', 'banktransfer', 'cashondelivery', 'paypal_express', 'stripe'];
                
                // Scan recent orders for any other payment methods
                if (isset($orders)) {
                    foreach ($orders as $order) {
                        $pm = isset($order['payment']['method']) ? trim((string) $order['payment']['method']) : '';
                        if ($pm !== '' && !in_array($pm, $methods)) {
                            $methods[] = $pm;
                        }
                    }
                }

                foreach ($methods as $key) {
                    if (!isset($result['payment_methods'][$key])) {
                        StateMapping::query()->firstOrCreate(
                            ['shop_id' => $shop->id, 'state_type' => 'payment_methods', 'shopware_state' => $key],
                            ['shopify_status' => '']
                        );
                        $result['payment_methods'][$key] = '';
                    }
                }
            } catch (\Throwable) {
            }

            // Shipping methods
            try {
                $methods = ['flatrate', 'tablerate', 'freeshipping', 'ups', 'usps', 'fedex', 'dhl'];

                // Scan recent orders for any other shipping methods
                if (isset($orders)) {
                    foreach ($orders as $order) {
                        $sm = isset($order['shipping_description']) ? trim((string) $order['shipping_description']) : '';
                        if ($sm !== '' && !in_array($sm, $methods)) {
                            $methods[] = $sm;
                        }
                    }
                }

                foreach ($methods as $key) {
                    if (!isset($result['shipping_methods'][$key])) {
                        StateMapping::query()->firstOrCreate(
                            ['shop_id' => $shop->id, 'state_type' => 'shipping_methods', 'shopware_state' => $key],
                            ['shopify_status' => '']
                        );
                        $result['shipping_methods'][$key] = '';
                    }
                }
            } catch (\Throwable) {
            }
        }

        return response()->json([
            'mappings' => $result,
            'defaults' => $defaults,
            'options' => self::shopifyOptions(),
        ]);
    }

    public function store(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.order_state' => ['nullable', 'array'],
            'mappings.transaction_state' => ['nullable', 'array'],
            'mappings.delivery_state' => ['nullable', 'array'],
            'mappings.payment_methods' => ['nullable', 'array'],
            'mappings.shipping_methods' => ['nullable', 'array'],
        ]);

        $mappings = $validated['mappings'];
        $validTypes = ['order_state', 'transaction_state', 'delivery_state', 'payment_methods', 'shipping_methods'];
        $validOrderStatuses = array_column(self::shopifyOptions()['order_financial'], 'value');
        $validFulfillmentStatuses = array_column(self::shopifyOptions()['fulfillment'], 'value');

        foreach ($validTypes as $type) {
            $states = $mappings[$type] ?? [];
            if (!is_array($states)) {
                continue;
            }

            foreach ($states as $shopwareState => $shopifyStatus) {
                if (!is_string($shopwareState) || !is_string($shopifyStatus)) {
                    continue;
                }

                StateMapping::query()->updateOrCreate(
                    [
                        'shop_id' => $shop->id,
                        'state_type' => $type,
                        'shopware_state' => $shopwareState,
                    ],
                    ['shopify_status' => $shopifyStatus]
                );
            }
        }

        return response()->json(['saved' => true]);
    }

    public static function shopifyOptions(): array
    {
        return [
            'order_financial' => [
                ['value' => 'pending',            'label' => 'Pending'],
                ['value' => 'paid',               'label' => 'Paid'],
                ['value' => 'partially_paid',     'label' => 'Partially Paid'],
                ['value' => 'refunded',           'label' => 'Refunded'],
                ['value' => 'partially_refunded', 'label' => 'Partially Refunded'],
                ['value' => 'voided',             'label' => 'Voided'],
                ['value' => 'cancelled',          'label' => 'Cancelled'],
                ['value' => 'open',               'label' => 'Open'],
            ],
            'fulfillment' => [
                ['value' => 'unfulfilled', 'label' => 'Unfulfilled'],
                ['value' => 'fulfilled',   'label' => 'Fulfilled'],
                ['value' => 'partial',     'label' => 'Partial'],
                ['value' => 'restocked',   'label' => 'Restocked'],
            ],
            'payment_methods' => [
                ['value' => '',                  'label' => '— Not mapped —'],
                ['value' => 'cash',              'label' => 'Cash'],
                ['value' => 'bank_transfer',     'label' => 'Bank Transfer'],
                ['value' => 'credit_card',       'label' => 'Credit Card'],
                ['value' => 'paypal',            'label' => 'PayPal'],
                ['value' => 'invoice',           'label' => 'Invoice'],
                ['value' => 'direct_debit',      'label' => 'Direct Debit'],
                ['value' => 'klarna',            'label' => 'Klarna'],
                ['value' => 'ideal',             'label' => 'iDEAL'],
                ['value' => 'sofort',            'label' => 'Sofort'],
                ['value' => 'apple_pay',         'label' => 'Apple Pay'],
                ['value' => 'google_pay',        'label' => 'Google Pay'],
                ['value' => 'other',             'label' => 'Other'],
            ],
            'shipping_methods' => [
                ['value' => '',                  'label' => '— Not mapped —'],
                ['value' => 'standard',          'label' => 'Standard Shipping'],
                ['value' => 'express',           'label' => 'Express Shipping'],
                ['value' => 'overnight',         'label' => 'Overnight Shipping'],
                ['value' => 'free',              'label' => 'Free Shipping'],
                ['value' => 'pickup',            'label' => 'Store Pickup'],
                ['value' => 'economy',           'label' => 'Economy Shipping'],
                ['value' => 'international',     'label' => 'International Shipping'],
                ['value' => 'other',             'label' => 'Other'],
            ],
        ];
    }

    /**
     * Load saved mappings for a shop, merged with defaults.
     * Used by OrderPayloadMapper.
     *
     * @return array{order_state: array<string,string>, transaction_state: array<string,string>, delivery_state: array<string,string>}
     */
    public static function loadForShop(Shop $shop): array
    {
        $saved = StateMapping::query()
            ->where('shop_id', $shop->id)
            ->get(['state_type', 'shopware_state', 'shopify_status']);

        $result = self::defaults();
        foreach ($saved as $row) {
            $result[$row->state_type][$row->shopware_state] = $row->shopify_status;
        }

        return $result;
    }
}
