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

        try {
            $stateMapper = (function_exists('app') && app()->bound('config'))
                ? app(\App\Services\Migration\StateAssignmentMapper::class)
                : null;
        } catch (\Throwable) {
            $stateMapper = null;
        }

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

        $shippingLines = $this->mapShippingLines($shop, $order, $currency, $stateMapper);

        // Map status
        $status = strtolower((string) ($order['status'] ?? 'pending'));
        $state = strtolower((string) ($order['state'] ?? 'new'));

        if ($stateMapper) {
            $financialStatus = $stateMapper->resolveFinancialStatus($shop, $order);
            $fulfillmentStatus = $stateMapper->resolveFulfillmentStatus($shop, $order) ?: 'UNFULFILLED';
        } else {
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
        if ($processedAt !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $processedAt) ?: new \DateTime($processedAt);
            if ($dt) {
                $processedAt = $dt->format(\DateTime::ATOM);
            }
        }

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

        // Build transactions suitable for orderCreate (works on all Shopify plans).
        // Shopify's orderCreate mutation for historical imports honours the financialStatus
        // field directly; we additionally pass a matching transaction so the order ledger
        // is consistent. orderCreateManualPayment is Shopify Plus-only and is NOT used.
        $transactions  = [];
        $paymentCapture = null; // Kept for interface compatibility; no longer used.
        $txAmountSet = [
            'shopMoney' => [
                'amount'       => $this->formatAmount((float) ($order['grand_total'] ?? 0)),
                'currencyCode' => $currency,
            ],
        ];
        $gateway = $paymentMethod !== '' ? $paymentMethod : 'manual';
        if ($stateMapper && $paymentMethod !== '') {
            $mappedPayment = $stateMapper->mappedValue($shop, 'payment_methods', $paymentMethod);
            if ($mappedPayment !== null && $mappedPayment !== '') {
                $gatewayLabel = $stateMapper->optionLabel('payment_methods', $mappedPayment);
                if ($gatewayLabel !== '') {
                    $gateway = $gatewayLabel;
                }
            }
        }

        switch ($financialStatus) {
            case 'PAID':
            case 'PARTIALLY_PAID':
            case 'REFUNDED':
            case 'PARTIALLY_REFUNDED':
                // A completed SALE transaction makes the order ledger consistent.
                // For REFUNDED/PARTIALLY_REFUNDED the financialStatus field drives the
                // displayed status; Shopify does not require a separate refund transaction
                // in the historical import mutation.
                $transactions[] = [
                    'amountSet' => $txAmountSet,
                    'gateway'   => $gateway,
                    'kind'      => 'SALE',
                    'status'    => 'SUCCESS',
                ];
                break;

            case 'VOIDED':
                $transactions[] = [
                    'amountSet' => $txAmountSet,
                    'gateway'   => $gateway,
                    'kind'      => 'VOID',
                    'status'    => 'SUCCESS',
                ];
                break;

            case 'PENDING':
            default:
                // No transaction entry — order stays pending.
                break;
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

        $metafields = $this->mapMagentoMetafields($shop, $order);

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
            'locationId'     => $locationGid,
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

            $taxAmount = (float) ($li['tax_amount'] ?? 0);
            if ($taxAmount > 0) {
                $taxPercent = (float) ($li['tax_percent'] ?? 0);
                $rate = $taxPercent > 0 ? ($taxPercent / 100) : 0.0;
                $payload['taxLines'] = [
                    [
                        'title' => 'Tax',
                        'rate' => $rate,
                        'priceSet' => [
                            'shopMoney' => [
                                'amount' => $this->formatAmount($taxAmount),
                                'currencyCode' => $currency,
                            ],
                        ],
                    ]
                ];
                $payload['taxable'] = true;
            }

            $out[] = $payload;
        }

        return $out;
    }

    private function mapShippingLines(?Shop $shop, array $order, string $currency, ?StateAssignmentMapper $stateMapper = null): array
    {
        $rawTitle = (string) ($order['shipping_description'] ?? 'Shipping');
        $title = $rawTitle;

        if ($shop && $stateMapper) {
            $mapped = $stateMapper->mappedValue($shop, 'shipping_methods', $rawTitle);
            if ($mapped !== null && $mapped !== '') {
                $title = $stateMapper->optionLabel('shipping_methods', $mapped);
            } else {
                $techCode = (string) ($order['shipping_method'] ?? '');
                if ($techCode !== '') {
                    $mapped = $stateMapper->mappedValue($shop, 'shipping_methods', $techCode);
                    if ($mapped !== null && $mapped !== '') {
                        $title = $stateMapper->optionLabel('shipping_methods', $mapped);
                    }
                }
            }
        }

        $shippingAmount = (float) ($order['shipping_amount'] ?? 0);
        $shippingTaxAmount = (float) ($order['shipping_tax_amount'] ?? 0);

        $line = [
            'title' => $title !== '' ? $title : 'Shipping',
            'priceSet' => [
                'shopMoney' => [
                    'amount' => $this->formatAmount($shippingAmount),
                    'currencyCode' => $currency,
                ],
            ],
        ];

        if ($shippingTaxAmount > 0) {
            $line['taxLines'] = [
                [
                    'title' => 'Shipping Tax',
                    'rate' => 0.0,
                    'priceSet' => [
                        'shopMoney' => [
                            'amount' => $this->formatAmount($shippingTaxAmount),
                            'currencyCode' => $currency,
                        ],
                    ],
                ]
            ];
        }

        return [$line];
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    public function mapMagentoMetafields(mixed $shop, array $order = []): array
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

        $status = (string) ($order['status'] ?? '');
        if ($status !== '') {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'status',
                'type' => 'single_line_text_field',
                'value' => $status,
            ];
        }

        $state = (string) ($order['state'] ?? '');
        if ($state !== '') {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'state',
                'type' => 'single_line_text_field',
                'value' => $state,
            ];
        }

        $paymentMethod = (string) ($order['payment']['method'] ?? '');
        if ($paymentMethod !== '') {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'payment_method',
                'type' => 'single_line_text_field',
                'value' => $paymentMethod,
            ];
        }

        $shippingDesc = (string) ($order['shipping_description'] ?? '');
        if ($shippingDesc !== '') {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'shipping_description',
                'type' => 'single_line_text_field',
                'value' => $shippingDesc,
            ];
        }

        $out[] = [
            'namespace' => 'magento',
            'key' => 'raw_json',
            'type' => 'json',
            'value' => $rawJson,
        ];

        // Raw invoice, shipment, and credit note JSON from Magento REST API
        $invoicesRaw = $order['invoices_raw'] ?? [];
        if (is_array($invoicesRaw) && count($invoicesRaw) > 0) {
            $invoicesJson = json_encode(array_values($invoicesRaw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($invoicesJson)) {
                $out[] = [
                    'namespace' => 'magento',
                    'key'       => 'invoices_json',
                    'type'      => 'json',
                    'value'     => $invoicesJson,
                ];
            }
        }

        $shipmentsRaw = $order['shipments_raw'] ?? [];
        if (is_array($shipmentsRaw) && count($shipmentsRaw) > 0) {
            $shipmentsJson = json_encode(array_values($shipmentsRaw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($shipmentsJson)) {
                $out[] = [
                    'namespace' => 'magento',
                    'key'       => 'shipments_json',
                    'type'      => 'json',
                    'value'     => $shipmentsJson,
                ];
            }
        }

        $creditNotesRaw = $order['credit_notes_raw'] ?? [];
        if (is_array($creditNotesRaw) && count($creditNotesRaw) > 0) {
            $creditNotesJson = json_encode(array_values($creditNotesRaw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($creditNotesJson)) {
                $out[] = [
                    'namespace' => 'magento',
                    'key'       => 'credit_notes_json',
                    'type'      => 'json',
                    'value'     => $creditNotesJson,
                ];
            }
        }

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