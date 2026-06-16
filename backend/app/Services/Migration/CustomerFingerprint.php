<?php

namespace App\Services\Migration;

class CustomerFingerprint
{
    public function make(array $customerPayload): string
    {
        $normalized = $this->normalize($customerPayload);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalize($value)
    {
        if (is_array($value)) {
            $isAssoc = $this->isAssoc($value);

            if ($isAssoc) {
                ksort($value);
            }

            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalize($v);
            }

            return $out;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function isAssoc(array $arr): bool
    {
        $keys = array_keys($arr);
        return array_keys($keys) !== $keys;
    }
}
