<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use GuzzleHttp\Client as GuzzleClient;

class ShopifyMediaSyncService
{
    private ShopifyAdminGraphqlClient $client;

    private GuzzleClient $http;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
        $this->http = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 5,
            'http_errors' => false,
            'verify' => false, // often needed for staging/dev sites
        ]);
    }

    /**
     * @return array{mediaId?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createSingleMediaFromUrl(Shop $shop, string $productGid, string $url): array
    {
        $mutation = <<<'GQL'
mutation CreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
  productCreateMedia(productId: $productId, media: $media) {
    media {
      __typename
      ... on MediaImage {
        id
      }
    }
    mediaUserErrors {
      field
      message
    }
  }
}
GQL;
        $staged = $this->stageUploadFromRemoteUrl($shop, $url);
        if (isset($staged['errors'])) {
            return ['userErrors' => [[
                'message' => 'Failed to stage upload for media URL',
                'url' => $url,
                'errors' => $staged['errors'],
            ]]];
        }

        $userErrors = $staged['userErrors'] ?? [];
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => [[
                'message' => 'Failed to stage upload for media URL',
                'url' => $url,
                'userErrors' => $userErrors,
            ]]];
        }

        $resourceUrl = (string) ($staged['resourceUrl'] ?? '');
        if ($resourceUrl === '') {
            return ['userErrors' => [[
                'message' => 'Staged upload did not return resourceUrl',
                'url' => $url,
            ]]];
        }

        $res = $this->client->query($shop, $mutation, [
            'productId' => $productGid,
            'media' => [[
                'mediaContentType' => 'IMAGE',
                'originalSource' => $resourceUrl,
            ]],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $ue2 = data_get($res, 'data.productCreateMedia.mediaUserErrors', []);
        $ue2 = is_array($ue2) ? $ue2 : [];

        $node2 = data_get($res, 'data.productCreateMedia.media.0', []);
        $id2 = is_array($node2) ? (string) data_get($node2, 'id', '') : '';

        if ($id2 !== '') {
            return ['mediaId' => $id2, 'userErrors' => $ue2];
        }

        return ['userErrors' => $ue2 ?: [[
            'message' => 'productCreateMedia did not return media id',
            'url' => $url,
        ]]];
    }

    /**
     * @return array{created: int, appended: int, errors?: mixed, userErrors?: array<int, mixed>}
     */
    public function syncProductAndVariantImages(Shop $shop, string $productGid, string $shopwareBaseUrl, array $parent, array $children, ?array $variantIdByShopwareId = null): array
    {
        \Illuminate\Support\Facades\Log::info('Starting media sync', [
            'shop' => $shop->shop_domain,
            'product_gid' => $productGid,
            'sw_id' => $parent['id'] ?? 'unknown'
        ]);

        if (count($children) > 0 && ($variantIdByShopwareId === null || count($variantIdByShopwareId) === 0)) {
            $variantMap = $this->fetchShopifyVariantMap($shop, $productGid);
            if (isset($variantMap['errors'])) {
                return ['created' => 0, 'appended' => 0, 'errors' => $variantMap['errors']];
            }

            /** @var array<string, string> $variantIdByShopwareId */
            $variantIdByShopwareId = $variantMap['variantIdByShopwareId'] ?? [];
        }

        $shopwareBaseUrl = rtrim($shopwareBaseUrl, '/');
        $productUrls = $this->collectProductImageUrls($shopwareBaseUrl, $parent);

        // Collect variant images: map of variantId => [imageUrls...]
        // Pass parent for configuratorSettings option-image mapping
        $variantImagesByShopwareId = $this->collectVariantImageUrls($shopwareBaseUrl, $children, $parent);

        // Collect ALL variant image URLs (not just first per variant)
        $allUrls = [];
        foreach ($productUrls as $u) {
            $allUrls[$u] = true;
        }
        // Add ALL variant images, not just one per variant
        foreach ($variantImagesByShopwareId as $swVariantId => $imageUrls) {
            foreach ($imageUrls as $u) {
                $allUrls[$u] = true;
            }
        }

        \Illuminate\Support\Facades\Log::info('Collected URLs', ['urls' => array_keys($allUrls)]);

        $urlToMediaId = [];
        $created = 0;
        $allUserErrors = [];

        $urls = array_values(array_keys($allUrls));
        foreach (array_chunk($urls, 10) as $batch) {
            $create = $this->createMediaBatchFromUrls($shop, $productGid, $batch);
            if (isset($create['errors'])) {
                return ['created' => $created, 'appended' => 0, 'errors' => $create['errors']];
            }

            $userErrors = $create['userErrors'] ?? [];
            if (is_array($userErrors) && count($userErrors) > 0) {
                foreach ($userErrors as $ue) {
                    $allUserErrors[] = $ue;
                }
            }

            $createdBatch = $create['urlToMediaId'] ?? [];
            if (is_array($createdBatch)) {
                foreach ($createdBatch as $u => $mediaId) {
                    if (is_string($u) && is_string($mediaId) && $u !== '' && $mediaId !== '') {
                        $urlToMediaId[$u] = $mediaId;
                        $created++;
                    }
                }
            }
        }

        if ($created === 0 && count($urls) > 0) {
            \Illuminate\Support\Facades\Log::warning('Media sync created 0 images', [
                'shop' => $shop->shop_domain,
                'product_gid' => $productGid,
                'url_count' => count($urls),
            ]);
        }

        // Associate ALL variant images with their variants.
        // Shopify rules for productVariantAppendMedia:
        //  - Each variant may appear only ONCE in the variantMedia array
        //  - Each entry takes mediaIds: [ID!]! (array, but only one item per variant in practice)
        $appendPairs = [];
        $mediaIdsByVariant = [];

        foreach ($variantImagesByShopwareId as $swVariantId => $imageUrls) {
            $shopifyVariantId = $variantIdByShopwareId[$swVariantId] ?? null;
            if (!$shopifyVariantId) {
                continue;
            }
            foreach ($imageUrls as $url) {
                $mediaId = $urlToMediaId[$url] ?? null;
                if ($mediaId) {
                    $mediaIdsByVariant[$shopifyVariantId][] = $mediaId;
                }
            }
        }

        // One entry per variant with its first (primary) mediaId
        foreach ($mediaIdsByVariant as $shopifyVariantId => $mediaIds) {
            $uniqueIds = array_values(array_unique($mediaIds));
            $appendPairs[] = ['variantId' => $shopifyVariantId, 'mediaIds' => [$uniqueIds[0]]];
        }

        $appended = 0;
        if (count($appendPairs) > 0) {
            // Wait for all variant media to be READY before associating.
            // Shopify processes images asynchronously; attaching non-ready media fails.
            $variantMediaIds = array_map(fn($p) => $p['mediaIds'][0], $appendPairs);
            $this->waitForMediaReady($shop, $productGid, $variantMediaIds);

            $append = $this->appendVariantMedia($shop, $productGid, $appendPairs);

            if (isset($append['errors'])) {
                return ['created' => $created, 'appended' => $appended, 'errors' => $append['errors'], 'userErrors' => $allUserErrors];
            }

            $userErrors = $append['userErrors'] ?? [];
            if (is_array($userErrors) && count($userErrors) > 0) {
                foreach ($userErrors as $ue) {
                    // Retry once more if still non-ready (race condition on slow stores)
                    if (str_contains((string) ($ue['message'] ?? ''), 'Non-ready media')) {
                        sleep(3);
                        $retry = $this->appendVariantMedia($shop, $productGid, $appendPairs);
                        if (!isset($retry['errors'])) {
                            $retryErrors = $retry['userErrors'] ?? [];
                            foreach (is_array($retryErrors) ? $retryErrors : [] as $rue) {
                                $allUserErrors[] = $rue;
                            }
                            $appended = count($appendPairs);
                            break;
                        }
                    }
                    $allUserErrors[] = $ue;
                }
            }

            $appended = count($appendPairs);
        }

        if (count($allUserErrors) > 0) {
            return ['created' => $created, 'appended' => $appended, 'userErrors' => $allUserErrors];
        }

        return ['created' => $created, 'appended' => $appended];
    }

    /**
     * @return array{variantIdByShopwareId: array<string, string>, errors?: mixed}
     */
    private function fetchShopifyVariantMap(Shop $shop, string $productGid): array
    {
        $query = <<<'GQL'
query VariantMap($id: ID!) {
  product(id: $id) {
    variants(first: 100) {
      nodes {
        id
        metafield(namespace: "magento", key: "variant_id") {
          value
        }
      }
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['id' => $productGid]);
        if (isset($res['errors'])) {
            return ['variantIdByShopwareId' => [], 'errors' => $res['errors']];
        }

        $nodes = data_get($res, 'data.product.variants.nodes', []);
        $nodes = is_array($nodes) ? $nodes : [];

        $map = [];
        foreach ($nodes as $n) {
            $variantId = (string) data_get($n, 'id', '');
            $swId = (string) data_get($n, 'metafield.value', '');
            if ($variantId !== '' && $swId !== '') {
                $map[$swId] = $variantId;
            }
        }

        return ['variantIdByShopwareId' => $map];
    }

    private function createMediaBatchFromUrls(Shop $shop, string $productGid, array $urls): array
    {
        $out = [];
        $allUserErrors = [];
        $downloads = [];

        // Normalize all URLs first
        $normalizedUrls = [];
        foreach ($urls as $u) {
            if (! is_string($u) || $u === '') {
                continue;
            }
            $normalizedUrls[$u] = $this->normalizeRemoteUrl($u);
        }

        if (count($normalizedUrls) === 0) {
            return ['urlToMediaId' => $out, 'userErrors' => $allUserErrors];
        }

        // Run HEAD checks in parallel using Guzzle promises to skip dead URLs instantly.
        $headPromises = [];
        foreach ($normalizedUrls as $origUrl => $normUrl) {
            $headPromises[$origUrl] = $this->http->headAsync($normUrl, ['timeout' => 5, 'connect_timeout' => 3]);
        }

        $headResults = \GuzzleHttp\Promise\Utils::settle($headPromises)->wait();

        // Filter to only URLs that passed HEAD check
        $validUrls = [];
        $failedUrlsToRetry = [];
        foreach ($normalizedUrls as $origUrl => $normUrl) {
            $result = $headResults[$origUrl] ?? null;
            $failed = ($result === null || $result['state'] !== 'fulfilled');
            if (!$failed) {
                $headStatus = $result['value']->getStatusCode();
                if ($headStatus < 200 || $headStatus >= 300) {
                    $failed = true;
                }
            }

            if ($failed) {
                if (str_contains($normUrl, '/pub/media/')) {
                    $failedUrlsToRetry[$origUrl] = str_replace('/pub/media/', '/media/', $normUrl);
                    continue;
                }

                $reason = $result ? ($result['reason'] ?? null) : null;
                $errMsg = $reason instanceof \Throwable ? $reason->getMessage() : 'HEAD request failed';
                \Illuminate\Support\Facades\Log::warning('Download skipped (HEAD failed)', ['url' => $origUrl, 'error' => $errMsg]);
                $allUserErrors[] = ['message' => 'Source image URL unreachable', 'url' => $origUrl];
                continue;
            }

            $validUrls[$origUrl] = $normUrl;
        }

        // Retry failed URLs with /media/ instead of /pub/media/ in parallel
        if (count($failedUrlsToRetry) > 0) {
            $retryPromises = [];
            foreach ($failedUrlsToRetry as $origUrl => $retryUrl) {
                $retryPromises[$origUrl] = $this->http->headAsync($retryUrl, ['timeout' => 5, 'connect_timeout' => 3]);
            }
            $retryResults = \GuzzleHttp\Promise\Utils::settle($retryPromises)->wait();

            foreach ($failedUrlsToRetry as $origUrl => $retryUrl) {
                $result = $retryResults[$origUrl] ?? null;
                $failed = ($result === null || $result['state'] !== 'fulfilled');
                if (!$failed) {
                    $headStatus = $result['value']->getStatusCode();
                    if ($headStatus < 200 || $headStatus >= 300) {
                        $failed = true;
                    }
                }

                if ($failed) {
                    $reason = $result ? ($result['reason'] ?? null) : null;
                    $errMsg = $reason instanceof \Throwable ? $reason->getMessage() : 'HEAD request failed';
                    \Illuminate\Support\Facades\Log::warning('Download skipped (HEAD fallback failed)', ['url' => $origUrl, 'error' => $errMsg]);
                    $allUserErrors[] = ['message' => 'Source image URL unreachable', 'url' => $origUrl];
                    continue;
                }

                $validUrls[$origUrl] = $retryUrl;
            }
        }

        if (count($validUrls) === 0) {
            return ['urlToMediaId' => $out, 'userErrors' => $allUserErrors];
        }

        // Download valid files in parallel using Guzzle promises.
        $tmpFiles = [];
        foreach ($validUrls as $origUrl => $normUrl) {
            $tmp = tempnam(sys_get_temp_dir(), 'swimg_');
            if ($tmp === false) {
                $allUserErrors[] = ['message' => 'Unable to create temp file', 'url' => $origUrl];
                continue;
            }
            $tmpFiles[$origUrl] = ['tmp' => $tmp, 'norm' => $normUrl];
        }

        $downloadPromises = [];
        foreach ($tmpFiles as $origUrl => $info) {
            $downloadPromises[$origUrl] = $this->http->getAsync($info['norm'], [
                'sink' => $info['tmp'],
                'timeout' => 30,
                'connect_timeout' => 5,
            ]);
        }

        $downloadResults = \GuzzleHttp\Promise\Utils::settle($downloadPromises)->wait();

        foreach ($tmpFiles as $origUrl => $info) {
            $tmp = $info['tmp'];
            $result = $downloadResults[$origUrl] ?? null;

            if ($result === null || $result['state'] !== 'fulfilled') {
                @unlink($tmp);
                $reason = $result['reason'] ?? null;
                $errMsg = $reason instanceof \Throwable ? $reason->getMessage() : 'Download failed';
                \Illuminate\Support\Facades\Log::error('Download exception', ['url' => $origUrl, 'error' => $errMsg]);
                $allUserErrors[] = ['message' => $errMsg, 'url' => $origUrl];
                continue;
            }

            $status = $result['value']->getStatusCode();
            if ($status < 200 || $status >= 300) {
                @unlink($tmp);
                \Illuminate\Support\Facades\Log::warning('Download failed', ['url' => $origUrl, 'status' => $status]);
                $allUserErrors[] = ['message' => 'Source image URL returned HTTP '.$status, 'url' => $origUrl];
                continue;
            }

            $size = @filesize($tmp);
            $size = is_int($size) ? $size : 0;
            if ($size <= 0) {
                @unlink($tmp);
                $allUserErrors[] = ['message' => 'Downloaded image was empty', 'url' => $origUrl];
                continue;
            }

            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = (string) finfo_file($finfo, $tmp);
                    finfo_close($finfo);
                }
            }
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }

            $ext = $this->extensionFromMime($mime);
            if ($ext !== null) {
                $renamed = $tmp.$ext;
                if (@rename($tmp, $renamed)) {
                    $tmp = $renamed;
                }
            }

            $downloads[] = [
                'url' => $origUrl,
                'path' => $tmp,
                'size' => $size,
                'mime' => $mime,
            ];
        }

        if (count($downloads) === 0) {
            return ['urlToMediaId' => $out, 'userErrors' => $allUserErrors];
        }

        $created = $this->createMediaBatchFromDownloadedFiles($shop, $productGid, $downloads);
        $this->cleanupDownloadedFiles($downloads);

        if (isset($created['errors'])) {
            return ['errors' => $created['errors']];
        }

        $createdMap = $created['urlToMediaId'] ?? [];
        if (is_array($createdMap)) {
            foreach ($createdMap as $u => $mediaId) {
                if (is_string($u) && is_string($mediaId) && $u !== '' && $mediaId !== '') {
                    $out[$u] = $mediaId;
                }
            }
        }

        $userErrors = $created['userErrors'] ?? [];
        if (is_array($userErrors) && count($userErrors) > 0) {
            foreach ($userErrors as $ue) {
                $allUserErrors[] = $ue;
            }
        }

        return ['urlToMediaId' => $out, 'userErrors' => $allUserErrors];
    }

    /**
     * @param  array<int, array{url: string, path: string, size: int, mime: string}>  $downloads
     * @return array{urlToMediaId?: array<string, string>, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createMediaBatchFromDownloadedFiles(Shop $shop, string $productGid, array $downloads): array
    {
        $staged = $this->createStagedUploadTargets($shop, $downloads);
        if (isset($staged['errors'])) {
            return ['errors' => $staged['errors']];
        }

        $userErrors = $staged['userErrors'] ?? [];
        if (is_array($userErrors) && count($userErrors) > 0) {
            \Illuminate\Support\Facades\Log::warning('Shopify stagedUploadsCreate userErrors', [
                'shop' => $shop->shop_domain,
                'product_gid' => $productGid,
                'userErrors' => $userErrors,
            ]);
            return ['userErrors' => $userErrors];
        }

        $targets = $staged['targets'] ?? [];
        if (! is_array($targets) || count($targets) !== count($downloads)) {
            return ['userErrors' => [[
                'message' => 'Invalid staged upload target count returned by Shopify',
            ]]];
        }

        $media = [];
        $allUserErrors = [];
        foreach ($downloads as $idx => $download) {
            $target = $targets[$idx] ?? [];
            $upload = $this->uploadDownloadedFileToStagedTarget($download, is_array($target) ? $target : []);
            if (! empty($upload['userErrors'])) {
                foreach ($upload['userErrors'] as $ue) {
                    $allUserErrors[] = $ue + ['url' => $download['url']];
                }

                continue;
            }

            $resourceUrl = (string) data_get($target, 'resourceUrl', '');
            if ($resourceUrl === '') {
                $allUserErrors[] = [
                    'message' => 'Staged upload did not return resourceUrl',
                    'url' => $download['url'],
                ];

                continue;
            }

            $media[] = [
                'url' => $download['url'],
                'input' => [
                    'mediaContentType' => 'IMAGE',
                    'originalSource' => $resourceUrl,
                ],
            ];
        }

        if (count($media) === 0) {
            return ['urlToMediaId' => [], 'userErrors' => $allUserErrors];
        }

        $created = $this->createProductMediaFromStagedResources($shop, $productGid, $media);
        if (isset($created['errors'])) {
            return ['errors' => $created['errors']];
        }

        $userErrors = $created['userErrors'] ?? [];
        if (is_array($userErrors) && count($userErrors) > 0) {
            \Illuminate\Support\Facades\Log::warning('Shopify productCreateMedia mediaUserErrors', [
                'shop' => $shop->shop_domain,
                'product_gid' => $productGid,
                'userErrors' => $userErrors,
            ]);
            foreach ($userErrors as $ue) {
                $allUserErrors[] = $ue;
            }
        }

        $createdMap = $created['urlToMediaId'] ?? null;
        if (! is_array($createdMap) || count($createdMap) === 0) {
            \Illuminate\Support\Facades\Log::warning('Shopify productCreateMedia created no media', [
                'shop' => $shop->shop_domain,
                'product_gid' => $productGid,
                'attempted_count' => count($media),
            ]);
        }

        return [
            'urlToMediaId' => $created['urlToMediaId'] ?? [],
            'userErrors' => $allUserErrors,
        ];
    }

    /**
     * @param  array<int, array{url: string, path: string, size: int, mime: string}>  $downloads
     * @return array{targets?: array<int, mixed>, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createStagedUploadTargets(Shop $shop, array $downloads): array
    {
        $stagedCreate = <<<'GQL'
mutation Stage($input: [StagedUploadInput!]!) {
  stagedUploadsCreate(input: $input) {
    stagedTargets {
      url
      resourceUrl
      parameters { name value }
    }
    userErrors { field message }
  }
}
GQL;

        $inputs = [];
        foreach ($downloads as $download) {
            $inputs[] = [
                'resource' => 'IMAGE',
                'filename' => basename($download['path']),
                'mimeType' => $download['mime'],
                'httpMethod' => 'POST',
                'fileSize' => (string) $download['size'],
            ];
        }

        $res = $this->client->query($shop, $stagedCreate, ['input' => $inputs]);
        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.stagedUploadsCreate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        $targets = data_get($res, 'data.stagedUploadsCreate.stagedTargets', []);
        $targets = is_array($targets) ? array_values($targets) : [];

        return ['targets' => $targets];
    }

    /**
     * @param  array{url: string, path: string, size: int, mime: string}  $download
     * @return array{ok?: bool, userErrors?: array<int, mixed>}
     */
    private function uploadDownloadedFileToStagedTarget(array $download, array $target): array
    {
        $uploadUrl = (string) data_get($target, 'url', '');
        $params = data_get($target, 'parameters', []);
        $params = is_array($params) ? $params : [];

        if ($uploadUrl === '' || count($params) === 0) {
            return ['userErrors' => [[
                'message' => 'Invalid staged upload target returned by Shopify',
            ]]];
        }

        $multipart = [];
        foreach ($params as $p) {
            $name = (string) data_get($p, 'name', '');
            $value = (string) data_get($p, 'value', '');
            if ($name !== '') {
                $multipart[] = ['name' => $name, 'contents' => $value];
            }
        }

        $handle = fopen($download['path'], 'rb');
        if ($handle === false) {
            return ['userErrors' => [[
                'message' => 'Unable to open downloaded image for upload',
            ]]];
        }

        $multipart[] = [
            'name' => 'file',
            'contents' => $handle,
            'filename' => basename($download['path']),
            'headers' => [
                'Content-Type' => $download['mime'],
            ],
        ];

        try {
            $uploadRes = $this->http->post($uploadUrl, ['multipart' => $multipart]);
            $status = $uploadRes->getStatusCode();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($status < 200 || $status >= 300) {
            return ['userErrors' => [[
                'message' => 'Failed to upload to Shopify staged target (HTTP '.$status.')',
            ]]];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<int, array{url: string, input: array<string, string>}>  $media
     * @return array{urlToMediaId?: array<string, string>, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createProductMediaFromStagedResources(Shop $shop, string $productGid, array $media): array
    {
        $mutation = <<<'GQL'
mutation CreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
  productCreateMedia(productId: $productId, media: $media) {
    media {
      __typename
      ... on MediaImage {
        id
      }
    }
    mediaUserErrors {
      field
      message
    }
  }
}
GQL;

        $inputs = array_map(static fn (array $row) => $row['input'], $media);
        $res = $this->client->query($shop, $mutation, [
            'productId' => $productGid,
            'media' => $inputs,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.productCreateMedia.mediaUserErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        $failedIndexes = $this->failedMediaIndexesFromUserErrors($userErrors);
        $failed = array_fill_keys($failedIndexes, true);

        $created = data_get($res, 'data.productCreateMedia.media', []);
        $created = is_array($created) ? array_values($created) : [];

        $map = [];
        $createdOffset = 0;
        foreach (array_values($media) as $idx => $row) {
            if (isset($failed[$idx])) {
                continue;
            }

            $node = $created[$createdOffset] ?? null;
            $createdOffset++;
            $mediaId = is_array($node) ? (string) data_get($node, 'id', '') : '';
            $url = (string) ($row['url'] ?? '');
            if ($url !== '' && $mediaId !== '') {
                $map[$url] = $mediaId;
            }
        }

        if (count($map) === 0 && count($media) > 0) {
            \Illuminate\Support\Facades\Log::warning('Shopify productCreateMedia returned no mappable media ids', [
                'shop' => $shop->shop_domain,
                'product_gid' => $productGid,
                'attempted_count' => count($media),
                'returned_media' => array_map(static function ($n) {
                    return [
                        '__typename' => is_array($n) ? (string) ($n['__typename'] ?? '') : '',
                        'id' => is_array($n) ? (string) ($n['id'] ?? '') : '',
                    ];
                }, $created),
                'mediaUserErrors' => $userErrors,
            ]);
        }

        return ['urlToMediaId' => $map, 'userErrors' => $userErrors];
    }

    /**
     * @param  array<int, mixed>  $mediaUserErrors
     * @return array<int, int>
     */
    private function failedMediaIndexesFromUserErrors(array $mediaUserErrors): array
    {
        $idxs = [];
        foreach ($mediaUserErrors as $e) {
            $field = data_get($e, 'field');
            if (! is_array($field)) {
                continue;
            }
            foreach ($field as $part) {
                if (is_int($part)) {
                    $idxs[] = $part;
                    break;
                }
                if (is_string($part) && ctype_digit($part)) {
                    $idxs[] = (int) $part;
                    break;
                }
            }
        }

        $idxs = array_values(array_unique(array_filter($idxs, static fn ($v) => is_int($v) && $v >= 0)));
        sort($idxs);

        return $idxs;
    }

    /**
     * @param  array<int, array{path?: string}>  $downloads
     */
    private function cleanupDownloadedFiles(array $downloads): void
    {
        foreach ($downloads as $download) {
            $path = (string) ($download['path'] ?? '');
            if ($path !== '') {
                @unlink($path);
            }
        }
    }

    /**
     * @return array{resourceUrl?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function stageUploadFromRemoteUrl(Shop $shop, string $remoteUrl): array
    {
        $download = $this->downloadRemoteFile($remoteUrl);
        if (isset($download['errors'])) {
            return ['errors' => $download['errors']];
        }

        $filePath = (string) ($download['path'] ?? '');
        $fileSize = (int) ($download['size'] ?? 0);
        $mime = (string) ($download['mime'] ?? 'application/octet-stream');

        if ($filePath === '' || $fileSize <= 0) {
            return ['userErrors' => [['message' => 'Failed to download image from source URL']]];
        }

        $stagedCreate = <<<'GQL'
mutation Stage($input: [StagedUploadInput!]!) {
  stagedUploadsCreate(input: $input) {
    stagedTargets {
      url
      resourceUrl
      parameters { name value }
    }
    userErrors { field message }
  }
}
GQL;

        $vars = [
            'input' => [[
                'resource' => 'IMAGE',
                'filename' => basename($filePath),
                'mimeType' => $mime,
                'httpMethod' => 'POST',
                'fileSize' => (string) $fileSize,
            ]],
        ];

        $res = $this->client->query($shop, $stagedCreate, $vars);
        if (isset($res['errors'])) {
            @unlink($filePath);

            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.stagedUploadsCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            @unlink($filePath);

            return ['userErrors' => $userErrors];
        }

        $target = data_get($res, 'data.stagedUploadsCreate.stagedTargets.0', []);
        $uploadUrl = (string) data_get($target, 'url', '');
        $resourceUrl = (string) data_get($target, 'resourceUrl', '');
        $params = data_get($target, 'parameters', []);
        $params = is_array($params) ? $params : [];

        if ($uploadUrl === '' || $resourceUrl === '' || count($params) === 0) {
            @unlink($filePath);

            return ['userErrors' => [['message' => 'Invalid staged upload target returned by Shopify']]];
        }

        $multipart = [];
        foreach ($params as $p) {
            $name = (string) data_get($p, 'name', '');
            $value = (string) data_get($p, 'value', '');
            if ($name !== '') {
                $multipart[] = ['name' => $name, 'contents' => $value];
            }
        }

        $multipart[] = [
            'name' => 'file',
            'contents' => fopen($filePath, 'rb'),
            'filename' => basename($filePath),
            'headers' => [
                'Content-Type' => $mime,
            ],
        ];

        $uploadRes = $this->http->post($uploadUrl, ['multipart' => $multipart]);
        $status = $uploadRes->getStatusCode();
        @unlink($filePath);

        if ($status < 200 || $status >= 300) {
            return ['userErrors' => [['message' => 'Failed to upload to Shopify staged target (HTTP '.$status.')']]];
        }

        return ['resourceUrl' => $resourceUrl];
    }

    /**
     * @return array{path?: string, size?: int, mime?: string, errors?: mixed}
     */
    private function downloadRemoteFile(string $url): array
    {
        // HEAD check first — skip immediately if URL is not reachable or returns non-200.
        // This avoids wasting time downloading large files that will ultimately fail.
        $finalUrl = $url;
        try {
            $head = $this->http->head($finalUrl, ['timeout' => 5, 'connect_timeout' => 3]);
            $headStatus = $head->getStatusCode();
            if ($headStatus < 200 || $headStatus >= 300) {
                if (str_contains($url, '/pub/media/')) {
                    $fallbackUrl = str_replace('/pub/media/', '/media/', $url);
                    try {
                        $fallbackHead = $this->http->head($fallbackUrl, ['timeout' => 5, 'connect_timeout' => 3]);
                        if ($fallbackHead->getStatusCode() >= 200 && $fallbackHead->getStatusCode() < 300) {
                            $finalUrl = $fallbackUrl;
                            goto proceed_download;
                        }
                    } catch (\Exception $fe) {}
                }
                \Illuminate\Support\Facades\Log::warning('Download failed', ['url' => $finalUrl, 'status' => $headStatus]);
                return ['errors' => [['message' => 'Source image URL returned HTTP '.$headStatus]]];
            }
        } catch (\Exception $e) {
            if (str_contains($url, '/pub/media/')) {
                $fallbackUrl = str_replace('/pub/media/', '/media/', $url);
                try {
                    $fallbackHead = $this->http->head($fallbackUrl, ['timeout' => 5, 'connect_timeout' => 3]);
                    if ($fallbackHead->getStatusCode() >= 200 && $fallbackHead->getStatusCode() < 300) {
                        $finalUrl = $fallbackUrl;
                        goto proceed_download;
                    }
                } catch (\Exception $fe) {}
            }
            \Illuminate\Support\Facades\Log::warning('Download skipped (HEAD failed)', ['url' => $finalUrl, 'error' => $e->getMessage()]);
            return ['errors' => [['message' => 'Source image URL unreachable: '.$e->getMessage()]]];
        }

        proceed_download:
        $tmp = tempnam(sys_get_temp_dir(), 'swimg_');
        if ($tmp === false) {
            return ['errors' => [['message' => 'Unable to create temp file']]];
        }

        try {
            $res = $this->http->get($finalUrl, ['sink' => $tmp, 'timeout' => 30, 'connect_timeout' => 5]);
            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                @unlink($tmp);
                \Illuminate\Support\Facades\Log::warning('Download failed', ['url' => $finalUrl, 'status' => $status]);

                return ['errors' => [['message' => 'Source image URL returned HTTP '.$status]]];
            }
        } catch (\Exception $e) {
            @unlink($tmp);
            \Illuminate\Support\Facades\Log::error('Download exception', ['url' => $finalUrl, 'error' => $e->getMessage()]);
            return ['errors' => [['message' => $e->getMessage()]]];
        }

        $size = @filesize($tmp);
        $size = is_int($size) ? $size : 0;
        if ($size <= 0) {
            @unlink($tmp);

            return ['errors' => [['message' => 'Downloaded image was empty']]];
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $ext = $this->extensionFromMime($mime);
        if ($ext !== null) {
            $renamed = $tmp.$ext;
            if (@rename($tmp, $renamed)) {
                $tmp = $renamed;
            }
        }

        return ['path' => $tmp, 'size' => $size, 'mime' => $mime];
    }

    private function extensionFromMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            return '.jpg';
        }
        if ($mime === 'image/png') {
            return '.png';
        }
        if ($mime === 'image/webp') {
            return '.webp';
        }
        if ($mime === 'image/gif') {
            return '.gif';
        }

        return null;
    }

    private function normalizeRemoteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        $parts = @parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '') {
            $segments = explode('/', $path);
            $segments = array_map(static fn ($seg) => rawurlencode(rawurldecode((string) $seg)), $segments);
            $path = implode('/', $segments);
        }

        $query = (string) ($parts['query'] ?? '');
        if ($query !== '') {
            $query = str_replace(' ', '%20', $query);
        }

        $out = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $out .= ':'.$parts['port'];
        }
        $out .= $path;
        if ($query !== '') {
            $out .= '?'.$query;
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $out .= '#'.$parts['fragment'];
        }

        return $out;
    }

    /**
     * Poll Shopify until all specified media IDs are in READY status.
     * Max wait: 30 seconds (10 attempts × 3s). Non-ready media cannot be attached to variants.
     *
     * @param array<int, string> $mediaIds
     */
    private function waitForMediaReady(Shop $shop, string $productGid, array $mediaIds): void
    {
        if (count($mediaIds) === 0) {
            return;
        }

        $query = <<<'GQL'
query MediaStatus($id: ID!) {
  product(id: $id) {
    media(first: 50) {
      nodes {
        __typename
        ... on MediaImage {
          id
          status
        }
      }
    }
  }
}
GQL;

        $targetIds = array_fill_keys($mediaIds, true);
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                sleep(3);
            }

            $res = $this->client->query($shop, $query, ['id' => $productGid]);
            if (isset($res['errors'])) {
                break; // Can't poll — proceed anyway
            }

            $nodes = data_get($res, 'data.product.media.nodes', []);
            $nodes = is_array($nodes) ? $nodes : [];

            $pendingCount = 0;
            foreach ($nodes as $node) {
                $nodeId = (string) data_get($node, 'id', '');
                if (!isset($targetIds[$nodeId])) {
                    continue;
                }
                $status = strtoupper((string) data_get($node, 'status', ''));
                if ($status !== 'READY') {
                    $pendingCount++;
                }
            }

            if ($pendingCount === 0) {
                \Illuminate\Support\Facades\Log::info('All variant media ready', [
                    'shop' => $shop->shop_domain,
                    'product_gid' => $productGid,
                    'attempts' => $attempt + 1,
                ]);
                return;
            }

            \Illuminate\Support\Facades\Log::info('Waiting for media to be ready', [
                'shop' => $shop->shop_domain,
                'product_gid' => $productGid,
                'pending' => $pendingCount,
                'attempt' => $attempt + 1,
            ]);
        }
    }

    /**
     * @param  array<int, array{variantId: string, mediaIds: array<int, string>}>  $variantMedia
     * @return array{userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function appendVariantMedia(Shop $shop, string $productGid, array $variantMedia): array
    {
        $mutation = <<<'GQL'
mutation AppendVariantMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
  productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
    userErrors {
      field
      message
    }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'productId' => $productGid,
            'variantMedia' => $variantMedia,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.productVariantAppendMedia.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];

        return ['userErrors' => $userErrors];
    }

    /**
     * @return array<int, string>
     */
    private function collectProductImageUrls(string $apiBaseUrl, array $product): array
    {
        $urls = [];
        $entries = $product['media_gallery_entries'] ?? [];
        foreach ($entries as $entry) {
            if (($entry['media_type'] ?? '') !== 'image' || ($entry['disabled'] ?? false)) {
                continue;
            }
            $file = $entry['file'] ?? '';
            if ($file === '') {
                continue;
            }
            $abs = $this->buildMagentoMediaUrl($apiBaseUrl, $file);
            if ($abs !== null) {
                $urls[$abs] = true;
            }
        }
        return array_values(array_keys($urls));
    }

    private function buildMagentoMediaUrl(string $apiBaseUrl, string $file): ?string
    {
        $apiBaseUrl = rtrim($apiBaseUrl, '/');
        $file = '/' . ltrim($file, '/');
        return $apiBaseUrl . '/pub/media/catalog/product' . $file;
    }

    /**
     * Collect all image URLs for each variant.
     */
    private function collectVariantImageUrls(string $apiBaseUrl, array $children, array $parent = []): array
    {
        $variantImages = [];
        foreach ($children as $child) {
            $childId = (string) ($child['id'] ?? '');
            if ($childId === '') {
                continue;
            }

            $urls = [];
            $entries = $child['media_gallery_entries'] ?? [];
            foreach ($entries as $entry) {
                if (($entry['media_type'] ?? '') !== 'image' || ($entry['disabled'] ?? false)) {
                    continue;
                }
                $file = $entry['file'] ?? '';
                if ($file === '') {
                    continue;
                }
                $abs = $this->buildMagentoMediaUrl($apiBaseUrl, $file);
                if ($abs !== null) {
                    $urls[$abs] = true;
                }
            }
            $variantImages[$childId] = array_values(array_keys($urls));
        }
        return $variantImages;
    }
}
