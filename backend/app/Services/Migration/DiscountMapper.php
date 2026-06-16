<?php

namespace App\Services\Migration;

use Carbon\Carbon;

class DiscountMapper
{
    /**
     * Map a Magento sales rule to a Shopify discount mutation payload.
     *
     * @return array{
     *   mutation: string|null,
     *   variables: array<string, mixed>,
     *   issues: string[],
     *   skipped: bool,
     *   skip_reason: string|null
     * }
     */
    public function map(array $rule): array
    {
        $issues = [];
        
        $hasCode = (isset($rule['coupon_code']) && trim((string) $rule['coupon_code']) !== '') || ((int) ($rule['coupon_type'] ?? 1) === 2);
        
        $action = strtolower(trim((string) ($rule['simple_action'] ?? 'by_percent')));
        $isFreeShipping = (int) ($rule['simple_free_shipping'] ?? 0) > 0;
        
        if ($isFreeShipping) {
            $mutation = $hasCode ? 'discountCodeFreeShippingCreate' : 'discountAutomaticFreeShippingCreate';
        } else {
            $mutation = $hasCode ? 'discountCodeBasicCreate' : 'discountAutomaticBasicCreate';
        }

        $title = trim((string) ($rule['name'] ?? 'Magento Discount Rule'));
        if ($title === '') {
            $title = 'Magento Discount #' . ($rule['rule_id'] ?? 'Rule');
        }
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }

        // Dates
        $startsAt = null;
        $endsAt = null;
        if (isset($rule['from_date']) && trim((string) $rule['from_date']) !== '') {
            try {
                $startsAt = Carbon::parse($rule['from_date'])->utc()->toIso8601String();
            } catch (\Throwable) {
                $issues[] = 'Could not parse from_date: ' . $rule['from_date'];
            }
        }
        if ($startsAt === null) {
            $startsAt = Carbon::now()->utc()->toIso8601String();
        }

        if (isset($rule['to_date']) && trim((string) $rule['to_date']) !== '') {
            try {
                $endsAt = Carbon::parse($rule['to_date'])->utc()->toIso8601String();
            } catch (\Throwable) {
                $issues[] = 'Could not parse to_date: ' . $rule['to_date'];
            }
        }

        // Inactive rules
        $isActive = (bool) ($rule['is_active'] ?? true);
        if (!$isActive) {
            $startsAt = Carbon::now()->addYears(100)->startOfDay()->utc()->toIso8601String();
            $endsAt = null;
        }

        $input = [
            'title' => $title,
            'startsAt' => $startsAt,
        ];
        if ($endsAt !== null) {
            $input['endsAt'] = $endsAt;
        }

        // Apply discount values
        if (!$isFreeShipping) {
            $value = (float) ($rule['discount_amount'] ?? 0);
            if ($action === 'by_percent') {
                if ($value > 100) {
                    $value = 100.0;
                    $issues[] = 'Percentage discount amount capped at 100%';
                }
                $pct = round($value / 100, 10);
                $input['customerGets'] = [
                    'value' => ['percentage' => $pct],
                    'items' => ['all' => true],
                ];
            } else {
                // fixed
                $input['customerGets'] = [
                    'value' => [
                        'discountAmount' => [
                            'amount' => number_format($value, 2, '.', ''),
                            'appliesOnEachItem' => false,
                        ],
                    ],
                    'items' => ['all' => true],
                ];
            }
        }

        // Code discount specific settings
        if ($hasCode) {
            $input['customerSelection'] = ['all' => true];
            
            $code = trim((string) ($rule['coupon_code'] ?? ''));
            if ($code === '') {
                $code = 'MAGENTO_' . ($rule['rule_id'] ?? rand(1000, 9999));
            }
            $input['code'] = $code;

            $usesPerCoupon = (int) ($rule['uses_per_coupon'] ?? 0);
            if ($usesPerCoupon > 0) {
                $input['usageLimit'] = $usesPerCoupon;
            }

            $usesPerCustomer = (int) ($rule['uses_per_customer'] ?? 0);
            if ($usesPerCustomer === 1) {
                $input['appliesOncePerCustomer'] = true;
            } elseif ($usesPerCustomer > 1) {
                $input['appliesOncePerCustomer'] = false;
                $issues[] = "Per-customer limit of {$usesPerCustomer} cannot be fully mapped (Shopify only supports once-per-customer)";
            }
        }

        // Metafields to store Magento info under the magento namespace
        $metafields = [];
        if (isset($rule['rule_id'])) {
            $metafields[] = [
                'namespace' => 'magento',
                'key' => 'rule_id',
                'type' => 'single_line_text_field',
                'value' => (string) $rule['rule_id'],
            ];
        }
        if (isset($rule['simple_action'])) {
            $metafields[] = [
                'namespace' => 'magento',
                'key' => 'simple_action',
                'type' => 'single_line_text_field',
                'value' => (string) $rule['simple_action'],
            ];
        }

        $inputKey = $this->mutationInputKey($mutation);

        return [
            'mutation' => $mutation,
            'variables' => [
                $inputKey => $input,
                '_metafields' => $metafields,
            ],
            'issues' => $issues,
            'skipped' => false,
            'skip_reason' => null,
        ];
    }

    private function mutationInputKey(string $mutation): string
    {
        return match ($mutation) {
            'discountAutomaticBasicCreate'        => 'automaticBasicDiscount',
            'discountAutomaticBasicUpdate'        => 'automaticBasicDiscount',
            'discountAutomaticFreeShippingCreate' => 'freeShippingAutomaticDiscount',
            'discountAutomaticFreeShippingUpdate' => 'freeShippingAutomaticDiscount',
            'discountCodeBasicCreate'             => 'basicCodeDiscount',
            'discountCodeBasicUpdate'             => 'basicCodeDiscount',
            'discountCodeFreeShippingCreate'      => 'freeShippingCodeDiscount',
            'discountCodeFreeShippingUpdate'      => 'freeShippingCodeDiscount',
            default                               => 'discount',
        };
    }

    public function updateMutation(string $createMutation): string
    {
        return str_replace('Create', 'Update', $createMutation);
    }
}
