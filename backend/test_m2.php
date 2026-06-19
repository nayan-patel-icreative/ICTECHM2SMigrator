<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $cust = Illuminate\Support\Facades\DB::select("SELECT COUNT(*) as cnt FROM magentonew.customer_entity");
    echo "Customers: " . $cust[0]->cnt . "\n";

    $ord = Illuminate\Support\Facades\DB::select("SELECT COUNT(*) as cnt FROM magentonew.sales_order");
    echo "Orders: " . $ord[0]->cnt . "\n";

    try {
        $news = Illuminate\Support\Facades\DB::select("SELECT COUNT(*) as cnt FROM magentonew.newsletter_subscriber");
        echo "Newsletter: " . $news[0]->cnt . "\n";
    } catch (\Throwable $e) {
        echo "Newsletter error: " . $e->getMessage() . "\n";
    }

    try {
        $rules = Illuminate\Support\Facades\DB::select("SELECT COUNT(*) as cnt FROM magentonew.salesrule");
        echo "Discounts: " . $rules[0]->cnt . "\n";
    } catch (\Throwable $e) {
        echo "Discounts error: " . $e->getMessage() . "\n";
    }

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
