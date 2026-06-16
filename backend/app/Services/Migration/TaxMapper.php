<?php

namespace App\Services\Migration;

class TaxMapper
{
    /**
     * Resolve taxable flag from Shopware product/variant tax data.
     * Falls back to parent product tax if variant has none.
     *
     * Rules:
     *   - taxRate > 0  → true
     *   - taxRate === 0 → false
     *   - null/missing  → true (safe default)
     */
    public function isTaxable(array $variant, array $fallbackParent): bool
    {
        $taxRate = $this->extractTaxRate($variant);

        if ($taxRate === null) {
            $taxRate = $this->extractTaxRate($fallbackParent);
        }

        if ($taxRate === null) {
            return true; // safe default
        }

        return (float) $taxRate > 0;
    }

    /**
     * Extract tax rate as float (e.g. 25.0) or null if not present.
     */
    public function taxRate(array $product): ?float
    {
        $rate = $this->extractTaxRate($product);
        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Extract tax name string (e.g. "Standard rate") or empty string.
     */
    public function taxName(array $product): string
    {
        $name = data_get($product, 'tax.name');
        if (!is_string($name)) {
            return '';
        }
        return trim($name);
    }

    /**
     * Read taxRate from a product/variant array. Returns null if not present.
     */
    private function extractTaxRate(array $product): mixed
    {
        $rate = data_get($product, 'tax.taxRate');
        if ($rate === null || $rate === '') {
            return null;
        }
        if (!is_numeric($rate)) {
            return null;
        }
        return $rate;
    }
}
