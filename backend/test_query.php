<?php
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Temporarily configure a new connection using Laravel config
    config(['database.connections.magentonew' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'magentonew',
        'username' => 'root',
        'password' => 'admin123',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]]);

    echo "--- STORES ---\n";
    $stores = DB::connection('magentonew')->select("SELECT store_id, code, name FROM store");
    print_r($stores);

    echo "--- ATTRIBUTE OPTIONS FOR ID 83 ---\n";
    $opts = DB::connection('magentonew')->select("SELECT * FROM eav_attribute_option WHERE attribute_id = 83");
    print_r($opts);

    echo "--- OPTION VALUES FOR ID 83 ---\n";
    $vals = DB::connection('magentonew')->select("SELECT * FROM eav_attribute_option_value WHERE option_id IN (SELECT option_id FROM eav_attribute_option WHERE attribute_id = 83)");
    print_r($vals);

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
