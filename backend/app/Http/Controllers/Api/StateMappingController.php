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
                'open'                    => 'open',
                'in_progress'             => 'open',
                'completed'               => 'open',
                'cancelled'               => 'cancelled',
            ],
            'transaction_state' => [
                'open'                    => 'pending',
                'in_progress'             => 'pending',
                'paid'                    => 'paid',
                'paid_partially'          => 'partially_paid',
                'refunded'                => 'refunded',
                'partially_refunded'      => 'partially_refunded',
                'cancelled'               => 'voided',
                'failed'                  => 'voided',
                'authorized'              => 'paid',
                'chargeback'              => 'voided',
                'unconfirmed'             => 'pending',
                'reminded'                => 'pending',
            ],
            'delivery_state' => [
                'open'                    => 'unfulfilled',
                'shipped'                 => 'fulfilled',
                'delivered'               => 'fulfilled',
                'partially_shipped'       => 'partial',
                'returned'                => 'restocked',
                'cancelled'               => 'unfulfilled',
                'returned_partially'      => 'restocked',
            ],
            'salutations' => [
                'mr'                      => 'mr',
                'mrs'                     => 'mrs',
                'ms'                      => 'ms',
                'miss'                    => 'miss',
                'dr'                      => 'dr',
                'not_specified'           => '',
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

        // Auto-populate payment_methods and shipping_methods from Magento if connected
        $conn = $shop->magentoConnection;
        if ($conn) {
            $magento = app(MagentoClient::class);

            // Payment methods
            try {
                $methods = ['checkmo', 'purchaseorder', 'banktransfer', 'cashondelivery', 'paypal_express', 'stripe'];
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
            'mappings.salutations' => ['nullable', 'array'],
            'mappings.payment_methods' => ['nullable', 'array'],
            'mappings.shipping_methods' => ['nullable', 'array'],
        ]);

        $mappings = $validated['mappings'];
        $validTypes = ['order_state', 'transaction_state', 'delivery_state', 'salutations', 'payment_methods', 'shipping_methods'];
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
            'salutations' => [
                ['value' => '',      'label' => '— Not mapped —'],
                ['value' => 'mr',    'label' => 'Mr'],
                ['value' => 'mrs',   'label' => 'Mrs'],
                ['value' => 'ms',    'label' => 'Ms'],
                ['value' => 'miss',  'label' => 'Miss'],
                ['value' => 'dr',    'label' => 'Dr'],
                ['value' => 'prof',  'label' => 'Prof'],
                ['value' => 'mx',    'label' => 'Mx'],
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
     * @return array{order_state: array<string,string>, transaction_state: array<string,string>, delivery_state: array<string,string>, salutations: array<string,string>}
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
