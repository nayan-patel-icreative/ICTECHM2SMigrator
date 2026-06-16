<?php

namespace App\Services\Migration;

use App\Http\Controllers\Api\StateMappingController;
use App\Models\Shop;
use App\Support\ShopwareStateResolver;

class StateAssignmentMapper
{
    /**
     * @var array<int, array<string, array<string, string>>>
     */
    private array $cache = [];

    /**
     * @return array<string, array<string, string>>
     */
    public function mappingsForShop(Shop $shop): array
    {
        if (!isset($this->cache[$shop->id])) {
            $this->cache[$shop->id] = StateMappingController::loadForShop($shop);
        }

        return $this->cache[$shop->id];
    }

    public function mappedValue(Shop $shop, string $type, string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        $mappings = $this->mappingsForShop($shop);
        $map = isset($mappings[$type]) && is_array($mappings[$type]) ? $mappings[$type] : [];

        if (array_key_exists($source, $map)) {
            $value = trim((string) $map[$source]);
            return $value !== '' ? $value : null;
        }

        $normalized = mb_strtolower($source);
        foreach ($map as $key => $value) {
            if (mb_strtolower(trim((string) $key)) !== $normalized) {
                continue;
            }

            $value = trim((string) $value);
            return $value !== '' ? $value : null;
        }

        return null;
    }

    public function optionLabel(string $type, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $options = StateMappingController::shopifyOptions();
        $rows = isset($options[$type]) && is_array($options[$type]) ? $options[$type] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row['value'] ?? '') !== $value) {
                continue;
            }

            return trim((string) ($row['label'] ?? '')) ?: $this->humanize($value);
        }

        return $this->humanize($value);
    }

    public function financialStatusValue(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'pending', 'open' => 'PENDING',
            'paid' => 'PAID',
            'partially_paid' => 'PARTIALLY_PAID',
            'refunded' => 'REFUNDED',
            'partially_refunded' => 'PARTIALLY_REFUNDED',
            'voided', 'cancelled' => 'VOIDED',
            default => null,
        };
    }

    /**
     * Resolve Shopify OrderCreate financialStatus from saved state assignments.
     *
     * Priority:
     * 1. Transaction state when it maps to a non-pending financial status (e.g. paid, refunded).
     * 2. Order state mapping (Assignments → Order States tab).
     * 3. Transaction state when it maps to pending.
     * 4. Legacy substring heuristics on raw Shopware transaction states.
     */
    public function resolveFinancialStatus(Shop $shop, array $order): string
    {
        $fromTransaction = $this->financialStatusFromStateType(
            $shop,
            'transaction_state',
            $this->firstTransactionState($order)
        );
        $fromOrder = $this->financialStatusFromStateType(
            $shop,
            'order_state',
            $this->orderState($order)
        );

        if ($fromTransaction !== null && $fromTransaction !== '' && $fromTransaction !== 'PENDING') {
            return $fromTransaction;
        }

        if ($fromOrder !== null && $fromOrder !== '') {
            return $fromOrder;
        }

        if ($fromTransaction !== null && $fromTransaction !== '') {
            return $fromTransaction;
        }

        return $this->financialStatusFallbackFromRawStates($order);
    }

    private function financialStatusFromStateType(Shop $shop, string $type, string $state): ?string
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            return null;
        }

        $mapped = $this->mappedValue($shop, $type, $state);
        if (!is_string($mapped) || $mapped === '') {
            return null;
        }

        return $this->financialStatusValue($mapped);
    }

    /**
     * Resolve Shopify OrderCreate fulfillmentStatus from saved delivery state assignments.
     */
    public function resolveFulfillmentStatus(Shop $shop, array $order): ?string
    {
        $fromDelivery = $this->fulfillmentFromStateType(
            $shop,
            'delivery_state',
            $this->firstDeliveryState($order)
        );
        if ($fromDelivery !== null && $fromDelivery !== '') {
            return $fromDelivery;
        }

        // Shopware order search often returns no deliveries[] — use order state as delivery proxy (e.g. open → fulfilled).
        $fromOrderState = $this->fulfillmentFromStateType(
            $shop,
            'delivery_state',
            $this->orderState($order)
        );
        if ($fromOrderState !== null && $fromOrderState !== '') {
            return $fromOrderState;
        }

        return $this->fulfillmentFallbackFromRawStates($order);
    }

    private function fulfillmentFromStateType(Shop $shop, string $type, string $state): ?string
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            return null;
        }

        $mapped = $this->mappedValue($shop, $type, $state);
        if (!is_string($mapped) || $mapped === '') {
            return null;
        }

        return $this->fulfillmentStatusValue($mapped);
    }

    private function firstTransactionState(array $order): string
    {
        $tx = data_get($order, 'transactions', []);
        if (!is_array($tx) || !isset($tx[0]) || !is_array($tx[0])) {
            return '';
        }

        return ShopwareStateResolver::technicalName($tx[0]);
    }

    private function firstDeliveryState(array $order): string
    {
        $deliveries = data_get($order, 'deliveries', []);
        if (!is_array($deliveries) || !isset($deliveries[0]) || !is_array($deliveries[0])) {
            return '';
        }

        return ShopwareStateResolver::technicalName($deliveries[0]);
    }

    private function orderState(array $order): string
    {
        return ShopwareStateResolver::technicalName($order);
    }

    private function financialStatusFallbackFromRawStates(array $order): string
    {
        $tx = data_get($order, 'transactions', []);
        if (!is_array($tx)) {
            return 'PENDING';
        }

        $states = [];
        foreach ($tx as $t) {
            if (!is_array($t)) {
                continue;
            }
            $states[] = ShopwareStateResolver::technicalName($t);
        }
        $joined = strtolower(implode('|', array_filter($states)));

        if (str_contains($joined, 'paid') || str_contains($joined, 'completed') || str_contains($joined, 'authorize')) {
            return 'PAID';
        }

        if (str_contains($joined, 'cancel') || str_contains($joined, 'fail')) {
            return 'VOIDED';
        }

        return 'PENDING';
    }

    private function fulfillmentFallbackFromRawStates(array $order): ?string
    {
        $deliveries = data_get($order, 'deliveries', []);
        if (!is_array($deliveries)) {
            return null;
        }

        $states = [];
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            $states[] = ShopwareStateResolver::technicalName($delivery);
        }
        $joined = strtolower(implode('|', array_filter($states)));

        if (str_contains($joined, 'shipped') || str_contains($joined, 'delivered')) {
            return 'FULFILLED';
        }

        if (str_contains($joined, 'partial')) {
            return 'PARTIAL';
        }

        if (str_contains($joined, 'cancel') || str_contains($joined, 'returned')) {
            return 'RESTOCKED';
        }

        return null;
    }

    public function fulfillmentOptionLabel(string $value): string
    {
        return $this->optionLabel('fulfillment', $value);
    }

    public function transactionStatusForFinancialStatus(?string $financialStatus): string
    {
        return match ($financialStatus) {
            'PAID', 'PARTIALLY_PAID', 'REFUNDED', 'PARTIALLY_REFUNDED' => 'SUCCESS',
            'VOIDED' => 'FAILURE',
            default => 'PENDING',
        };
    }

    public function fulfillmentStatusValue(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'unfulfilled') {
            return null;
        }

        return match ($value) {
            'fulfilled' => 'FULFILLED',
            'partial' => 'PARTIAL',
            'restocked' => 'RESTOCKED',
            default => null,
        };
    }

    public function humanize(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));
        if ($value === '') {
            return '';
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
