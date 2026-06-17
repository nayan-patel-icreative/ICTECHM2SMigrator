<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Magento\MagentoClient;
use Illuminate\Support\Str;

class ProductPayloadMapper
{
    /**
     * Map a Magento parent product with its variants to a Shopify ProductSet payload.
     *
     * @param array<string, mixed> $parent The Magento parent product
     * @param array<int, array<string, mixed>> $children The Magento variant children
     * @param string $locationGid The Shopify location GID for inventory
     * @param int|null $shopId The Shopify shop ID
     * @param string $priceMode "gross" or "net"
     * @return array<string, mixed>
     */
    public function mapParentWithVariants(array $parent, array $children, string $locationGid, ?int $shopId = null, string $priceMode = 'gross'): array
    {
        $title = $this->normalizeText($parent['name'] ?? '') ?: 'Untitled';

        $descriptionHtml = $this->getCustomAttributeValue($parent, 'description')
            ?: $this->getCustomAttributeValue($parent, 'short_description')
            ?: '';

        $seoTitle = $this->getCustomAttributeValue($parent, 'meta_title') ?: $title;
        $seoDescription = $this->getCustomAttributeValue($parent, 'meta_description') ?: '';

        // Resolve handle
        $urlKey = $this->getCustomAttributeValue($parent, 'url_key') ?: $title;
        $handle = $this->toShopifyHandle($urlKey);

        $vendor = $this->resolveVendor($parent, $shopId);
        $tags = $this->buildTagsFromMagento($parent);

        // Map product category to productType
        $productType = '';
        $categoryIds = $parent['extension_attributes']['category_links'] ?? [];
        if (count($categoryIds) > 0) {
            // Just use a tag-like category prefix or let first category name represent it
            $productType = 'Magento Product';
        }

        // Resolve Options
        $optionResolutions = $this->resolveConfigurableOptions($parent, $shopId);
        $productOptions = $optionResolutions['productOptions'];
        $optionMaps = $optionResolutions['optionMaps'];

        // Resolve Variants
        $variants = $this->buildVariants($parent, $children, $productOptions, $optionMaps, $locationGid, $priceMode);

        $productSet = [
            'title' => $title,
            'descriptionHtml' => $descriptionHtml,
            'vendor' => $vendor,
            'tags' => $tags,
            'status' => $this->mapProductStatus($parent),
            'productType' => $productType,
            'handle' => $handle,
            'productOptions' => $productOptions,
            'variants' => $variants,
        ];

        if ($seoTitle !== '' || $seoDescription !== '') {
            $productSet['seo'] = $this->removeEmpty([
                'title' => $seoTitle,
                'description' => $seoDescription,
            ]);
        }

        return $productSet;
    }

    /**
     * Resolve vendor/manufacturer name.
     */
    private function resolveVendor(array $parent, ?int $shopId): string
    {
        $manufacturerId = $this->getCustomAttributeValue($parent, 'manufacturer');
        if ($manufacturerId && $shopId) {
            $shop = Shop::query()->with('magentoConnection')->find($shopId);
            $conn = $shop ? $shop->magentoConnection : null;
            if ($conn) {
                // Fetch options for manufacturer attribute to resolve ID to text label
                $client = app(MagentoClient::class);
                $attr = $client->getAttributeDetailsByCode($conn, 'manufacturer');
                foreach ($attr['options'] ?? [] as $o) {
                    if ((string)$o['value'] === (string)$manufacturerId) {
                        return trim($o['label']);
                    }
                }
            }
        }

        return 'Unknown';
    }

    /**
     * Resolve Magento configurable options to Shopify productOptions and option value maps.
     */
    private function resolveConfigurableOptions(array $parent, ?int $shopId): array
    {
        $productOptions = [];
        $optionMaps = []; // attribute_code => [option_id => label]
        $pos = 1;

        $shop = $shopId ? Shop::query()->with('magentoConnection')->find($shopId) : null;
        $conn = $shop ? $shop->magentoConnection : null;

        $configurableOptions = $parent['configurable_options'] ?? [];
        foreach ($configurableOptions as $opt) {
            $attrId = $opt['attribute_id'] ?? null;
            if (!$attrId || !$conn) {
                continue;
            }

            $client = app(MagentoClient::class);
            $attrDetails = $client->getAttributeDetails($conn, $attrId);
            $attrCode = $attrDetails['attribute_code'] ?? null;
            if (!$attrCode) {
                continue;
            }

            $optValues = [];
            $valueMap = [];
            foreach ($attrDetails['options'] ?? [] as $o) {
                $val = (string) ($o['value'] ?? '');
                $label = trim((string) ($o['label'] ?? ''));
                if ($val !== '' && $label !== '') {
                    $valueMap[$val] = $label;
                    $optValues[] = ['name' => $label];
                }
            }

            $optionMaps[$attrCode] = [
                'name' => $opt['label'] ?? $attrCode,
                'values' => $valueMap
            ];

            $productOptions[] = [
                'name' => $opt['label'] ?? $attrCode,
                'position' => $pos++,
                'values' => $optValues,
            ];
        }

        if (count($productOptions) === 0) {
            $productOptions[] = [
                'name' => 'Title',
                'position' => 1,
                'values' => [
                    ['name' => 'Default'],
                ],
            ];
        }

        return [
            'productOptions' => $productOptions,
            'optionMaps' => $optionMaps
        ];
    }

    /**
     * Build Shopify variants.
     */
    private function buildVariants(array $parent, array $children, array $productOptions, array $optionMaps, string $locationGid, string $priceMode = 'gross'): array
    {
        $variants = [];
        $seen = [];

        if (count($children) === 0) {
            $v = $this->variantFromMagento($parent, $productOptions, [], $locationGid, $parent, $priceMode);
            $sig = $this->variantSignature($v);
            $seen[$sig] = true;
            $variants[] = $v;
            return $variants;
        }

        foreach ($children as $child) {
            $v = $this->variantFromMagento($child, $productOptions, $optionMaps, $locationGid, $parent, $priceMode);
            $sig = $this->variantSignature($v);
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $variants[] = $v;
        }

        return $variants;
    }

    /**
     * Parse single variant row.
     */
    private function variantFromMagento(array $variant, array $productOptions, array $optionMaps, string $locationGid, array $fallbackParent, string $priceMode = 'gross'): array
    {
        $price = $this->moneyToPrice($variant, $fallbackParent, $priceMode);
        $compareAt = $this->moneyToCompareAtPrice($variant, $fallbackParent, $priceMode);

        if ($compareAt !== null && (float) $compareAt <= (float) $price) {
            $compareAt = null;
        }

        // stock qty
        $extAttrs = $variant['extension_attributes'] ?? [];
        $stockItem = $extAttrs['stock_item'] ?? [];
        $inventoryQty = $this->numericInt($stockItem['qty'] ?? 0);

        $inventoryPolicy = ($stockItem['backorders'] ?? 0) > 0 ? 'CONTINUE' : 'DENY';
        $taxable = true; // default taxable true for Shopify

        // Option values
        $optionValues = [];
        if (count($optionMaps) > 0) {
            foreach ($optionMaps as $attrCode => $info) {
                $valId = $this->getCustomAttributeValue($variant, $attrCode);
                $label = $info['values'][$valId] ?? 'Default';
                $optionValues[] = [
                    'optionName' => $info['name'],
                    'name' => $label,
                ];
            }
        }

        if (count($optionValues) === 0) {
            $optionValues[] = [
                'optionName' => 'Title',
                'name' => 'Default',
            ];
        }

        $payload = [
            'price' => $price,
            'sku' => $this->normalizeText($variant['sku'] ?? ''),
            'barcode' => $this->normalizeText($this->getCustomAttributeValue($variant, 'ean') ?: ''),
            'taxable' => $taxable,
            'inventoryPolicy' => $inventoryPolicy,
            'optionValues' => $optionValues,
            'inventoryQuantities' => [
                [
                    'locationId' => $locationGid,
                    'name' => 'available',
                    'quantity' => $inventoryQty,
                ],
            ],
        ];

        if ($compareAt !== null) {
            $payload['compareAtPrice'] = $compareAt;
        }

        // Custom variant metafields (e.g. Magento variant ID)
        $mId = (string) ($variant['id'] ?? '');
        if ($mId !== '') {
            $payload['metafields'] = [[
                'namespace' => 'magento',
                'key' => 'variant_id',
                'type' => 'single_line_text_field',
                'value' => $mId,
            ]];
        }

        return $this->removeEmpty($payload);
    }

    /**
     * Map variant prices for external Price List.
     */
    public function extractVariantPricesForPriceList(
        array $variantIdByShopwareId,
        array $parent,
        array $children,
        ?Shop $shop = null,
        array $allVariantGids = [],
        string $priceMode = 'gross'
    ): array {
        $currency = 'USD'; // default fallback
        if ($shop && $shop->magentoConnection) {
            $storeViews = app(MagentoClient::class)->getStoreViews($shop->magentoConnection);
            foreach ($storeViews as $sv) {
                if ($sv['code'] === $shop->magentoConnection->store_view_code) {
                    $currency = $sv['currency'];
                    break;
                }
            }
        }

        $variantPrices = [];
        $variantComparePrices = [];

        if (count($children) === 0) {
            $price = $this->moneyToPrice($parent, $parent, $priceMode);
            $compareAt = $this->moneyToCompareAtPrice($parent, $parent, $priceMode);

            $gids = count($variantIdByShopwareId) > 0 ? array_values($variantIdByShopwareId) : $allVariantGids;
            foreach ($gids as $variantGid) {
                if (is_string($variantGid) && $variantGid !== '') {
                    $variantPrices[$variantGid] = $price;
                    $variantComparePrices[$variantGid] = $compareAt;
                }
            }
        } else {
            foreach ($children as $child) {
                $mId = (string) ($child['id'] ?? '');
                if ($mId === '') {
                    continue;
                }
                $variantGid = $variantIdByShopwareId[$mId] ?? null;
                if (!$variantGid) {
                    continue;
                }
                $price = $this->moneyToPrice($child, $parent, $priceMode);
                $compareAt = $this->moneyToCompareAtPrice($child, $parent, $priceMode);
                $variantPrices[$variantGid] = $price;
                $variantComparePrices[$variantGid] = $compareAt;
            }
        }

        return [
            'currency' => $currency,
            'variantPrices' => $variantPrices,
            'variantComparePrices' => $variantComparePrices,
        ];
    }

    /**
     * Map product metafields.
     */
    public function mapShopwareMetafields(array $parent, array $children = [], ?Shop $shop = null, string $priceMode = 'gross'): array
    {
        $out = [];
        // Magento Custom ID (type: id)
        $this->pushProductMetafield($out, 'custom_id', (string) ($parent['id'] ?? ''), 'id');

        // Magento Product SKU
        $this->pushProductMetafield($out, 'product_number', (string) ($parent['sku'] ?? ''));

        // Magento Active status
        $status = (int) ($parent['status'] ?? 1);
        $this->pushProductMetafield($out, 'active', $status === 1 ? '1' : '0');

        // Weight
        $weight = $parent['weight'] ?? null;
        if (is_numeric($weight) && (float) $weight > 0) {
            $this->pushProductMetafield($out, 'weight_kg', (string)(float)$weight);
        }

        // SEO Keywords
        $seoKeywords = $this->getCustomAttributeValue($parent, 'meta_keyword');
        if ($seoKeywords) {
            $this->pushProductMetafield($out, 'seo_keywords', (string) $seoKeywords);
        }

        // Specification JSON
        $ignoredAttributes = [
            'image',
            'small_image',
            'thumbnail',
            'image_label',
            'small_image_label',
            'thumbnail_label',
            'meta_title',
            'meta_keyword',
            'meta_description',
            'description',
            'url_key',
            'options_container',
            'required_options',
            'has_options',
            'gift_message_available',
            'msrp_display_actual_price_type',
            'tax_class_id',
            'category_ids',
            'custom_layout',
            'custom_layout_update_xml',
            'custom_design',
            'page_layout',
            'status',
            'visibility',
            'news_from_date',
            'news_to_date',
            'special_price',
            'special_from_date',
            'special_to_date',
            'tier_price',
        ];

        $specs = [];
        $attrs = $parent['custom_attributes'] ?? [];
        foreach ($attrs as $attr) {
            $code = $attr['attribute_code'] ?? '';
            $val = $attr['value'] ?? null;
            if ($code !== '' && $val !== null && !in_array($code, $ignoredAttributes, true)) {
                $specs[$code] = $val;
            }
        }
        if (count($specs) > 0) {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'specification_json',
                'type' => 'json',
                'value' => json_encode($specs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        // Price Mode
        $this->pushProductMetafield($out, 'price_mode', $priceMode);

        // Price Currency
        $currency = 'USD';
        if ($shop && $shop->magentoConnection) {
            try {
                $storeViews = app(\App\Services\Magento\MagentoClient::class)->getStoreViews($shop->magentoConnection);
                foreach ($storeViews as $sv) {
                    if ($sv['code'] === $shop->magentoConnection->store_view_code) {
                        $currency = $sv['currency'] ?? 'USD';
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Keep default USD on failure
            }
        }
        $this->pushProductMetafield($out, 'price_currency', $currency);

        // Tax details JSON
        $taxClassId = $this->getCustomAttributeValue($parent, 'tax_class_id');
        $taxDetails = [
            'tax_class_id' => $taxClassId,
            'taxable' => true,
            'rules' => [],
        ];

        if ($shop && $shop->magentoConnection && $taxClassId !== null && $taxClassId !== '') {
            $taxRules = \Illuminate\Support\Facades\Cache::remember('magento_tax_rules:' . $shop->magentoConnection->id, now()->addHour(), function () use ($shop) {
                try {
                    $client = app(\App\Services\Magento\MagentoClient::class);
                    $query = $client->buildSearchCriteria(200, 1);
                    $res = $client->request($shop->magentoConnection, 'GET', '/V1/taxRules/search', ['query' => $query]);
                    return $res['items'] ?? [];
                } catch (\Throwable $e) {
                    return [];
                }
            });

            $taxRates = \Illuminate\Support\Facades\Cache::remember('magento_tax_rates:' . $shop->magentoConnection->id, now()->addHour(), function () use ($shop) {
                try {
                    $client = app(\App\Services\Magento\MagentoClient::class);
                    $query = $client->buildSearchCriteria(500, 1);
                    $res = $client->request($shop->magentoConnection, 'GET', '/V1/taxRates/search', ['query' => $query]);
                    return $res['items'] ?? [];
                } catch (\Throwable $e) {
                    return [];
                }
            });

            $matchedRules = [];
            foreach ($taxRules as $rule) {
                $productClasses = array_map('intval', $rule['product_tax_class_ids'] ?? []);
                if (in_array((int)$taxClassId, $productClasses, true)) {
                    $ruleRates = [];
                    $rateIds = array_map('intval', $rule['tax_rate_ids'] ?? []);
                    foreach ($rateIds as $rateId) {
                        foreach ($taxRates as $rate) {
                            if ((int)($rate['id'] ?? 0) === $rateId) {
                                $ruleRates[] = [
                                    'id' => $rateId,
                                    'code' => $rate['code'] ?? '',
                                    'rate' => (float)($rate['rate'] ?? 0.0),
                                    'country' => $rate['tax_country_id'] ?? '',
                                    'region' => $rate['region_name'] ?? $rate['tax_region_id'] ?? '',
                                    'postcode' => $rate['tax_postcode'] ?? '',
                                ];
                            }
                        }
                    }
                    $matchedRules[] = [
                        'id' => $rule['id'] ?? null,
                        'code' => $rule['code'] ?? '',
                        'rates' => $ruleRates,
                    ];
                }
            }
            $taxDetails['rules'] = $matchedRules;
        }

        $out[] = [
            'namespace' => 'magento',
            'key' => 'tax_details_json',
            'type' => 'json',
            'value' => json_encode($taxDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        // Tier / Advanced Prices JSON
        $tierPrices = $parent['tier_prices'] ?? [];
        if (is_array($tierPrices) && count($tierPrices) > 0) {
            $out[] = [
                'namespace' => 'magento',
                'key' => 'advanced_prices_json',
                'type' => 'json',
                'value' => json_encode($tierPrices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
            $this->pushProductMetafield($out, 'advanced_price_count', (string) count($tierPrices));
        } else {
            $this->pushProductMetafield($out, 'advanced_price_count', '0');
        }

        return $out;
    }

    /**
     * Get value of custom attribute by code.
     */
    public function getCustomAttributeValue(array $product, string $code)
    {
        $attrs = $product['custom_attributes'] ?? [];
        foreach ($attrs as $attr) {
            if (($attr['attribute_code'] ?? '') === $code) {
                return $attr['value'];
            }
        }
        return null;
    }

    /**
     * Map product status.
     */
    private function mapProductStatus(array $parent): string
    {
        $status = (int) ($parent['status'] ?? 1);
        return $status === 1 ? 'ACTIVE' : 'DRAFT';
    }

    /**
     * Build tags.
     */
    private function buildTagsFromMagento(array $product): array
    {
        $tags = [];
        $tags[] = 'Magento';
        if (isset($product['type_id'])) {
            $tags[] = 'type:' . $product['type_id'];
        }
        return $tags;
    }

    private function variantSignature(array $variantPayload): string
    {
        $sku = $this->normalizeText($variantPayload['sku'] ?? '');
        if ($sku !== '') {
            return 'sku:'.$sku;
        }
        return md5(json_encode($variantPayload));
    }

    private function moneyToPrice(array $product, array $fallbackProduct, string $priceMode = 'gross'): string
    {
        // Magento product sale price detection
        // If special_price is set, check if it falls within special_from_date & special_to_date
        $specialPrice = $this->getCustomAttributeValue($product, 'special_price');
        $hasSpecial = false;
        if ($specialPrice && is_numeric($specialPrice) && (float)$specialPrice > 0) {
            $from = $this->getCustomAttributeValue($product, 'special_from_date');
            $to = $this->getCustomAttributeValue($product, 'special_to_date');
            $now = time();

            $fromOk = !$from || strtotime($from) <= $now;
            $toOk = !$to || strtotime($to) >= $now;

            if ($fromOk && $toOk) {
                $hasSpecial = true;
            }
        }

        if ($hasSpecial) {
            return number_format((float)$specialPrice, 2, '.', '');
        }

        $price = $product['price'] ?? $fallbackProduct['price'] ?? 0;
        return number_format((float)$price, 2, '.', '');
    }

    private function moneyToCompareAtPrice(array $product, array $fallbackProduct, string $priceMode = 'gross'): ?string
    {
        // If special price is active, the compare-at price is the regular price
        $specialPrice = $this->getCustomAttributeValue($product, 'special_price');
        $hasSpecial = false;
        if ($specialPrice && is_numeric($specialPrice) && (float)$specialPrice > 0) {
            $from = $this->getCustomAttributeValue($product, 'special_from_date');
            $to = $this->getCustomAttributeValue($product, 'special_to_date');
            $now = time();

            $fromOk = !$from || strtotime($from) <= $now;
            $toOk = !$to || strtotime($to) >= $now;

            if ($fromOk && $toOk) {
                $hasSpecial = true;
            }
        }

        if ($hasSpecial) {
            $price = $product['price'] ?? $fallbackProduct['price'] ?? 0;
            return number_format((float)$price, 2, '.', '');
        }

        return null;
    }

    private function numericInt($v): int
    {
        if (!is_numeric($v)) {
            return 0;
        }
        return (int) floor((float) $v);
    }

    private function normalizeText($v): string
    {
        if ($v === null) {
            return '';
        }
        return trim((string) $v);
    }

    private function removeEmpty(array $payload): array
    {
        foreach (array_keys($payload) as $k) {
            if ($payload[$k] === '' || $payload[$k] === null) {
                unset($payload[$k]);
            }
        }
        return $payload;
    }

    private function toShopifyHandle(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $handle = Str::slug($value, '-');
        return substr($handle, 0, 255);
    }

    private function pushProductMetafield(array &$out, string $key, string $value, string $type = 'single_line_text_field'): void
    {
        $key = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '_', $key) ?? '', '_'));
        $value = trim($value);
        if ($key === '' || $value === '') {
            return;
        }

        $out[] = [
            'namespace' => 'magento',
            'key' => $key,
            'type' => $type,
            'value' => $value,
        ];
    }

    /**
     * Parse Magento downloadable product links to retrieve digital files for upload.
     *
     * @param array $product The Magento product details
     * @return array
     */
    public function extractDigitalDownloadFiles(array $product): array
    {
        $links = data_get($product, 'extension_attributes.downloadable_product_links', []);
        if (!is_array($links)) {
            return [];
        }

        $files = [];
        foreach ($links as $link) {
            $title = data_get($link, 'title') ?: 'Digital File';
            $linkType = data_get($link, 'link_type');
            
            $fileData = [
                'mediaId' => (string) data_get($link, 'id'),
                'fileName' => $title,
                'fileExtension' => '',
                'mimeType' => 'application/octet-stream',
                'path' => '',
                'private' => true,
                'hasFile' => true,
                'url' => '',
            ];

            if ($linkType === 'url') {
                $url = data_get($link, 'link_url', '');
                if ($url) {
                    $fileData['url'] = $url;
                    $fileData['private'] = false;
                    $fileData['path'] = '';
                } else {
                    continue;
                }
            } else {
                $linkFile = data_get($link, 'link_file', '');
                if ($linkFile) {
                    // Path relative to files_path (/var/www/html/magento2/pub/media)
                    $fileData['path'] = 'downloadable/files/links' . $linkFile;
                    $fileData['private'] = true;
                    $fileData['url'] = '';
                    
                    // Determine file extension
                    $ext = strtolower(pathinfo($linkFile, PATHINFO_EXTENSION));
                    if ($ext) {
                        $fileData['fileExtension'] = $ext;
                        // Map standard mime types
                        $mimeMap = [
                            'pdf'  => 'application/pdf',
                            'zip'  => 'application/zip',
                            'csv'  => 'text/csv',
                            'jpg'  => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'png'  => 'image/png',
                            'gif'  => 'image/gif',
                            'mp3'  => 'audio/mpeg',
                            'mp4'  => 'video/mp4',
                        ];
                        if (isset($mimeMap[$ext])) {
                            $fileData['mimeType'] = $mimeMap[$ext];
                        }
                    }
                } else {
                    continue;
                }
            }

            $files[] = $fileData;
        }

        return $files;
    }
}
