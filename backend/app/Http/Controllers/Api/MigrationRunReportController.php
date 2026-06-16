<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MigrationRun;
use App\Models\Shop;
use Illuminate\Http\Request;

class MigrationRunReportController extends Controller
{
    public function download(Request $request, MigrationRun $run)
    {
        $shop = $this->authorizedShop($request, $run);
        if (! $shop) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $path = $this->resolveCsvPath($run);

        if (! is_file($path)) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        $name = sprintf('migration-%s-run-%d.csv', (string) $run->type, (int) $run->id);
        return response()->download($path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizedShop(Request $request, MigrationRun $run): ?Shop
    {
        /** @var Shop|null $shop */
        $shop = $request->attributes->get('shop');
        if (! $shop || (int) $run->shop_id !== (int) $shop->id) {
            return null;
        }

        return $shop;
    }

    private function resolveCsvPath(MigrationRun $run): string
    {
        $path = is_string($run->report_path) ? trim($run->report_path) : '';
        if ($path !== '') {
            return $path;
        }

        return storage_path('app/migration-reports/shop_' . (int) $run->shop_id . '/run_' . (int) $run->id . '.csv');
    }
}
