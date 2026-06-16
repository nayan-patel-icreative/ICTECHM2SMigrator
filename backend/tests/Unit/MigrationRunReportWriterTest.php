<?php

namespace Tests\Unit;

use App\Models\MigrationItem;
use App\Services\Migration\MigrationRunReportWriter;
use Tests\TestCase;

class MigrationRunReportWriterTest extends TestCase
{
    public function test_humanizeFailureReason_prefers_userErrors_message(): void
    {
        $writer = new MigrationRunReportWriter();

        $item = new MigrationItem();
        $item->error_message = 'Fallback';
        $item->error_context = [
            'userErrors' => [
                [
                    'message' => 'Shopify rejected variant: Option "Size" is required',
                ],
            ],
        ];

        $reason = $writer->humanizeFailureReason($item);
        $this->assertStringContainsString('Option', $reason);
        $this->assertStringContainsString('required', $reason);
    }

    public function test_headersForType_products(): void
    {
        $writer = new MigrationRunReportWriter();
        $headers = $writer->headersForType('products');

        $this->assertSame('shopware_product_id', $headers[0]);
        $this->assertContains('variant_count', $headers);
        $this->assertContains('migrated_at_utc', $headers);
    }

    public function test_humanizeFailureReason_falls_back_to_error_message(): void
    {
        $writer = new MigrationRunReportWriter();

        $item = new MigrationItem();
        $item->error_message = 'Fallback message';
        $item->error_context = null;

        $reason = $writer->humanizeFailureReason($item);
        $this->assertSame('Fallback message', $reason);
    }

    public function test_humanizeFailureReason_truncates_long_messages(): void
    {
        $writer = new MigrationRunReportWriter();

        $item = new MigrationItem();
        $item->error_context = [
            'userErrors' => [
                ['message' => str_repeat('A', 400)],
            ],
        ];

        $reason = $writer->humanizeFailureReason($item);
        $this->assertStringEndsWith('…', $reason);
        $this->assertTrue(mb_strlen($reason) <= 241);
    }
}
