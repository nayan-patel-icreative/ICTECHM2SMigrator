<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Support\Facades\Cache;

class ShopifyProductSyncService
{
    private ShopifyAdminGraphqlClient $client;

    private const CUSTOM_ID_NAMESPACE = 'shopware';

    private const CUSTOM_ID_KEY = 'custom_id';

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{productGid?: string, variantIdByShopwareId?: array<string, string>, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function upsertByCustomId(Shop $shop, string $sourceId, array $productSetPayload): array
    {
        $ensure = $this->ensureShopwareIdMetafieldDefinition($shop);
        if (! empty($ensure['errors']) || ! empty($ensure['userErrors'])) {
            return $ensure;
        }
        $ensureProductDefs = $this->ensureCommonProductMetafieldDefinitions($shop);
        if (! empty($ensureProductDefs['errors']) || ! empty($ensureProductDefs['userErrors'])) {
            return $ensureProductDefs;
        }

        $mutation = <<<'GQL'
mutation UpsertProduct($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
  productSet(synchronous: true, input: $input, identifier: $identifier) {
    product {
      id
      title
      variants(first: 100) {
        nodes {
          id
          metafield(namespace: "shopware", key: "variant_id") {
            value
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $shopwareId = trim($sourceId);
        if ($shopwareId === '') {
            return ['userErrors' => [['message' => 'Missing Shopware sourceId for identifier']]];
        }

        $res = $this->client->query($shop, $mutation, [
            'input' => $productSetPayload,
            'identifier' => [
                'customId' => [
                    'namespace' => self::CUSTOM_ID_NAMESPACE,
                    'key' => self::CUSTOM_ID_KEY,
                    'value' => $shopwareId,
                ],
            ],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.productSet.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        $productId = data_get($res, 'data.productSet.product.id');
        if (is_string($productId) && $productId !== '') {
            return [
                'productGid' => $productId,
                'variantIdByShopwareId' => $this->variantMapFromProductSetResponse($res),
                'allVariantGids' => $this->allVariantGidsFromProductSetResponse($res),
            ];
        }

        return ['userErrors' => [['message' => 'Shopify productSet did not return a product id']]];
    }

    /**
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function setupAndPinCommonProductMetafields(Shop $shop): array
    {
        return $this->ensureCommonProductMetafieldDefinitions($shop);
    }

    /**
     * One-time warmup so product workers don't block on first-wave definition setup.
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function warmupProductDefinitions(Shop $shop): array
    {
        $custom = $this->ensureShopwareIdMetafieldDefinition($shop);
        if (!empty($custom['errors']) || !empty($custom['userErrors'])) {
            return $custom;
        }

        return $this->ensureCommonProductMetafieldDefinitions($shop);
    }

    /**
     * Shopify requires the metafield definition to exist when using ProductSetIdentifiers.customId.
     * Also ensures the PRODUCTVARIANT-level shopware.variant_id definition exists so that
     * variant_id metafields stored during productSet are queryable (needed for image association).
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function ensureShopwareIdMetafieldDefinition(Shop $shop): array
    {
        $cacheKey = 'shopify:product_custom_id_definition_ensured:'.$shop->id;
        if (Cache::get($cacheKey)) {
            return ['ok' => true];
        }

        $lock = Cache::lock('shopify:product_custom_id_definition_lock:'.$shop->id, 60);
        return $lock->block(20, function () use ($shop, $cacheKey) {
            if (Cache::get($cacheKey)) {
                return ['ok' => true];
            }

            $mutation = <<<'GQL'
mutation CreateDef($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message }
  }
}
GQL;

            // 1. Ensure PRODUCT-level custom_id definition (required for ProductSetIdentifiers)
            $query = <<<'GQL'
query FindDef {
  metafieldDefinitions(first: 1, ownerType: PRODUCT, namespace: "shopware", key: "custom_id") {
    nodes { id }
  }
}
GQL;
            $res = $this->client->query($shop, $query, []);
            if (isset($res['errors'])) {
                return ['errors' => $res['errors']];
            }

            if ((string) data_get($res, 'data.metafieldDefinitions.nodes.0.id', '') === '') {
                $create = $this->client->query($shop, $mutation, [
                    'definition' => [
                        'name' => 'Magento Custom ID',
                        'namespace' => self::CUSTOM_ID_NAMESPACE,
                        'key' => self::CUSTOM_ID_KEY,
                        'ownerType' => 'PRODUCT',
                        'type' => 'single_line_text_field',
                        'pin' => true,
                    ],
                ]);

                if (isset($create['errors'])) {
                    return ['errors' => $create['errors']];
                }

                $userErrors = data_get($create, 'data.metafieldDefinitionCreate.userErrors', []);
                if (is_array($userErrors) && count($userErrors) > 0) {
                    $nonFatal = array_filter($userErrors, function ($e) {
                        $msg = strtolower((string) data_get($e, 'message', ''));
                        return str_contains($msg, 'key is in use') || str_contains($msg, 'already exists');
                    });
                    if (count($nonFatal) !== count($userErrors)) {
                        return ['userErrors' => $userErrors];
                    }
                }
            }

            // 2. Ensure PRODUCTVARIANT-level variant_id definition (required for image-to-variant mapping)
            $variantQuery = <<<'GQL'
query FindVariantDef {
  metafieldDefinitions(first: 1, ownerType: PRODUCTVARIANT, namespace: "shopware", key: "variant_id") {
    nodes { id }
  }
}
GQL;
            $variantRes = $this->client->query($shop, $variantQuery, []);
            if (isset($variantRes['errors'])) {
                // Non-fatal: log and continue — image association will still attempt but may not persist
                \Illuminate\Support\Facades\Log::warning('Could not query PRODUCTVARIANT variant_id definition', [
                    'shop' => $shop->shop_domain,
                    'errors' => $variantRes['errors'],
                ]);
            } elseif ((string) data_get($variantRes, 'data.metafieldDefinitions.nodes.0.id', '') === '') {
                $variantCreate = $this->client->query($shop, $mutation, [
                    'definition' => [
                        'name' => 'Magento Variant ID',
                        'namespace' => 'shopware',
                        'key' => 'variant_id',
                        'ownerType' => 'PRODUCTVARIANT',
                        'type' => 'single_line_text_field',
                        'pin' => false,
                    ],
                ]);

                if (isset($variantCreate['errors'])) {
                    \Illuminate\Support\Facades\Log::warning('Could not create PRODUCTVARIANT variant_id definition', [
                        'shop' => $shop->shop_domain,
                        'errors' => $variantCreate['errors'],
                    ]);
                } else {
                    $variantUserErrors = data_get($variantCreate, 'data.metafieldDefinitionCreate.userErrors', []);
                    if (is_array($variantUserErrors) && count($variantUserErrors) > 0) {
                        $nonFatal = array_filter($variantUserErrors, function ($e) {
                            $msg = strtolower((string) data_get($e, 'message', ''));
                            return str_contains($msg, 'key is in use') || str_contains($msg, 'already exists');
                        });
                        if (count($nonFatal) !== count($variantUserErrors)) {
                            \Illuminate\Support\Facades\Log::warning('PRODUCTVARIANT variant_id definition creation errors', [
                                'shop' => $shop->shop_domain,
                                'userErrors' => $variantUserErrors,
                            ]);
                        }
                    }
                }
            }

            Cache::put($cacheKey, 1, now()->addDays(7));

            return ['ok' => true];
        });
    }

    /**
     * Ensure SEO support metafields are visible in Shopify Admin Metafields section.
     *
     * Uses a single batch query to find which definitions already exist, then only
     * creates the missing ones. The `pin: true` flag in the create input is sufficient
     * — no separate pin API calls are needed.
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function ensureCommonProductMetafieldDefinitions(Shop $shop): array
    {
        $cacheKey = 'shopify:product_common_metafields_ensured_v3:'.$shop->id;
        if (Cache::get($cacheKey)) {
            return ['ok' => true];
        }

        $lock = Cache::lock('shopify:product_common_metafields_lock:'.$shop->id, 120);
        return $lock->block(30, function () use ($shop, $cacheKey) {
            // Double-check after acquiring lock — another worker may have finished first.
            if (Cache::get($cacheKey)) {
                return ['ok' => true];
            }

            // Clean up any obsolete metafield definitions
            $this->cleanupObsoleteMetafieldDefinitions($shop);

            $definitions = [
                ['name' => 'SEO Keywords',           'namespace' => 'shopware', 'key' => 'seo_keywords',         'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'SEO Source Path',         'namespace' => 'shopware', 'key' => 'seo_path_source',      'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Magento Product ID',     'namespace' => 'shopware', 'key' => 'product_id',           'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Magento Product Number', 'namespace' => 'shopware', 'key' => 'product_number',       'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Magento Active',         'namespace' => 'shopware', 'key' => 'active',               'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Magento Weight (kg)',    'namespace' => 'shopware', 'key' => 'weight_kg',            'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Width',              'namespace' => 'shopware', 'key' => 'spec_width',           'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Height',             'namespace' => 'shopware', 'key' => 'spec_height',          'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Length',             'namespace' => 'shopware', 'key' => 'spec_length',          'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Weight',             'namespace' => 'shopware', 'key' => 'spec_weight',          'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Purchase Unit',      'namespace' => 'shopware', 'key' => 'spec_purchase_unit',   'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Reference Unit',     'namespace' => 'shopware', 'key' => 'spec_reference_unit',  'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Pack Unit',          'namespace' => 'shopware', 'key' => 'spec_pack_unit',       'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Pack Unit Plural',   'namespace' => 'shopware', 'key' => 'spec_pack_unit_plural','ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Unit',               'namespace' => 'shopware', 'key' => 'spec_unit',            'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Properties',         'namespace' => 'shopware', 'key' => 'spec_properties',      'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Specification JSON',      'namespace' => 'shopware', 'key' => 'specification_json',   'ownerType' => 'PRODUCT', 'type' => 'json',                   'pin' => true],
                ['name' => 'Price Currency',          'namespace' => 'shopware', 'key' => 'price_currency',        'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Tax Rate',               'namespace' => 'shopware', 'key' => 'tax_rate',               'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Tax Name',               'namespace' => 'shopware', 'key' => 'tax_name',               'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Advanced Price Count',   'namespace' => 'shopware', 'key' => 'advanced_price_count',   'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Advanced Prices JSON',   'namespace' => 'shopware', 'key' => 'advanced_prices_json',   'ownerType' => 'PRODUCT', 'type' => 'json',                   'pin' => true],
                ['name' => 'Price Mode',             'namespace' => 'shopware', 'key' => 'price_mode',             'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Magento Download Links', 'namespace' => 'shopware', 'key' => 'download_links',       'ownerType' => 'PRODUCT', 'type' => 'list.link',              'pin' => true],
            ];

            // --- Step 1: Fetch all existing shopware-namespace definitions in one API call ---
            $existingKeys = $this->fetchExistingProductMetafieldKeys($shop, 'shopware');
            if ($existingKeys === null) {
                // API error — fall back to attempting all creates (safe, "key is in use" is handled below)
                $existingKeys = [];
            }

            // --- Step 2: Only create definitions that don't exist yet ---
            $mutation = <<<'GQL'
mutation CreateDef($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message }
  }
}
GQL;

            foreach ($definitions as $definition) {
                // Skip if already exists — pin: true in the create input handles pinning on creation
                if (in_array($definition['key'], $existingKeys, true)) {
                    continue;
                }

                $create = $this->client->query($shop, $mutation, [
                    'definition' => $definition,
                ]);

                if (isset($create['errors'])) {
                    return ['errors' => $create['errors']];
                }

                $userErrors = data_get($create, 'data.metafieldDefinitionCreate.userErrors', []);
                if (is_array($userErrors) && count($userErrors) > 0) {
                    $nonFatal = array_filter($userErrors, function ($e) {
                        $msg = strtolower((string) data_get($e, 'message', ''));
                        return str_contains($msg, 'key is in use') || str_contains($msg, 'already exists');
                    });
                    if (count($nonFatal) !== count($userErrors)) {
                        return ['userErrors' => $userErrors];
                    }
                    // "key is in use" — definition already exists, that's fine
                }
                // Note: pin: true in the definition input pins it on creation.
                // No separate pinProductMetafieldDefinition call needed.
            }

            Cache::put($cacheKey, 1, now()->addDays(7));
            return ['ok' => true];
        });
    }

    /**
     * Fetch all existing metafield definition keys for a given namespace and owner type
     * in a single paginated API call. Returns null on API error.
     *
     * @return array<int, string>|null
     */
    private function fetchExistingProductMetafieldKeys(Shop $shop, string $namespace): ?array
    {
        $query = <<<'GQL'
query ExistingDefs($namespace: String!) {
  metafieldDefinitions(first: 50, ownerType: PRODUCT, namespace: $namespace) {
    nodes { key }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['namespace' => $namespace]);
        if (isset($res['errors'])) {
            return null;
        }

        $nodes = data_get($res, 'data.metafieldDefinitions.nodes', []);
        if (!is_array($nodes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($n) => is_array($n) ? (string) ($n['key'] ?? '') : '',
            $nodes
        ), fn ($k) => $k !== ''));
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array<string, string>
     */
    private function variantMapFromProductSetResponse(array $res): array
    {
        $nodes = data_get($res, 'data.productSet.product.variants.nodes', []);
        $nodes = is_array($nodes) ? $nodes : [];

        $map = [];
        foreach ($nodes as $n) {
            $variantId = (string) data_get($n, 'id', '');
            $swId = (string) data_get($n, 'metafield.value', '');
            if ($variantId !== '' && $swId !== '') {
                $map[$swId] = $variantId;
            }
        }

        return $map;
    }

    /**
     * Return all variant GIDs from the productSet response (used for simple products
     * that have no Shopware variant ID metafield but still need price list sync).
     *
     * @param  array<string, mixed>  $res
     * @return array<int, string>
     */
    private function allVariantGidsFromProductSetResponse(array $res): array
    {
        $nodes = data_get($res, 'data.productSet.product.variants.nodes', []);
        $nodes = is_array($nodes) ? $nodes : [];

        $gids = [];
        foreach ($nodes as $n) {
            $variantId = (string) data_get($n, 'id', '');
            if ($variantId !== '') {
                $gids[] = $variantId;
            }
        }

        return $gids;
    }

    /**
     * @param  array<int, array{namespace: string, key: string, type: string, value: string}>  $metafields
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function setProductMetafields(Shop $shop, string $productGid, array $metafields): array
    {
        $mutation = <<<'GQL'
mutation SetMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    userErrors { field message }
    metafields { id namespace key }
  }
}
GQL;

        $inputs = [];
        foreach ($metafields as $m) {
            if (! is_array($m)) {
                continue;
            }

            $ns = (string) ($m['namespace'] ?? '');
            $key = (string) ($m['key'] ?? '');
            $type = (string) ($m['type'] ?? '');
            $value = $m['value'] ?? null;

            if ($ns === '' || $key === '' || $type === '' || $value === null) {
                continue;
            }

            $inputs[] = [
                'ownerId' => $productGid,
                'namespace' => $ns,
                'key' => $key,
                'type' => $type,
                'value' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        if (count($inputs) === 0) {
            return ['ok' => true];
        }

        $res = $this->client->query($shop, $mutation, [
            'metafields' => $inputs,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.metafieldsSet.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Ensure the Shopware Download Links metafield definition exists for a given owner type.
     * Uses type 'list.link' (Link list).
     */
    public function ensureDownloadLinksMetafieldDefinition(Shop $shop, string $ownerType): array
    {
        $cacheKey = 'shopify:download_links_def_ensured:'.$shop->id.':'.$ownerType;
        if (Cache::get($cacheKey)) {
            return ['ok' => true];
        }

        // Fetch existing keys to avoid duplicate creates
        if ($ownerType === 'PRODUCT') {
            $existingKeys = $this->fetchExistingProductMetafieldKeys($shop, 'shopware');
        } else {
            $existingKeys = $this->fetchExistingVariantMetafieldKeys($shop, 'shopware');
        }

        if ($existingKeys === null) {
            $existingKeys = [];
        }

        if (in_array('download_links', $existingKeys, true)) {
            Cache::put($cacheKey, 1, now()->addDays(7));
            return ['ok' => true];
        }

        $mutation = <<<'GQL'
mutation CreateDef($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message }
  }
}
GQL;

        $definition = [
            'name'      => 'Magento Download Links',
            'namespace' => 'shopware',
            'key'       => 'download_links',
            'ownerType' => $ownerType,
            'type'      => 'list.link',
            'pin'       => true,
        ];

        $create = $this->client->query($shop, $mutation, ['definition' => $definition]);
        if (isset($create['errors'])) {
            \Illuminate\Support\Facades\Log::warning("Could not create {$ownerType} download links definition", [
                'shop'   => $shop->shop_domain,
                'errors' => $create['errors'],
            ]);
            return ['errors' => $create['errors']];
        }

        $userErrors = data_get($create, 'data.metafieldDefinitionCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            $allNonFatal = array_filter($userErrors, function ($e) {
                $msg = strtolower((string) data_get($e, 'message', ''));
                return str_contains($msg, 'key is in use') || str_contains($msg, 'already exists');
            });
            if (count($allNonFatal) !== count($userErrors)) {
                \Illuminate\Support\Facades\Log::warning("{$ownerType} download links definition creation errors", [
                    'shop'       => $shop->shop_domain,
                    'userErrors' => $userErrors,
                ]);
            }
        }

        Cache::put($cacheKey, 1, now()->addDays(7));
        return ['ok' => true];
    }

    public function ensureDigitalFileMetafieldDefinitions(Shop $shop, int $fileCount): array
    {
        return $this->ensureDownloadLinksMetafieldDefinition($shop, 'PRODUCT');
    }

    public function ensureVariantDigitalFileMetafieldDefinitions(Shop $shop, int $fileCount): array
    {
        return $this->ensureDownloadLinksMetafieldDefinition($shop, 'PRODUCTVARIANT');
    }

    /**
     * Fetch existing PRODUCTVARIANT metafield definition keys for a namespace.
     *
     * @return array<int, string>|null
     */
    private function fetchExistingVariantMetafieldKeys(Shop $shop, string $namespace): ?array
    {
        $query = <<<'GQL'
query ExistingVariantDefs($namespace: String!) {
  metafieldDefinitions(first: 50, ownerType: PRODUCTVARIANT, namespace: $namespace) {
    nodes { key }
  }
}
GQL;
        $res = $this->client->query($shop, $query, ['namespace' => $namespace]);
        if (isset($res['errors'])) {
            return null;
        }
        $nodes = data_get($res, 'data.metafieldDefinitions.nodes', []);
        if (!is_array($nodes)) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn ($n) => is_array($n) ? (string) ($n['key'] ?? '') : '',
            $nodes
        ), fn ($k) => $k !== ''));
    }

    /**
     * Clean up obsolete digital file metafield definitions in Shopify.
     */
    private function cleanupObsoleteMetafieldDefinitions(Shop $shop): void
    {
        $obsoleteKeys = [
            'digital_files_metadata',
            'digital_file_count',
            'digital_file_1',
            'digital_file_2',
            'digital_file_3',
            'digital_file_4',
            'digital_file_5',
        ];

        $deleteMutation = <<<'GQL'
mutation DeleteMetafieldDef($id: ID!, $deleteAllAssociatedMetafields: Boolean!) {
  metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: $deleteAllAssociatedMetafields) {
    deletedDefinitionId
    userErrors {
      field
      message
    }
  }
}
GQL;

        // Clean up both PRODUCT and PRODUCTVARIANT definitions
        foreach (['PRODUCT', 'PRODUCTVARIANT'] as $ownerType) {
            $query = <<<'GQL'
query GetDefs($namespace: String!, $ownerType: MetafieldOwnerType!) {
  metafieldDefinitions(first: 250, ownerType: $ownerType, namespace: $namespace) {
    nodes {
      id
      key
    }
  }
}
GQL;

            $res = $this->client->query($shop, $query, [
                'namespace' => 'shopware',
                'ownerType' => $ownerType,
            ]);

            if (isset($res['errors'])) {
                continue;
            }

            $nodes = data_get($res, 'data.metafieldDefinitions.nodes', []);
            if (!is_array($nodes)) {
                continue;
            }

            foreach ($nodes as $node) {
                $id = (string) ($node['id'] ?? '');
                $key = (string) ($node['key'] ?? '');
                if ($id === '' || $key === '') {
                    continue;
                }

                $shouldDelete = in_array($key, $obsoleteKeys, true) || str_starts_with($key, 'variant_digital_');
                if ($shouldDelete) {
                    \Illuminate\Support\Facades\Log::info("Deleting obsolete metafield definition in Shopify", [
                        'shop' => $shop->shop_domain,
                        'ownerType' => $ownerType,
                        'key' => $key,
                        'id' => $id,
                    ]);

                    $delRes = $this->client->query($shop, $deleteMutation, [
                        'id' => $id,
                        'deleteAllAssociatedMetafields' => true,
                    ]);

                    if (isset($delRes['errors'])) {
                        \Illuminate\Support\Facades\Log::warning("Failed to delete definition in Shopify", [
                            'shop' => $shop->shop_domain,
                            'id' => $id,
                            'errors' => $delRes['errors'],
                        ]);
                    } else {
                        $userErrors = data_get($delRes, 'data.metafieldDefinitionDelete.userErrors', []);
                        if (is_array($userErrors) && count($userErrors) > 0) {
                            \Illuminate\Support\Facades\Log::warning("User errors deleting definition in Shopify", [
                                'shop' => $shop->shop_domain,
                                'id' => $id,
                                'userErrors' => $userErrors,
                            ]);
                        }
                    }
                }
            }
        }
    }
}
