<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MagentoConnection;
use App\Services\Magento\MagentoClient;

$conn = MagentoConnection::first();
$magento = new MagentoClient();

echo "=== WEBSITES ===\n";
try {
    $websites = $magento->request($conn, 'GET', '/store/websites');
    print_r($websites);
} catch (\Throwable $e) {
    echo "Websites Error: " . $e->getMessage() . "\n";
}

echo "\n=== STORE GROUPS ===\n";
try {
    $groups = $magento->request($conn, 'GET', '/store/storeGroups');
    print_r($groups);
} catch (\Throwable $e) {
    echo "Groups Error: " . $e->getMessage() . "\n";
}

echo "\n=== STORE VIEWS ===\n";
try {
    $stores = $magento->request($conn, 'GET', '/store/storeViews');
    print_r($stores);
} catch (\Throwable $e) {
    echo "Stores Error: " . $e->getMessage() . "\n";
}
