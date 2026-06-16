<?php

namespace Tests\Feature;

use App\Models\MigrationRun;
use App\Models\Shop;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MigrationRunReportDownloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_report_download_is_allowed_for_same_shop_and_blocked_for_other_shop(): void
    {
        config()->set('shopify.api_key', 'test-key');
        config()->set('shopify.api_secret', 'test-secret');

        $domainA = 'shop-a-' . uniqid() . '.myshopify.com';
        $domainB = 'shop-b-' . uniqid() . '.myshopify.com';

        $shopA = Shop::query()->create([
            'shop_domain' => $domainA,
            'access_token' => 'token-a',
        ]);

        $shopB = Shop::query()->create([
            'shop_domain' => $domainB,
            'access_token' => 'token-b',
        ]);

        $reportPath = storage_path('framework/testing/report-download-test.csv');
        file_put_contents($reportPath, "a,b\n1,2\n");

        $run = MigrationRun::query()->create([
            'shop_id' => $shopA->id,
            'type' => 'products',
            'status' => 'finished',
            'report_path' => $reportPath,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $tokenA = JWT::encode([
            'aud' => 'test-key',
            'dest' => 'https://' . $domainA,
            'exp' => now()->addMinutes(5)->timestamp,
        ], 'test-secret', 'HS256');

        $ok = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
            ->get('/api/migration/runs/' . $run->id . '/report');
        $ok->assertOk();
        $ok->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $tokenB = JWT::encode([
            'aud' => 'test-key',
            'dest' => 'https://' . $domainB,
            'exp' => now()->addMinutes(5)->timestamp,
        ], 'test-secret', 'HS256');

        $forbidden = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
            ->get('/api/migration/runs/' . $run->id . '/report');
        $forbidden->assertStatus(404);
    }
}
