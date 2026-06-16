<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Migration\StateAssignmentMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class StateAssignmentMapperTest extends TestCase
{
    private function mapperWithMappings(array $mappings): StateAssignmentMapper
    {
        $mapper = new StateAssignmentMapper();
        $shop = new Shop();
        $shop->id = 1;
        $shop->shop_domain = 'test.myshopify.com';

        $reflection = new ReflectionClass($mapper);
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue($mapper, [1 => $mappings]);

        return $mapper;
    }

    public function test_transaction_state_open_to_paid_maps_financial_status(): void
    {
        $mapper = $this->mapperWithMappings([
            'transaction_state' => ['open' => 'paid'],
        ]);

        $shop = new Shop();
        $shop->id = 1;
        $order = [
            'state' => ['technicalName' => 'open'],
            'transactions' => [
                ['state' => ['technicalName' => 'open']],
            ],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
        $this->assertSame('SUCCESS', $mapper->transactionStatusForFinancialStatus('PAID'));
    }

    public function test_order_state_paid_used_when_transaction_maps_to_pending(): void
    {
        $mapper = $this->mapperWithMappings([
            'transaction_state' => ['open' => 'pending'],
            'order_state' => ['open' => 'paid'],
        ]);

        $shop = new Shop();
        $shop->id = 1;
        $order = [
            'state' => ['technicalName' => 'open'],
            'transactions' => [
                ['state' => ['technicalName' => 'open']],
            ],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
        $this->assertSame('SUCCESS', $mapper->transactionStatusForFinancialStatus('PAID'));
    }

    public function test_transaction_paid_takes_priority_over_order_pending(): void
    {
        $mapper = $this->mapperWithMappings([
            'transaction_state' => ['paid' => 'paid'],
            'order_state' => ['open' => 'pending'],
        ]);

        $shop = new Shop();
        $shop->id = 1;
        $order = [
            'state' => ['technicalName' => 'open'],
            'transactions' => [
                ['state' => ['technicalName' => 'paid']],
            ],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
    }

    public function test_order_state_used_when_no_transactions(): void
    {
        $mapper = $this->mapperWithMappings([
            'order_state' => ['completed' => 'paid'],
        ]);

        $shop = new Shop();
        $shop->id = 1;
        $order = [
            'state' => ['technicalName' => 'completed'],
            'transactions' => [],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
    }

    public function test_delivery_state_open_to_fulfilled(): void
    {
        $mapper = $this->mapperWithMappings([
            'delivery_state' => ['open' => 'fulfilled'],
        ]);

        $shop = new Shop();
        $shop->id = 1;
        $order = [
            'deliveries' => [
                ['state' => ['technicalName' => 'open']],
            ],
        ];

        $this->assertSame('FULFILLED', $mapper->resolveFulfillmentStatus($shop, $order));
    }

    public function test_delivery_open_to_fulfilled_when_no_deliveries_use_order_state(): void
    {
        $mapper = $this->mapperWithMappings([
            'delivery_state' => ['open' => 'fulfilled'],
        ]);

        $shop = new Shop();
        $shop->id = 1;
        $order = [
            'state' => ['technicalName' => 'open'],
            'deliveries' => [],
        ];

        $this->assertSame('FULFILLED', $mapper->resolveFulfillmentStatus($shop, $order));
    }
}
