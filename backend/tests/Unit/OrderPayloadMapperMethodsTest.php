<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Migration\OrderPayloadMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OrderPayloadMapperMethodsTest extends TestCase
{
    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($object);
        $callable = $reflection->getMethod($method);
        $callable->setAccessible(true);

        return $callable->invoke($object, ...$args);
    }

    public function test_finalize_mailing_address_for_shopify(): void
    {
        $mapper = new OrderPayloadMapper();
        $addr = [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'company' => 'Google',
            'street' => ['1600 Amphitheatre Pkwy', 'Suite 100'],
            'city' => 'Mountain View',
            'postcode' => '94043',
            'country_id' => 'US',
            'telephone' => '+16502530000',
        ];

        $res = $this->invokePrivate($mapper, 'finalizeMailingAddressForShopify', $addr);

        $this->assertSame('John', $res['firstName']);
        $this->assertSame('Doe', $res['lastName']);
        $this->assertSame('Google', $res['company']);
        $this->assertSame('1600 Amphitheatre Pkwy', $res['address1']);
        $this->assertSame('Suite 100', $res['address2']);
        $this->assertSame('Mountain View', $res['city']);
        $this->assertSame('94043', $res['zip']);
        $this->assertSame('US', $res['countryCode']);
        $this->assertSame('+16502530000', $res['phone']);
    }

    public function test_resolve_billing_address(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'billing_address' => [
                'firstname' => 'John',
                'lastname' => 'Doe',
            ],
        ];

        $res = $this->invokePrivate($mapper, 'resolveBillingAddress', $order);
        $this->assertSame('John', $res['firstname']);
    }

    public function test_resolve_shipping_address_from_shipping_assignments(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'extension_attributes' => [
                'shipping_assignments' => [
                    [
                        'shipping' => [
                            'address' => [
                                'firstname' => 'Jane',
                                'lastname' => 'Doe',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->invokePrivate($mapper, 'resolveShippingAddress', $order);
        $this->assertSame('Jane', $res['firstname']);
    }

    public function test_resolve_shipping_address_from_shipping_address_field(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'shipping_address' => [
                'firstname' => 'Bob',
                'lastname' => 'Smith',
            ],
        ];

        $res = $this->invokePrivate($mapper, 'resolveShippingAddress', $order);
        $this->assertSame('Bob', $res['firstname']);
    }

    public function test_map_line_items_skips_children(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'items' => [
                [
                    'name' => 'Parent Configurable',
                    'qty_ordered' => 2,
                    'price' => 49.99,
                    'sku' => 'CONFIG-01',
                ],
                [
                    'name' => 'Child Simple',
                    'qty_ordered' => 2,
                    'price' => 49.99,
                    'sku' => 'SIMPLE-01',
                    'parent_item_id' => 123,
                ],
            ],
        ];

        $res = $this->invokePrivate($mapper, 'mapLineItems', $order, 'USD');

        $this->assertCount(1, $res);
        $this->assertSame('Parent Configurable', $res[0]['title']);
        $this->assertSame(2, $res[0]['quantity']);
        $this->assertSame('CONFIG-01', $res[0]['sku']);
    }

    public function test_map_shipping_lines(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'shipping_description' => 'Flat Rate - Fixed',
            'shipping_amount' => 5.00,
        ];

        $res = $this->invokePrivate($mapper, 'mapShippingLines', $order, 'USD');

        $this->assertCount(1, $res);
        $this->assertSame('Flat Rate - Fixed', $res[0]['title']);
        $this->assertSame('5.00', $res[0]['originalShopityShippingRate']['priceSet']['shopMoney']['amount']);
    }

    public function test_map_order(): void
    {
        $shop = new Shop();
        $shop->id = 1;

        $mapper = new OrderPayloadMapper();
        $order = [
            'entity_id' => 99,
            'increment_id' => '100000099',
            'customer_email' => 'customer@example.com',
            'order_currency_code' => 'EUR',
            'grand_total' => 104.99,
            'status' => 'processing',
            'payment' => [
                'method' => 'checkmo',
            ],
            'billing_address' => [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'street' => ['Main Street 123'],
                'city' => 'Berlin',
                'postcode' => '10115',
                'country_id' => 'DE',
            ],
            'items' => [
                [
                    'name' => 'Product 1',
                    'qty_ordered' => 1,
                    'price' => 99.99,
                    'sku' => 'PROD-1',
                ],
            ],
            'shipping_description' => 'Flat Rate',
            'shipping_amount' => 5.00,
        ];

        $mapped = $mapper->mapOrder($shop, $order);

        $this->assertIsArray($mapped);
        $this->assertSame('PENDING', $mapped['order']['financialStatus']);
        $this->assertSame('104.99', $mapped['payment_capture']['amount']);
        $this->assertSame('checkmo', $mapped['payment_capture']['paymentMethodName']);
        $this->assertSame('customer@example.com', $mapped['order']['email']);
        $this->assertSame('EUR', $mapped['order']['currency']);
        $this->assertSame('100000099', $mapped['order']['name']);
    }
}
