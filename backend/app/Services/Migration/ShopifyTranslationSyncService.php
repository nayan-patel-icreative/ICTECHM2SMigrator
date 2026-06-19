<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Syncs Magento translated content to Shopify via two mechanisms:
 *
 * 1. Shopify Translations API (translationsRegister mutation) — pushes locale-specific
 *    strings into Shopify's native multilingual system for storefront display.
 *
 * 2. Shopify Metafields — stores a JSON snapshot of all translations under the
 *    'magento_translations' namespace for reference/custom usage.
 *
 * Design principles:
 * - NEVER throws exceptions — all errors are logged and returned as soft failures.
 * - Zero overhead when no languages are configured (early return).
 * - All locales batched into a single translationsRegister call per resource.
 */
class ShopifyTranslationSyncService
{
    private ShopifyAdminGraphqlClient $client;

    /**
     * Mapping from Magento translation field name → Shopify translation key.
     * These are the standard keys recognised by Shopify's Translations API.
     */
    private const FIELD_MAP = [
        'name'            => 'title',
        'description'     => 'body_html',
        'metaTitle'       => 'meta_title',
        'metaDescription' => 'meta_description',
    ];

    /**
     * Mapping for Collection (custom_collection / smart_collection) translation keys.
     * Shopify uses slightly different keys for collections.
     */
    private const COLLECTION_FIELD_MAP = [
        'name'            => 'title',
        'description'     => 'body_html',
        'metaTitle'       => 'meta_title',
        'metaDescription' => 'meta_description',
    ];

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Sync translations for a Shopify resource (product, collection, etc.).
     *
     * @param  array<string, array<string, string>>  $translationsByLocale
     *         Format: ['de-DE' => ['name' => '...', 'description' => '...'], ...]
     *         Keys are Magento field names (name, description, metaTitle, metaDescription).
     * @param  array<string, string>  $fieldMap  Optional override of FIELD_MAP
     * @return array{ok?: bool, errors?: mixed}
     */
    public function syncTranslations(
        Shop $shop,
        string $resourceGid,
        array $translationsByLocale,
        array $fieldMap = []
    ): array {
        if (empty($translationsByLocale)) {
            return ['ok' => true];
        }

        $map = !empty($fieldMap) ? $fieldMap : self::FIELD_MAP;

        $shopLocalesInfo = $this->getShopLocales($shop);
        $primaryLocale = $shopLocalesInfo['primary'] ?? 'en';
        $publishedLocales = $shopLocalesInfo['published'] ?? [];

        // Fetch translatable content digests from Shopify
        $digests = $this->getTranslatableContentDigests($shop, $resourceGid);

        // Build all TranslationInput entries, grouped by locale
        $translationInputs = [];
        foreach ($translationsByLocale as $locale => $fields) {
            if (!is_array($fields) || $locale === '') {
                continue;
            }

            $mappedLocale = $this->matchLocale($locale, $publishedLocales);
            if ($mappedLocale === null || strtolower($mappedLocale) === strtolower($primaryLocale)) {
                continue;
            }

            foreach ($fields as $swField => $value) {
                $shopifyKey = $map[$swField] ?? null;
                if ($shopifyKey === null) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }

                // If the key is not in Shopify's translatableContent list, we skip it
                $digest = $digests[$shopifyKey] ?? null;
                if ($digest === null) {
                    continue;
                }

                $translationInputs[] = [
                    'locale' => $mappedLocale,
                    'key'    => $shopifyKey,
                    'value'  => $value,
                    'translatableContentDigest' => $digest,
                ];
            }
        }

        if (empty($translationInputs)) {
            return ['ok' => true];
        }

        $result = ['ok' => true];

        // --- 1. Shopify Translations API ---
        try {
            $transResult = $this->pushTranslationsApi($shop, $resourceGid, $translationInputs);
            if (!empty($transResult['errors']) || !empty($transResult['userErrors'])) {
                Log::warning('ShopifyTranslationSyncService: translationsRegister partial failure', [
                    'resource_gid' => $resourceGid,
                    'result'       => $transResult,
                    'translation_inputs' => $translationInputs,
                    'shop_locales' => $shopLocalesInfo,
                ]);
                $result['translation_api_errors'] = $transResult;
            } else {
                Log::info('ShopifyTranslationSyncService: translationsRegister success', [
                    'resource_gid' => $resourceGid,
                    'translation_inputs' => $translationInputs,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ShopifyTranslationSyncService: translationsRegister exception (non-fatal)', [
                'resource_gid' => $resourceGid,
                'error'        => $e->getMessage(),
            ]);
            $result['translation_api_exception'] = $e->getMessage();
        }

        // --- 2. Store as metafield JSON snapshot ---
        try {
            $mfResult = $this->storeTranslationsMetafield($shop, $resourceGid, $translationsByLocale);
            if (!empty($mfResult['errors']) || !empty($mfResult['userErrors'])) {
                Log::warning('ShopifyTranslationSyncService: metafield storage partial failure', [
                    'resource_gid' => $resourceGid,
                    'result'       => $mfResult,
                ]);
                $result['metafield_errors'] = $mfResult;
            }
        } catch (\Throwable $e) {
            Log::warning('ShopifyTranslationSyncService: metafield storage exception (non-fatal)', [
                'resource_gid' => $resourceGid,
                'error'        => $e->getMessage(),
            ]);
            $result['metafield_exception'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Sync collection translations using the collection-specific field map.
     *
     * @param  array<string, array<string, string>>  $translationsByLocale
     */
    public function syncCollectionTranslations(
        Shop $shop,
        string $collectionGid,
        array $translationsByLocale
    ): array {
        return $this->syncTranslations($shop, $collectionGid, $translationsByLocale, self::COLLECTION_FIELD_MAP);
    }

    /**
     * Store entity language preference as a metafield (for customers, orders, etc.
     * where there is no translatable content but the entity has a language preference).
     *
     * @param  array<string, string>  $extraMeta  Additional key=>value metadata to store
     */
    public function storeLanguagePreferenceMetafield(
        Shop $shop,
        string $resourceGid,
        string $languageLocale,
        string $languageName = '',
        array $extraMeta = []
    ): array {
        if ($languageLocale === '') {
            return ['ok' => true];
        }

        try {
            $value = json_encode(array_filter([
                'locale' => $languageLocale,
                'name'   => $languageName,
            ] + $extraMeta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($value)) {
                return ['ok' => true];
            }

            $mutation = <<<'GQL'
mutation SetTranslationMeta($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    userErrors { field message }
  }
}
GQL;

            $res = $this->client->query($shop, $mutation, [
                'metafields' => [[
                    'ownerId'   => $resourceGid,
                    'namespace' => 'magento_translations',
                    'key'       => 'language_preference',
                    'type'      => 'json',
                    'value'     => $value,
                ]],
            ]);

            if (isset($res['errors'])) {
                return ['errors' => $res['errors']];
            }
            $userErrors = data_get($res, 'data.metafieldsSet.userErrors', []);
            if (is_array($userErrors) && count($userErrors) > 0) {
                return ['userErrors' => $userErrors];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('ShopifyTranslationSyncService: storeLanguagePreferenceMetafield exception (non-fatal)', [
                'resource_gid' => $resourceGid,
                'error'        => $e->getMessage(),
            ]);
            return ['exception' => $e->getMessage()];
        }
    }

    /**
     * Push translations via Shopify's Translations API.
     * All locales are sent in a single mutation call.
     *
     * @param  array<int, array{locale: string, key: string, value: string, translatableContentDigest: string}>  $translationInputs
     */
    private function pushTranslationsApi(Shop $shop, string $resourceGid, array $translationInputs): array
    {
        $mutation = <<<'GQL'
mutation TranslationsRegister($resourceId: ID!, $translations: [TranslationInput!]!) {
  translationsRegister(resourceId: $resourceId, translations: $translations) {
    translations {
      locale
      key
      value
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'resourceId'   => $resourceGid,
            'translations' => $translationInputs,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.translationsRegister.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Store all translations as a single JSON metafield for reference.
     * Namespace: 'magento_translations', key: 'all_translations'
     *
     * @param  array<string, array<string, string>>  $translationsByLocale
     */
    private function storeTranslationsMetafield(Shop $shop, string $resourceGid, array $translationsByLocale): array
    {
        $json = json_encode($translationsByLocale, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return ['ok' => true];
        }

        // Shopify metafield value limit is 512KB
        if (strlen($json) > 512000) {
            return ['ok' => true, 'skipped' => 'translations_too_large'];
        }

        $mutation = <<<'GQL'
mutation SetTranslationMeta($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'metafields' => [[
                'ownerId'   => $resourceGid,
                'namespace' => 'magento_translations',
                'key'       => 'all_translations',
                'type'      => 'json',
                'value'     => $json,
            ]],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.metafieldsSet.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Extract translations from a Magento entity's 'translations' association.
     *
     * Magento returns translations as an array of objects, each with:
     * - languageId
     * - name, description, metaTitle, metaDescription, etc.
     *
     * We map languageId → locale using the enabledLanguages list and return
     * only translations for the enabled languages.
     *
     * @param  array<string, mixed>  $entity          Magento entity (product, category, etc.)
     * @param  array<int, array{id: string, locale: string, name: string}>  $enabledLanguages
     * @return array<string, array<string, string>>  locale => [field => value]
     */
    public static function extractTranslationsFromEntity(array $entity, array $enabledLanguages): array
    {
        if (empty($enabledLanguages)) {
            return [];
        }

        // Build a quick lookup: languageId => locale
        $localeById = [];
        foreach ($enabledLanguages as $lang) {
            $id     = trim((string) ($lang['id'] ?? ''));
            $locale = trim((string) ($lang['locale'] ?? ''));
            if ($id !== '' && $locale !== '') {
                $localeById[$id] = $locale;
            }
        }

        $rawTranslations = data_get($entity, 'translations', []);
        if (!is_array($rawTranslations)) {
            return [];
        }

        $result = [];
        foreach ($rawTranslations as $t) {
            if (!is_array($t)) {
                continue;
            }

            $langId = trim((string) ($t['languageId'] ?? ''));
            $locale = $localeById[$langId] ?? null;

            if ($locale === null) {
                // Language not in the enabled list — skip
                continue;
            }

            $fields = [];
            foreach (['name', 'description', 'metaTitle', 'metaDescription'] as $field) {
                $val = trim((string) ($t[$field] ?? ''));
                if ($val !== '') {
                    $fields[$field] = $val;
                }
            }

            if (!empty($fields)) {
                $result[$locale] = $fields;
            }
        }

        return $result;
    }

    /**
     * Fetch digests for all translatable content of the given resource GID.
     *
     * @return array<string, string> Key-to-digest mapping
     */
    private function getTranslatableContentDigests(Shop $shop, string $resourceGid): array
    {
        $query = <<<'GQL'
query getTranslatableResource($id: ID!) {
  translatableResource(resourceId: $id) {
    translatableContent {
      key
      digest
    }
  }
}
GQL;
        try {
            $res = $this->client->query($shop, $query, ['id' => $resourceGid]);
            $digests = [];
            $contents = data_get($res, 'data.translatableResource.translatableContent', []);
            if (is_array($contents)) {
                foreach ($contents as $item) {
                    if (isset($item['key'], $item['digest'])) {
                        $digests[$item['key']] = $item['digest'];
                    }
                }
            }
            return $digests;
        } catch (\Throwable $e) {
            Log::warning('ShopifyTranslationSyncService: Failed to fetch translatable resource digests', [
                'resource_gid' => $resourceGid,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Fetch the list of published locales and primary locale for the shop.
     * Caches the list for 24 hours to minimize API overhead.
     *
     * @return array{primary: string, published: array<string>}
     */
    private function getShopLocales(Shop $shop): array
    {
        $cacheKey = 'shopify_shop_locales:' . $shop->id;
        return Cache::remember($cacheKey, now()->addDay(), function () use ($shop) {
            $query = <<<'GQL'
query {
  shopLocales {
    locale
    primary
    published
  }
}
GQL;
            try {
                $res = $this->client->query($shop, $query);
                $primary = 'en';
                $published = [];
                $data = data_get($res, 'data.shopLocales', []);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (is_array($item)) {
                            $locale = strtolower(trim((string)$item['locale']));
                            if ($item['primary'] ?? false) {
                                $primary = $locale;
                            }
                            if ($item['published'] ?? false) {
                                $published[] = $locale;
                            }
                        }
                    }
                }
                return [
                    'primary' => $primary,
                    'published' => $published,
                ];
            } catch (\Throwable $e) {
                Log::warning('ShopifyTranslationSyncService: Failed to query shopLocales', [
                    'shop' => $shop->shop_domain,
                    'error' => $e->getMessage()
                ]);
                return [
                    'primary' => 'en',
                    'published' => [],
                ];
            }
        });
    }

    /**
     * Match the target locale to one of the shop's published locales.
     * e.g., 'de-DE' -> 'de'
     *
     * @param  array<string>  $shopLocales
     */
    private function matchLocale(string $target, array $shopLocales): ?string
    {
        $targetLower = strtolower(trim($target));
        if ($targetLower === '') {
            return null;
        }

        // 1. Exact match
        if (in_array($targetLower, $shopLocales, true)) {
            return $target;
        }

        // 2. 2-letter prefix match (e.g. 'de-DE' -> 'de')
        $parts = explode('-', $targetLower);
        $prefix = $parts[0];
        if (in_array($prefix, $shopLocales, true)) {
            // Find the original case/casing of the matched shop locale
            foreach ($shopLocales as $sl) {
                if (strtolower($sl) === $prefix) {
                    return $sl;
                }
            }
        }

        // 3. Fallback: if no match, just return the target as-is and let Shopify API handle/reject it.
        return $target;
    }
}
