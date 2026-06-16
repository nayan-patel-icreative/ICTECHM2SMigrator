<?php

namespace App\Services\Migration;

class AdvancedPriceMapper
{
    /**
     * Map Shopware product.prices[] to grouped Shopify price list entries.
     *
     * @param array<int, array<string, mixed>> $prices  Shopware product.prices[] array
     * @param array<string, string> $variantIdByShopwareId  swVariantId => shopifyVariantGid
     * @param array<int, string>    $allVariantGids  fallback for simple products
     * @param string $currencyCode  e.g. "GBP"
     * @param string $priceMode  "gross" or "net"
     * @return array<string, array{ruleName: string, entries: array<int, array{variantGid: string, amount: string, currencyCode: string, compareAt: string|null, quantityMin: int, ruleName: string}>}>
     *   Keyed by ruleId.
     */
    public function map(
        array $prices,
        array $variantIdByShopwareId,
        array $allVariantGids,
        string $currencyCode,
        string $priceMode = 'gross'
    ): array {
        if (count($prices) === 0) {
            return [];
        }

        // Deduplicate key: ruleId|variantGid|quantityMin → keep lower price
        $dedupeMap = [];
        $grouped = [];

        foreach ($prices as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Resolve price amount
            $rawAmount = $priceMode === 'net'
                ? data_get($entry, 'net')
                : data_get($entry, 'gross');

            if (!is_numeric($rawAmount) || (float) $rawAmount <= 0) {
                continue;
            }

            $amount = number_format((float) $rawAmount, 2, '.', '');

            // Resolve compare-at price
            $compareAt = null;
            $rawCompare = $priceMode === 'net'
                ? data_get($entry, 'listPrice.net')
                : data_get($entry, 'listPrice.gross');
            if (is_numeric($rawCompare) && (float) $rawCompare > (float) $rawAmount) {
                $compareAt = number_format((float) $rawCompare, 2, '.', '');
            }

            $quantityMin = max(1, (int) ($entry['quantityStart'] ?? 1));
            $ruleId = trim((string) ($entry['ruleId'] ?? 'default'));
            if ($ruleId === '') {
                $ruleId = 'default';
            }
            $ruleName = trim((string) data_get($entry, 'rule.name', $ruleId));
            if ($ruleName === '') {
                $ruleName = $ruleId;
            }

            // Resolve variant GIDs for this entry
            $productId = trim((string) ($entry['productId'] ?? ''));
            if ($productId !== '' && isset($variantIdByShopwareId[$productId])) {
                $variantGids = [$variantIdByShopwareId[$productId]];
            } else {
                $variantGids = $allVariantGids;
            }

            foreach ($variantGids as $variantGid) {
                if (!is_string($variantGid) || $variantGid === '') {
                    continue;
                }

                $dedupeKey = $ruleId . '|' . $variantGid . '|' . $quantityMin;

                // Keep lower price on duplicate
                if (isset($dedupeMap[$dedupeKey])) {
                    if ((float) $amount >= (float) $dedupeMap[$dedupeKey]) {
                        continue;
                    }
                }

                $dedupeMap[$dedupeKey] = $amount;

                $entryData = [
                    'variantGid'  => $variantGid,
                    'amount'      => $amount,
                    'currencyCode' => $currencyCode,
                    'compareAt'   => $compareAt,
                    'quantityMin' => $quantityMin,
                    'ruleName'    => $ruleName,
                ];

                if (!isset($grouped[$ruleId])) {
                    $grouped[$ruleId] = [
                        'ruleName' => $ruleName,
                        'entries'  => [],
                    ];
                }

                // Replace existing entry for same dedupeKey with lower price
                $replaced = false;
                foreach ($grouped[$ruleId]['entries'] as $i => $existing) {
                    if ($existing['variantGid'] === $variantGid && $existing['quantityMin'] === $quantityMin) {
                        $grouped[$ruleId]['entries'][$i] = $entryData;
                        $replaced = true;
                        break;
                    }
                }
                if (!$replaced) {
                    $grouped[$ruleId]['entries'][] = $entryData;
                }
            }
        }

        return $grouped;
    }
}
