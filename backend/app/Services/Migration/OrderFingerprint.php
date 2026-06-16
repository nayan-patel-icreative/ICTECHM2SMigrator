<?php

namespace App\Services\Migration;

class OrderFingerprint
{
    public function make(array $payload): string
    {
        $normalized = $this->normalize($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '';
        }

        return hash('sha256', $json);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isAssoc($value)) {
                ksort($value);
            }

            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalize($v);
            }
            return $out;
        }

        return $value;
    }

    private function isAssoc(array $arr): bool
    {
        $keys = array_keys($arr);
        return array_keys($keys) !== $keys;
    }
}
