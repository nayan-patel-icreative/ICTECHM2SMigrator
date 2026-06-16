<?php

namespace App\Support;

class ShopifyHmac
{
    public static function buildFromArray(array $params, string $secret): string
    {
        unset($params['hmac'], $params['signature']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $pairs[] = $key.'='.str_replace('%', '%25', str_replace('&', '%26', str_replace('=', '%3D', (string) $value)));
        }

        $data = implode('&', $pairs);

        return hash_hmac('sha256', $data, $secret);
    }

    public static function verifyQuery(array $params, string $secret, ?string $hmacProvided): bool
    {
        if (!$hmacProvided) {
            return false;
        }

        $calculated = self::buildFromArray($params, $secret);

        return hash_equals($calculated, $hmacProvided);
    }

    public static function verifyWebhook(string $rawBody, string $secret, ?string $hmacHeader): bool
    {
        if (!$hmacHeader) {
            return false;
        }

        $digest = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($digest, $hmacHeader);
    }
}
