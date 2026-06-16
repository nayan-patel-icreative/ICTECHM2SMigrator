<?php

namespace App\Services\Migration;

class DiscountFingerprint
{
    /**
     * @param  array<string, mixed>  $rule
     */
    public function make(array $rule): string
    {
        $payload = [
            'name' => $rule['name'] ?? null,
            'is_active' => $rule['is_active'] ?? null,
            'from_date' => $rule['from_date'] ?? null,
            'to_date' => $rule['to_date'] ?? null,
            'simple_action' => $rule['simple_action'] ?? null,
            'discount_amount' => $rule['discount_amount'] ?? null,
            'coupon_code' => $rule['coupon_code'] ?? null,
            'uses_per_customer' => $rule['uses_per_customer'] ?? null,
            'uses_per_coupon' => $rule['uses_per_coupon'] ?? null,
        ];

        ksort($payload);
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }
}
