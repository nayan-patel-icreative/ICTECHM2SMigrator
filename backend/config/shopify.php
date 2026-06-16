<?php

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'scopes' => array_filter(array_map('trim', explode(',', (string) (env('SHOPIFY_SCOPES') ?: 'read_products,write_products,read_inventory,write_inventory,read_locations,write_customers,write_orders,read_publications,write_publications,read_online_store_navigation,write_online_store_navigation,write_files,write_discounts,read_discounts,read_translations,write_translations,read_locales,read_markets,write_markets')))),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    'app_url' => rtrim((string) (env('SHOPIFY_APP_URL') ?: env('APP_URL')), '/'),

    'http' => [
        'max_retries' => (int) env('SHOPIFY_HTTP_MAX_RETRIES', 12),
        'base_backoff_ms' => (int) env('SHOPIFY_HTTP_BASE_BACKOFF_MS', 1000),
    ],
];
