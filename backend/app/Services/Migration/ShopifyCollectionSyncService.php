<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopifyCollectionSyncService
{
    private ShopifyAdminGraphqlClient $client;

    private ShopifyPublicationService $publication;

    private array $runCache = [];

    public function __construct(ShopifyAdminGraphqlClient $client, ShopifyPublicationService $publication)
    {
        $this->client = $client;
        $this->publication = $publication;
    }

    /**
     * @param  array<string, mixed>  $category  Shopware category entity (from product.categories)
     * @param  array<int, array{id: string, locale: string, name: string}>  $enabledLanguages  (optional)
     * @return array{collectionGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function upsertCollectionForCategoryAndAddProduct(Shop $shop, string $shopwareCategoryId, array $category, string $productGid, array $enabledLanguages = []): array
    {
        $input = $this->buildCollectionInput($category);
        $cacheKey = "{$shop->id}:{$shopwareCategoryId}";
        $collectionGid = $this->runCache[$cacheKey] ?? null;

        if (! $collectionGid) {
            $ensure = $this->ensureCollectionExists($shop, $shopwareCategoryId, $input, $productGid);
            if (! empty($ensure['errors']) || ! empty($ensure['userErrors'])) {
                return $ensure;
            }
            $collectionGid = $ensure['collectionGid'];
            $this->runCache[$cacheKey] = $collectionGid;
        }

        $result = $this->addProductToCollection($shop, $collectionGid, $productGid);
        $result['collectionGid'] = $collectionGid;

        // --- Non-blocking collection translation sync ---
        if ($collectionGid && count($enabledLanguages) > 0) {
            try {
                $translationsByLocale = ShopifyTranslationSyncService::extractTranslationsFromEntity($category, $enabledLanguages);
                if (count($translationsByLocale) > 0) {
                    $translationSync = app(ShopifyTranslationSyncService::class);
                    $translationSync->syncCollectionTranslations($shop, $collectionGid, $translationsByLocale);
                }
            } catch (\Throwable $e) {
                Log::warning('Collection translation sync failed (collection still migrated)', [
                    'shop'                => $shop->shop_domain,
                    'shopware_cat_id'     => $shopwareCategoryId,
                    'collection_gid'      => $collectionGid,
                    'error'               => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function buildCollectionInput(array $category): array
    {
        $title = $this->normalizeText(data_get($category, 'translated.name'))
            ?: $this->normalizeText(data_get($category, 'name'))
            ?: 'Category';

        $descriptionHtml = $this->normalizeText(data_get($category, 'translated.description'))
            ?: $this->normalizeText(data_get($category, 'description'));

        $seoTitle = $this->normalizeText(data_get($category, 'translated.metaTitle'))
            ?: $this->normalizeText(data_get($category, 'metaTitle'));
        $seoDescription = $this->normalizeText(data_get($category, 'translated.metaDescription'))
            ?: $this->normalizeText(data_get($category, 'metaDescription'));

        // Derive a deterministic handle from the Shopware SEO URL or the title.
        // Setting an explicit handle prevents Shopify from auto-creating a duplicate
        // collection (e.g. "men-2") when a "men" collection already exists.
        $handle = $this->deriveCollectionHandle($category, $title);

        $input = [
            'title' => $title,
            'handle' => $handle,
        ];

        if ($descriptionHtml !== '') {
            $input['descriptionHtml'] = $descriptionHtml;
        }

        if ($seoTitle !== '' || $seoDescription !== '') {
            $seo = [];
            if ($seoTitle !== '') {
                $seo['title'] = $seoTitle;
            }
            if ($seoDescription !== '') {
                $seo['description'] = $seoDescription;
            }
            $input['seo'] = $seo;
        }

        $imageUrl = $this->categoryImageUrl($category);
        if ($imageUrl !== null) {
            $input['image'] = ['src' => $imageUrl];
        }

        return $input;
    }

    /**
     * Derive a URL-safe Shopify collection handle from the category SEO URL or title.
     * This is deterministic so re-runs produce the same handle and hit the same collection.
     */
    private function deriveCollectionHandle(array $category, string $title): string
    {
        // Prefer the Shopware SEO URL path as handle (most stable across runs)
        $seoUrls = data_get($category, 'seoUrls', []);
        if (is_array($seoUrls)) {
            foreach ($seoUrls as $seo) {
                $path = trim((string) data_get($seo, 'seoPathInfo', ''));
                if ($path !== '') {
                    // Strip trailing/leading slashes and take last segment
                    $path = trim($path, '/');
                    $segments = explode('/', $path);
                    $last = end($segments);
                    if (is_string($last) && $last !== '') {
                        return $this->slugify($last);
                    }
                }
            }
        }

        // Fall back to slugifying the title
        return $this->slugify($title);
    }

    /**
     * Convert a string to a Shopify-compatible URL handle (lowercase, hyphens, no special chars).
     */
    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        // Replace non-alphanumeric characters with hyphens
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-');
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function categoryImageUrl(array $category): ?string
    {
        $media = data_get($category, 'media');
        if (! is_array($media)) {
            return null;
        }

        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) (data_get($item, 'media.url') ?: data_get($item, 'url') ?: ''));
            if ($url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
                return $url;
            }
        }

        return null;
    }

    private function normalizeText(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{collectionGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function ensureCollectionExists(Shop $shop, string $categoryId, array $input, string $productGid): array
    {
        $title = (string) ($input['title'] ?? 'Category');
        $contentHash = md5(json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $cacheKey = 'shopify:collection_gid:'.$shop->id.':'.$categoryId;
        $cachedGid = Cache::get($cacheKey);
        $existingGid = is_string($cachedGid) ? $cachedGid : '';

        $gidIsFromDb = false;
        $mapping = null;
        if ($existingGid === '') {
            $mapping = ShopifyIdMapping::query()->where([
                'shop_id' => $shop->id,
                'entity_type' => 'collection',
                'source_id' => $categoryId,
            ])->first();

            $existingGid = $mapping ? (string) $mapping->shopify_gid : '';
            if ($existingGid !== '') {
                Cache::put($cacheKey, $existingGid, now()->addDays(7));
                $gidIsFromDb = true;
            }
        }

        if ($existingGid !== '') {
            if (! $gidIsFromDb && ! $this->collectionExistsCached($shop, $existingGid)) {
                $mapping?->delete();
                $existingGid = '';
                Cache::forget($cacheKey);
            }
        }

        if ($existingGid !== '') {
            $updateKey = 'shopify:collection_updated:'.$shop->id.':'.$existingGid.':'.$contentHash;
            $recentlyUpdated = (bool) Cache::get($updateKey, 0);

            $update = ['ok' => true];
            if (! $recentlyUpdated) {
                $update = $this->updateCollection($shop, $existingGid, $input);
                if (empty($update['errors']) && empty($update['userErrors'])) {
                    Cache::put($updateKey, 1, now()->addHours(6));
                }
            }
            if (! empty($update['errors']) || ! empty($update['userErrors'])) {
                Log::warning('Collection update failed', [
                    'shop' => $shop->shop_domain,
                    'category_id' => $categoryId,
                    'collection_gid' => $existingGid,
                    'title' => $title,
                    'result' => $update,
                ]);
                if ($this->looksLikeDeletedCollection($update)) {
                    $mapping?->delete();
                    $existingGid = '';
                    Cache::forget($cacheKey);
                    Cache::forget('shopify:collection_exists:'.$shop->id.':'.$existingGid);
                    Cache::forget($updateKey);
                } else {
                    return $update;
                }
            }

            if ($existingGid !== '') {
                $this->tryPublishCollection($shop, $existingGid);
                $add = $this->addProductToCollection($shop, $existingGid, $productGid);
                if (! empty($add['errors']) || ! empty($add['userErrors'])) {
                    Log::warning('Add product to collection failed', [
                        'shop' => $shop->shop_domain,
                        'category_id' => $categoryId,
                        'collection_gid' => $existingGid,
                        'product_gid' => $productGid,
                        'result' => $add,
                    ]);
                    if ($this->looksLikeDeletedCollection($add)) {
                        $mapping?->delete();
                        $existingGid = '';
                        Cache::forget($cacheKey);
                        Cache::forget($updateKey);
                    } else {
                        return $add + ['collectionGid' => $existingGid];
                    }
                }

                if ($existingGid !== '') {
                    return ['collectionGid' => $existingGid];
                }
            }
        }

        // No existing mapping — check Shopify by handle before creating to prevent duplicates
        if (isset($input['handle']) && $input['handle'] !== '') {
            $existingByHandle = $this->findCollectionByHandle($shop, $input['handle']);
            if ($existingByHandle !== null) {
                ShopifyIdMapping::query()->updateOrCreate([
                    'shop_id' => $shop->id,
                    'entity_type' => 'collection',
                    'source_id' => $categoryId,
                ], ['shopify_gid' => $existingByHandle]);

                Cache::put($cacheKey, $existingByHandle, now()->addDays(7));

                // Update the collection metadata and add the product
                $this->updateCollection($shop, $existingByHandle, $input);
                $this->tryPublishCollection($shop, $existingByHandle);
                $this->addProductToCollection($shop, $existingByHandle, $productGid);
                return ['collectionGid' => $existingByHandle];
            }
        }

        $create = $this->createCollection($shop, $input);
        if (! empty($create['errors']) || ! empty($create['userErrors'])) {
            // Check if the error is a handle collision — collection with that handle already exists
            $userErrors = $create['userErrors'] ?? [];
            $handleTaken = false;
            foreach (is_array($userErrors) ? $userErrors : [] as $ue) {
                $msg = strtolower((string) data_get($ue, 'message', ''));
                if (str_contains($msg, 'handle') && (str_contains($msg, 'taken') || str_contains($msg, 'already') || str_contains($msg, 'exists'))) {
                    $handleTaken = true;
                    break;
                }
            }

            if ($handleTaken && isset($input['handle']) && $input['handle'] !== '') {
                // Look up by handle to recover the existing collection GID
                $existing = $this->findCollectionByHandle($shop, $input['handle']);
                if ($existing !== null) {
                    ShopifyIdMapping::query()->updateOrCreate([
                        'shop_id' => $shop->id,
                        'entity_type' => 'collection',
                        'source_id' => $categoryId,
                    ], ['shopify_gid' => $existing]);

                    Cache::put($cacheKey, $existing, now()->addDays(7));
                    $this->tryPublishCollection($shop, $existing);
                    $this->addProductToCollection($shop, $existing, $productGid);
                    return ['collectionGid' => $existing];
                }
            }

            Log::warning('Collection create failed', [
                'shop' => $shop->shop_domain,
                'category_id' => $categoryId,
                'title' => $title,
                'result' => $create,
            ]);

            return $create;
        }

        $gid = (string) ($create['collectionGid'] ?? '');
        if ($gid !== '') {
            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id' => $shop->id,
                'entity_type' => 'collection',
                'source_id' => $categoryId,
            ], [
                'shopify_gid' => $gid,
            ]);

            Cache::put($cacheKey, $gid, now()->addDays(7));
            Cache::put('shopify:collection_updated:'.$shop->id.':'.$gid.':'.$contentHash, 1, now()->addHours(6));

            $this->tryPublishCollection($shop, $gid);

            $add = $this->addProductToCollection($shop, $gid, $productGid);
            if (! empty($add['errors']) || ! empty($add['userErrors'])) {
                Log::warning('Add product to newly created collection failed', [
                    'shop' => $shop->shop_domain,
                    'category_id' => $categoryId,
                    'collection_gid' => $gid,
                    'product_gid' => $productGid,
                    'result' => $add,
                ]);

                return $add + ['collectionGid' => $gid];
            }

            return ['collectionGid' => $gid];
        }

        return ['userErrors' => [['message' => 'collectionCreate did not return a collection id']]];
    }

    /**
     * Look up a Shopify collection by its URL handle.
     * Returns the collection GID if found, null otherwise.
     * Cached for 6 hours to reduce API calls.
     */
    private function findCollectionByHandle(Shop $shop, string $handle): ?string
    {
        $cacheKey = 'shopify:collection_by_handle:'.$shop->id.':'.md5($handle);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === '' ? null : (string) $cached;
        }

        $query = <<<'GQL'
query FindCollectionByHandle($handle: String!) {
  collectionByHandle(handle: $handle) {
    id
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['handle' => $handle]);
        if (isset($res['errors'])) {
            return null;
        }

        $id = (string) data_get($res, 'data.collectionByHandle.id', '');
        Cache::put($cacheKey, $id !== '' ? $id : '', now()->addHours(6));

        return $id !== '' ? $id : null;
    }

    private function collectionExists(Shop $shop, string $collectionGid): bool
    {
        $query = <<<'GQL'
query CollectionExists($id: ID!) {
  node(id: $id) {
    __typename
    ... on Collection { id }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['id' => $collectionGid]);
        if (isset($res['errors'])) {
            return true;
        }

        $type = (string) data_get($res, 'data.node.__typename', '');
        $id = (string) data_get($res, 'data.node.id', '');

        return $type === 'Collection' && $id !== '';
    }

    private function collectionExistsCached(Shop $shop, string $collectionGid): bool
    {
        $k = 'shopify:collection_exists:'.$shop->id.':'.$collectionGid;
        $cached = Cache::get($k);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $exists = $this->collectionExists($shop, $collectionGid);
        Cache::put($k, $exists ? 1 : 0, now()->addHours(6));

        return $exists;
    }

    /**
     * @param  array{userErrors?: array<int, mixed>, errors?: mixed}  $result
     */
    private function looksLikeDeletedCollection(array $result): bool
    {
        $msgs = [];

        $errors = $result['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $e) {
                $m = data_get($e, 'message');
                if (is_string($m) && $m !== '') {
                    $msgs[] = $m;
                }
            }
        }

        $userErrors = $result['userErrors'] ?? null;
        if (is_array($userErrors)) {
            foreach ($userErrors as $e) {
                $m = data_get($e, 'message');
                if (is_string($m) && $m !== '') {
                    $msgs[] = $m;
                }
            }
        }

        $joined = strtolower(implode(' | ', $msgs));
        if ($joined === '') {
            return false;
        }

        return str_contains($joined, 'not found')
            || str_contains($joined, 'does not exist')
            || str_contains($joined, 'invalid id')
            || str_contains($joined, 'could not find');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{collectionGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createCollection(Shop $shop, array $input): array
    {
        $mutation = <<<'GQL'
mutation CollectionCreate($input: CollectionInput!) {
  collectionCreate(input: $input) {
    userErrors { field message }
    collection { id }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'input' => $input,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.collectionCreate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];

        $id = data_get($res, 'data.collectionCreate.collection.id');
        if (is_string($id) && $id !== '') {
            return ['collectionGid' => $id, 'userErrors' => $userErrors];
        }

        return ['userErrors' => $userErrors];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{collectionGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function updateCollection(Shop $shop, string $collectionGid, array $input): array
    {
        $mutation = <<<'GQL'
mutation CollectionUpdate($input: CollectionInput!) {
  collectionUpdate(input: $input) {
    userErrors { field message }
    collection { id }
  }
}
GQL;

        $input['id'] = $collectionGid;

        $res = $this->client->query($shop, $mutation, [
            'input' => $input,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.collectionUpdate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];

        $id = data_get($res, 'data.collectionUpdate.collection.id');
        if (is_string($id) && $id !== '') {
            return ['collectionGid' => $id, 'userErrors' => $userErrors];
        }

        return ['userErrors' => $userErrors];
    }

    /**
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function addProductToCollection(Shop $shop, string $collectionGid, string $productGid): array
    {
        if ($this->isAutomatedCollectionCached($shop, $collectionGid)) {
            return ['ok' => true, 'skipped' => true];
        }

        $mutation = <<<'GQL'
mutation CollectionAddProducts($id: ID!, $productIds: [ID!]!) {
  collectionAddProducts(id: $id, productIds: $productIds) {
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'id' => $collectionGid,
            'productIds' => [$productGid],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.collectionAddProducts.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    private function isAutomatedCollection(Shop $shop, string $collectionGid): bool
    {
        $query = <<<'GQL'
query CollectionRuleSet($id: ID!) {
  node(id: $id) {
    __typename
    ... on Collection {
      id
      ruleSet {
        appliedDisjunctively
        rules {
          column
          relation
          condition
        }
      }
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['id' => $collectionGid]);
        if (isset($res['errors'])) {
            return false;
        }

        $type = (string) data_get($res, 'data.node.__typename', '');
        if ($type !== 'Collection') {
            return false;
        }

        $ruleSet = data_get($res, 'data.node.ruleSet');
        if (! is_array($ruleSet)) {
            return false;
        }

        $rules = data_get($ruleSet, 'rules', []);
        $rules = is_array($rules) ? $rules : [];

        return count($rules) > 0;
    }

    private function tryPublishCollection(Shop $shop, string $collectionGid): void
    {
        $cacheKey = 'shopify:collection_published:'.$shop->id.':'.$collectionGid;
        if (Cache::get($cacheKey)) {
            return;
        }

        $res = $this->publication->publishToOnlineStore($shop, $collectionGid);
        if (! empty($res['errors']) || ! empty($res['userErrors'])) {
            Log::warning('Collection publish to Online Store failed', [
                'shop' => $shop->shop_domain,
                'collection_gid' => $collectionGid,
                'result' => $res,
            ]);

            return;
        }

        Cache::put($cacheKey, 1, now()->addHours(6));
    }

    private function isAutomatedCollectionCached(Shop $shop, string $collectionGid): bool
    {
        $k = 'shopify:collection_is_automated:'.$shop->id.':'.$collectionGid;
        $cached = Cache::get($k);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $isAutomated = $this->isAutomatedCollection($shop, $collectionGid);
        Cache::put($k, $isAutomated ? 1 : 0, now()->addHours(6));

        return $isAutomated;
    }
}
