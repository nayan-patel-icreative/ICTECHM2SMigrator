<?php

namespace App\Services\Migration;

class ManufacturerFingerprint
{
    /**
     * @param  array<string, mixed>  $manufacturer
     */
    public function make(array $manufacturer): string
    {
        $payload = [
            'name' => data_get($manufacturer, 'name'),
            'translated' => data_get($manufacturer, 'translated'),
            'description' => data_get($manufacturer, 'description'),
            'link' => data_get($manufacturer, 'link'),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }
}
