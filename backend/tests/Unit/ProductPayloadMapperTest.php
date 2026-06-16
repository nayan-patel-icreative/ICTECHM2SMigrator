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
        $fields = $mapper->mapShopwareMetafields([
            'id' => 123,
            'sku' => 'PN-1',
            'weight' => 2.5,
        ], []);

        $keys = array_column($fields, 'key');
        $this->assertContains('product_id', $keys);
        $this->assertContains('sku', $keys);
        $this->assertContains('weight', $keys);

        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field['value'];
        }

        $this->assertSame('123', $byKey['product_id']);
        $this->assertSame('PN-1', $byKey['sku']);
        $this->assertSame('2.5', $byKey['weight']);
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
}
