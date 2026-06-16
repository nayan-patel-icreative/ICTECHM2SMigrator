<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use App\Models\Shop;

$shops = Shop::all();
foreach ($shops as $shop) {
    $key = 'shopify:order_doc_metafields_ensured:' . $shop->id;
    Cache::forget($key);
    echo "Cleared order doc metafield cache for: {$shop->shop_domain}\n";
}
echo "Done.\n";
