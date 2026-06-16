<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;

class OrderPayloadMapper
{
    /**
     * Maps Magento order data to Shopify Order Input.
     *
     * @return array{order: array<string, mixed>, metafields: array<int, array<string, mixed>>, magento_raw: array<string, mixed>, payment_capture: ?array<string, mixed>}
     */
    public function mapOrder(Shop $shop, array $order, ?string $shopifyLocationGid = null): array
    {
        $currency = strtoupper(trim((string) ($order['order_currency_code'] ?? 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }

        $orderNumber = (string) ($order['increment_id'] ?? $order['entity_id'] ?? '');
        $email = (string) ($order['customer_email'] ?? $order['billing_address']['email'] ?? '');
        $rawPhone = (string) ($order['billing_address']['telephone'] ?? '');
        $phone = $this->normalizeE164Phone($rawPhone);

        $billing = $this->finalizeMailingAddressForShopify($this->resolveBillingAddress($order));
        $shipping = $this->finalizeMailingAddressForShopify($this->resolveShippingAddress($order), $billing);

        $lineItems = $this->mapLineItems($order, $currency);
        if (count($lineItems) === 0) {
            $lineItems[] = [
                'title' => 'Imported order line item',
                'quantity' => 1,
                'priceSet' => [
                    'shopMoney' => [
                        'amount' => $this->formatAmount((float) ($order['grand_total'] ?? 0)),
                        'currencyCode' => $currency,
                    ],
                ],
            ];
        }

        $shippingLines = $this->mapShippingLines($order, $currency);

        // Map status
        $status = strtolower((string) ($order['status'] ?? 'pending'));
        $state = strtolower((string) ($order['state'] ?? 'new'));

        $financialStatus = 'PENDING';
        $fulfillmentStatus = 'UNFULFILLED';

        if ($status === 'complete') {
            $financialStatus = 'PAID';
            $fulfillmentStatus = 'FULFILLED';
        } elseif ($status === 'processing') {
            $financialStatus = 'PAID';
            $fulfillmentStatus = 'UNFULFILLED';
        } elseif ($status === 'closed') {
            $financialStatus = 'REFUNDED';
        } elseif ($status === 'canceled') {
            $financialStatus = 'VOIDED';
        }

        $paymentMethod = (string) ($order['payment']['method'] ?? '');

        // Build note parts
        $noteParts = [];
        if ($orderNumber !== '') {
            $noteParts[] = 'Magento Order Number: ' . $orderNumber;
        }
        if (isset($order['entity_id'])) {
            $noteParts[] = 'Magento Order ID: ' . $order['entity_id'];
        }
        if ($status !== '') {
            $noteParts[] = 'Magento status: ' . $status;
        }
        if ($state !== '') {
            $noteParts[] = 'Magento state: ' . $state;
        }
        if ($paymentMethod !== '') {
            $noteParts[] = 'Magento payment method: ' . $paymentMethod;
        }
        if ($phone === null && trim($rawPhone) !== '') {
            $noteParts[] = 'Magento billing phone: ' . trim($rawPhone);
        }

        // Customer note / comment
        $customerNote = trim((string) ($order['customer_note'] ?? ''));
        if ($customerNote !== '') {
            $noteParts[] = 'Customer Note: ' . $customerNote;
        }

        $note = implode("\n", array_values(array_filter($noteParts)));

        $processedAt = (string) ($order['created_at'] ?? '');

        $customerGid = $this->resolveShopifyCustomerGid($shop, $order);

        $tags = [
            'magento',
            'M2_ORDER_' . $orderNumber
        ];
        if ($paymentMethod !== '') {
            $tags[] = 'M2_PAYMENT_' . strtoupper($paymentMethod);
        }

        $payload = [
            'currency' => $currency,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
            'billingAddress' => $billing,
            'shippingAddress' => $shipping,
            'lineItems' => $lineItems,
            'shippingLines' => $shippingLines,
            'financialStatus' => $financialStatus,
            'processedAt' => $processedAt !== '' ? $processedAt : null,
            'sourceName' => 'magento',
            'sourceIdentifier' => (string) ($order['entity_id'] ?? ''),
            'name' => $orderNumber !== '' ? $orderNumber : null,
            'note' => $note !== '' ? $note : null,
            'tags' => $tags,
        ];

        if ($fulfillmentStatus !== 'UNFULFILLED') {
            $payload['fulfillmentStatus'] = $fulfillmentStatus;
        }

        $fulfillmentInput = $this->buildFulfillmentInput($fulfillmentStatus, $shopifyLocationGid);
        if ($fulfillmentInput !== null) {
            $payload['fulfillment'] = $fulfillmentInput;
        }

        // Transactions
        $transactions = [];
        $paymentCapture = null;
        if ($financialStatus === 'PAID') {
            $paymentCapture = [
                'amount' => $this->formatAmount((float) ($order['grand_total'] ?? 0)),
                'currencyCode' => $currency,
                'paymentMethodName' => $paymentMethod !== '' ? $paymentMethod : 'Magento Payment',
                'processedAt' => $processedAt !== '' ? $processedAt : null,
            ];
            // When using payment capture step, create order financialStatus as PENDING and then record manual payment transaction.
            $payload['financialStatus'] = 'PENDING';
        } else {
            $transactions[] = [
                'amount' => $this->formatAmount((float) ($order['grand_total'] ?? 0)),
                'currencyCode' => $currency,
                'gateway' => $paymentMethod !== '' ? $paymentMethod : 'manual',
                'kind' => 'SALE',
                'status' => 'SUCCESS',
            ];
        }
        $payload['transactions'] = $transactions;

        $customAttributes = [];
        if ($customerNote !== '') {
            $customAttributes[] = [
                'key' => 'magento_customer_note',
                'value' => $this->limitCustomAttributeValue($customerNote),
            ];
        }

        if (count($customAttributes) > 0) {
            $payload['customAttributes'] = $customAttributes;
        }

        $payload = array_filter($payload, function ($v) {
            return $v !== null;
        });

        $metafields = $this->mapShopwareMetafields($shop, $order);

        return [
            'order' => $payload,
            'metafields' => $metafields,
            'magento_raw' => $order,
            'payment_capture' => $paymentCapture,
        ];
    }

    private function resolveShopifyCustomerGid(Shop $shop, array $order): string
    {
        $customerId = (string) ($order['customer_id'] ?? '');
        if ($customerId === '') {
            return '';
        }

        $mapping = ShopifyIdMapping::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', 'customer')
            ->where('source_id', $customerId)
            ->first();

        return $mapping ? (string) $mapping->shopify_gid : '';
    }

    private function buildFulfillmentInput(string $status, ?string $locationGid): ?array
    {
        if ($status !== 'FULFILLED' || !$locationGid) {
            return null;
        }

        return [
            'locationId' => $locationGid,
            'notifyMerchant' => false,
            'notifyCustomer' => false,
        ];
    }

    private function mapLineItems(array $order, string $currency): array
    {
        $items = $order['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $li) {
            if (!is_array($li)) {
                continue;
            }

            // Exclude child simple products inside configurables to prevent duplicate items
            if (isset($li['parent_item_id']) || isset($li['parent_item'])) {
                continue;
            }

            $qty = (int) ($li['qty_ordered'] ?? 0);
            if ($qty <= 0) {
                $qty = 1;
            }

            $price = (float) ($li['price'] ?? 0);

            $payload = [
                'title' => (string) ($li['name'] ?? 'Magento line item'),
                'quantity' => $qty,
                'priceSet' => [
                    'shopMoney' => [
                        'amount' => $this->formatAmount($price),
                        'currencyCode' => $currency,
                    ],
                ],
            ];

            $sku = trim((string) ($li['sku'] ?? ''));
            if ($sku !== '') {
                $payload['sku'] = $sku;
            }

            $out[] = $payload;
        }

        return $out;
    }

    private function mapShippingLines(array $order, string $currency): array
    {
        $title = (string) ($order['shipping_description'] ?? 'Shipping');
        $shippingAmount = (float) ($order['shipping_amount'] ?? 0);

        return [
            [
                'title' => $title !== '' ? $title : 'Shipping',
                'originalShopityShippingRate' => [
                    'priceSet' => [
                        'shopMoney' => [
                            'amount' => $this->formatAmount($shippingAmount),
                            'currencyCode' => $currency,
                        ],
                    ],
                ],
            ]
        ];
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    public function mapShopwareMetafields(mixed $shop, array $order = []): array
    {
        if (is_array($shop) && $order === []) {
            $order = $shop;
        }

        $rawJson = json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($rawJson)) {
            $rawJson = '{}';
        }

        $out = [];

        $orderId = (string) ($order['entity_id'] ?? '');
        if ($orderId !== '') {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'order_id',
                'type' => 'single_line_text_field',
                'value' => $orderId,
            ];
        }

        $orderNumber = (string) ($order['increment_id'] ?? '');
        if ($orderNumber !== '') {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'order_number',
                'type' => 'single_line_text_field',
                'value' => $orderNumber,
            ];
        }

        $out[] = [
            'namespace' => 'magento',
            'key' => 'raw_json',
            'type' => 'json',
            'value' => $rawJson,
        ];

        return $out;
    }

    public function extractDocumentsPublic(array $order): array
    {
        return [];
    }

    private function resolveBillingAddress(array $order): array
    {
        return $order['billing_address'] ?? [];
    }

    private function resolveShippingAddress(array $order): array
    {
        // Try extension attributes
        $assignments = $order['extension_attributes']['shipping_assignments'] ?? [];
        if (is_array($assignments) && count($assignments) > 0) {
            $address = $assignments[0]['shipping']['address'] ?? null;
            if (is_array($address)) {
                return $address;
            }
        }

        // Try standalone shipping_address field if it exists
        if (isset($order['shipping_address']) && is_array($order['shipping_address'])) {
            return $order['shipping_address'];
        }

        return [];
    }

    private function finalizeMailingAddressForShopify(array $addr, ?array $fallback = null): ?array
    {
        if (count($addr) === 0) {
            return $fallback;
        }

        $countryCode = strtoupper(trim((string) ($addr['country_id'] ?? '')));

        // Street handling (Magento returns street as array of lines)
        $street = $addr['street'] ?? [];
        $address1 = '';
        $address2 = '';
        if (is_array($street)) {
            $address1 = (string) ($street[0] ?? '');
            $address2 = (string) ($street[1] ?? '');
        } else if (is_string($street)) {
            $address1 = $street;
        }

        $province = '';
        if (isset($addr['region'])) {
            if (is_array($addr['region'])) {
                $province = (string) ($addr['region']['region'] ?? $addr['region']['region_code'] ?? '');
            } else {
                $province = (string) $addr['region'];
            }
        }

        $out = [
            'firstName' => (string) ($addr['firstname'] ?? ''),
            'lastName' => (string) ($addr['lastname'] ?? ''),
            'company' => (string) ($addr['company'] ?? ''),
            'address1' => $address1 !== '' ? $address1 : 'Street',
            'address2' => $address2 !== '' ? $address2 : null,
            'city' => (string) ($addr['city'] ?? 'City'),
            'zip' => (string) ($addr['postcode'] ?? '0000'),
            'province' => $province !== '' ? $province : null,
            'countryCode' => $countryCode !== '' ? $countryCode : 'US',
            'phone' => $this->normalizeE164Phone((string) ($addr['telephone'] ?? '')),
        ];

        return array_filter($out, function ($v) {
            return $v !== null;
        });
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function normalizeE164Phone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1) {
            return $phone;
        }

        return null;
    }

    private function limitCustomAttributeValue(string $val): string
    {
        $val = trim($val);
        if (strlen($val) > 255) {
            return substr($val, 0, 252) . '...';
        }
        return $val;
    }
}