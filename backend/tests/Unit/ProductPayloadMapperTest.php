<?php

namespace Tests\Unit;

use App\Services\Migration\ProductPayloadMapper;
use PHPUnit\Framework\TestCase;

class ProductPayloadMapperTest extends TestCase
{
    public function test_description_falls_back_when_description_empty(): void
    {
        $mapper = new ProductPayloadMapper();
        $payload = $mapper->mapParentWithVariants([
            'name' => 'Product Name',
            'custom_attributes' => [
                ['attribute_code' => 'description', 'value' => 'Parent description'],
            ],
            'type_id' => 'simple',
            'sku' => 'SIMPLE-SKU',
            'price' => 10.99,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('Parent description', $payload['descriptionHtml']);
        $this->assertSame('ACTIVE', $payload['status']);
    }

    public function test_inactive_product_maps_to_draft_or_active(): void
    {
        $mapper = new ProductPayloadMapper();
        
        // Active Magento product
        $payloadActive = $mapper->mapParentWithVariants([
            'name' => 'Active Product',
            'status' => 1, // Active in Magento
            'sku' => 'SKU-ACT',
            'price' => 10.00,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('ACTIVE', $payloadActive['status']);

        // Inactive Magento product
        $payloadInactive = $mapper->mapParentWithVariants([
            'name' => 'Inactive Product',
            'status' => 2, // Inactive in Magento
            'sku' => 'SKU-INACT',
            'price' => 12.00,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('DRAFT', $payloadInactive['status']);
    }

    public function test_map_magento_metafields(): void
    {
        $mapper = new ProductPayloadMapper();
        $fields = $mapper->mapMagentoMetafields([
            'id' => 123,
            'sku' => 'PN-1',
            'weight' => 2.5,
        ], []);

        $keys = array_column($fields, 'key');
        $this->assertContains('custom_id', $keys);
        $this->assertContains('product_number', $keys);
        $this->assertContains('weight_kg', $keys);

        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field['value'];
        }

        $this->assertSame('123', $byKey['custom_id']);
        $this->assertSame('PN-1', $byKey['product_number']);
        $this->assertSame('2.5', $byKey['weight_kg']);
    }

    public function test_maps_handle_from_url_key(): void
    {
        $mapper = new ProductPayloadMapper();
        $payload = $mapper->mapParentWithVariants([
            'name' => 'Fallback Name',
            'sku' => 'PN-SEO-1',
            'price' => 15.00,
            'custom_attributes' => [
                ['attribute_code' => 'url_key', 'value' => 'main-product-url-key'],
                ['attribute_code' => 'meta_title', 'value' => 'SEO Title'],
                ['attribute_code' => 'meta_description', 'value' => 'SEO Description'],
            ],
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('main-product-url-key', $payload['handle']);
        $this->assertSame('SEO Title', $payload['seo']['title']);
        $this->assertSame('SEO Description', $payload['seo']['description']);
    }

    public function test_digital_product_requires_shipping_is_false(): void
    {
        $mapper = new ProductPayloadMapper();

        // Downloadable product (digital)
        $payloadDownloadable = $mapper->mapParentWithVariants([
            'name' => 'Digital E-Book',
            'sku' => 'BOOK-DIGITAL',
            'price' => 5.00,
            'type_id' => 'downloadable',
        ], [], 'gid://shopify/Location/1');

        $this->assertFalse($payloadDownloadable['variants'][0]['inventoryItem']['requiresShipping']);

        // Virtual product (digital)
        $payloadVirtual = $mapper->mapParentWithVariants([
            'name' => 'SaaS Membership',
            'sku' => 'SAAS-SUB',
            'price' => 29.00,
            'type_id' => 'virtual',
        ], [], 'gid://shopify/Location/1');

        $this->assertFalse($payloadVirtual['variants'][0]['inventoryItem']['requiresShipping']);
    }

    public function test_physical_product_requires_shipping_is_true(): void
    {
        $mapper = new ProductPayloadMapper();

        // Simple product (physical)
        $payloadSimple = $mapper->mapParentWithVariants([
            'name' => 'Physical Mug',
            'sku' => 'MUG-PHYSICAL',
            'price' => 12.00,
            'type_id' => 'simple',
        ], [], 'gid://shopify/Location/1');

        $this->assertTrue($payloadSimple['variants'][0]['inventoryItem']['requiresShipping']);
    }
}
