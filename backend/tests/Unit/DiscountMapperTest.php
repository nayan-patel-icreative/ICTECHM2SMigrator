<?php

namespace Tests\Unit;

use App\Services\Migration\DiscountMapper;
use PHPUnit\Framework\TestCase;

class DiscountMapperTest extends TestCase
{
    public function test_maps_basic_percentage_rule(): void
    {
        $mapper = new DiscountMapper();
        $res = $mapper->map([
            'rule_id' => 3,
            'name' => '20% OFF Ever $200-plus purchase!*',
            'simple_action' => 'by_percent',
            'discount_amount' => 20.00,
            'coupon_type' => 'NO_COUPON',
            'is_active' => 1,
            'condition' => [
                'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Combine',
                'conditions' => [
                    [
                        'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Address',
                        'operator' => '>=',
                        'attribute_name' => 'base_subtotal',
                        'value' => 200.00,
                    ]
                ]
            ]
        ]);

        $this->assertSame('discountAutomaticBasicCreate', $res['mutation']);
        $variables = $res['variables']['automaticBasicDiscount'];
        $this->assertSame('20% OFF Ever $200-plus purchase!*', $variables['title']);
        $this->assertSame(0.2, $variables['customerGets']['value']['percentage']);
        $this->assertSame('200.00', $variables['minimumRequirement']['subtotal']['greaterThanOrEqualToSubtotal']);
    }

    public function test_maps_specific_coupon_code_rule(): void
    {
        $mapper = new DiscountMapper();
        $res = $mapper->map([
            'rule_id' => 4,
            'name' => '$4 Luma water bottle (save 70%)',
            'simple_action' => 'by_percent',
            'discount_amount' => 70.00,
            'coupon_type' => 'SPECIFIC_COUPON',
            'coupon_code' => 'H20',
            'uses_per_customer' => 1,
            'uses_per_coupon' => 100,
            'is_active' => 1,
        ]);

        $this->assertSame('discountCodeBasicCreate', $res['mutation']);
        $variables = $res['variables']['basicCodeDiscount'];
        $this->assertSame('H20', $variables['code']);
        $this->assertSame(100, $variables['usageLimit']);
        $this->assertTrue($variables['appliesOncePerCustomer']);
    }

    public function test_maps_free_shipping_subtotal_rule(): void
    {
        $mapper = new DiscountMapper();
        $res = $mapper->map([
            'rule_id' => 2,
            'name' => 'Spend $50 or more - shipping is free!',
            'simple_action' => 'by_percent',
            'discount_amount' => 0.00,
            'coupon_type' => 'NO_COUPON',
            'simple_free_shipping' => 2,
            'is_active' => 1,
            'condition' => [
                'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Combine',
                'conditions' => [
                    [
                        'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Address',
                        'operator' => '>=',
                        'attribute_name' => 'base_subtotal',
                        'value' => 50.00,
                    ]
                ]
            ]
        ]);

        $this->assertSame('discountAutomaticFreeShippingCreate', $res['mutation']);
        $variables = $res['variables']['freeShippingAutomaticDiscount'];
        $this->assertSame('50.00', $variables['minimumRequirement']['subtotal']['greaterThanOrEqualToSubtotal']);
    }
}
