<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

/**
 * Downloads Magento digital product files and uploads them to Shopify Files CDN,
 * returning the public CDN URLs.
 *
 * Magento private digital download files have media.private=true and media.url="" (empty).
 * The file lives in the Magento private filesystem ({files_path}/media/xx/xx/xx/filename.ext).
 *
 * Download strategy (tried in order):
 *   1. Public HTTP URL  – media.private=false AND media.url starts with http/https
 *   2. Local disk read  – files_path is configured AND file exists at {files_path}/{media.path}
 *   3. Built public URL – media.private=false AND media.path is set (try {api_url}/{media.path})
 *   4. Authenticated API download – GET /api/_action/media/{mediaId}/download with Bearer token
 *
 * After obtaining the file bytes, the standard staged-upload pattern is used:
 *   stagedUploadsCreate (resource: FILE) → S3 staging URL → POST multipart → fileCreate → poll
 */
class ShopifyDigitalFileSyncService
{
    private ShopifyAdminGraphqlClient $client;
    private GuzzleClient $http;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
        $this->http = new GuzzleClient([
            'timeout'         => 120,
            'connect_timeout' => 15,
            'http_errors'     => false,
            'verify'          => false,
        ]);
    }

    /**
     * Upload all digital download files for a product/variant to Shopify Files.
     *
     * @param array<int, array{
     *   url: string, mediaId: string, fileName: string, fileExtension: string,
     *   mimeType: string, path: string, private: bool, hasFile: bool
     * }> $files
     * @param string $magentoBaseUrl  e.g. "http://magentonew.local"
     * @param string $magentoToken    Magento API Bearer token
     * @param string $magentoFilesPath  Absolute path to Magento private files dir
     *                                   e.g. "/var/www/html/magento/files"
     * @return array<int, array{fileName: string, shopifyFileUrl: string, shopifyFileGid: string, error?: string}>
     */
    public function uploadDigitalFiles(
        Shop   $shop,
        array  $files,
        string $magentoBaseUrl,
        string $magentoToken,
        string $magentoFilesPath = ''
    ): array {
        $results = [];

        foreach ($files as $file) {
            $mediaId   = (string) ($file['mediaId'] ?? '');
            $fileName  = (string) ($file['fileName'] ?? '');
            $ext       = (string) ($file['fileExtension'] ?? '');
            $mime      = (string) ($file['mimeType'] ?? 'application/octet-stream');
            $publicUrl = (string) ($file['url'] ?? '');
            $mediaPath = (string) ($file['path'] ?? '');
            $isPrivate = (bool)   ($file['private'] ?? false);
            $hasFile   = (bool)   ($file['hasFile'] ?? true);

            $displayName = $fileName !== '' ? ($ext !== '' ? $fileName . '.' . $ext : $fileName) : $mediaId;

            // If Magento says hasFile=false, there is no file — skip entirely.
            if (!$hasFile) {
                $results[] = [
                    'fileName'       => $displayName,
                    'shopifyFileUrl' => '',
                    'shopifyFileGid' => '',
                    'error'          => 'Magento media entity has hasFile=false (no file stored)',
                ];
                Log::warning('Digital file skipped — Magento hasFile=false', [
                    'shop'      => $shop->shop_domain,
                    'mediaId'   => $mediaId,
                    'fileName'  => $displayName,
                ]);
                continue;
            }

            $uploaded = $this->tryAllStrategies(
                $shop,
                $publicUrl,
                $mediaPath,
                $mediaId,
                $displayName,
                $mime,
                $isPrivate,
                $magentoBaseUrl,
                $magentoToken,
                $magentoFilesPath
            );

            $results[] = [
                'fileName'       => $displayName,
                'shopifyFileUrl' => $uploaded['fileUrl'] ?? '',
                'shopifyFileGid' => $uploaded['fileGid'] ?? '',
            ];

            if (!empty($uploaded['error'])) {
                $results[count($results) - 1]['error'] = $uploaded['error'];
                Log::warning('Digital file upload failed (product still migrated)', [
                    'shop'      => $shop->shop_domain,
                    'mediaId'   => $mediaId,
                    'fileName'  => $displayName,
                    'strategy'  => $uploaded['strategy'] ?? 'unknown',
                    'error'     => $uploaded['error'],
                ]);
            } else {
                Log::info('Digital file uploaded to Shopify CDN', [
                    'shop'      => $shop->shop_domain,
                    'fileName'  => $displayName,
                    'fileUrl'   => $uploaded['fileUrl'] ?? '',
                    'strategy'  => $uploaded['strategy'] ?? 'unknown',
                ]);
            }
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Try all download strategies in priority order.
     *
     * @return array{fileUrl?: string, fileGid?: string, strategy?: string, error?: string}
     */
    private function tryAllStrategies(
        Shop   $shop,
        string $publicUrl,
        string $mediaPath,
        string $mediaId,
        string $displayName,
        string $mime,
        bool   $isPrivate,
        string $magentoBaseUrl,
        string $magentoToken,
        string $magentoFilesPath
    ): array {
        $errors = [];

        // ── Strategy 1: Public HTTP URL ──────────────────────────────────────
        // Use media.url directly — only available for non-private public files.
        if (!$isPrivate
            && $publicUrl !== ''
            && (str_starts_with($publicUrl, 'http://') || str_starts_with($publicUrl, 'https://'))
        ) {
            $download = $this->downloadFile($publicUrl, '', $mime);
            if (!isset($download['error'])) {
                $result = $this->stageAndCreate($shop, $download, $displayName, $mime);
                if (!isset($result['error'])) {
                    $result['strategy'] = 'public_url';
                    return $result;
                }
                $errors[] = '[S1 public_url] ' . $result['error'];
            } else {
                $errors[] = '[S1 public_url] ' . $download['error'];
            }
        }

        // ── Strategy 2: Local filesystem read ────────────────────────────────
        // Magento stores private media in {files_path}/media/xx/xx/xx/file.ext.
        // When the migrator runs on the same server as Magento, we can read
        // directly from disk — this is the most reliable approach for private files.
        if ($mediaPath !== '' && $magentoFilesPath !== '') {
            $diskPath = rtrim($magentoFilesPath, '/') . '/' . ltrim($mediaPath, '/');
            if (file_exists($diskPath) && is_readable($diskPath) && filesize($diskPath) > 0) {
                $result = $this->uploadFromDisk($shop, $diskPath, $displayName, $mime);
                if (!isset($result['error'])) {
                    $result['strategy'] = 'local_filesystem';
                    return $result;
                }
                $errors[] = '[S2 local_filesystem] ' . $result['error'];
            } else {
                $errors[] = '[S2 local_filesystem] File not found or empty at: ' . $diskPath;
            }
        }

        // ── Strategy 3: Built public URL from base URL + path ─────────────────
        // For non-private files where media.url is empty but media.path is set,
        // try constructing the URL as {base_url}/{path}.
        if (!$isPrivate && $mediaPath !== '') {
            $builtUrl = rtrim($magentoBaseUrl, '/') . '/' . ltrim($mediaPath, '/');
            $download = $this->downloadFile($builtUrl, '', $mime);
            if (!isset($download['error'])) {
                $result = $this->stageAndCreate($shop, $download, $displayName, $mime);
                if (!isset($result['error'])) {
                    $result['strategy'] = 'built_public_url';
                    return $result;
                }
                $errors[] = '[S3 built_public_url] ' . $result['error'];
            } else {
                $errors[] = '[S3 built_public_url] ' . $download['error'];
            }
        }

        // ── Strategy 4: Authenticated API download ───────────────────────────
        // Last resort: GET /api/_action/media/{mediaId}/download with Bearer token.
        // This route exists in some Magento/plugin versions but 404s in core.
        if ($mediaId !== '' && $magentoToken !== '') {
            $apiUrl = rtrim($magentoBaseUrl, '/') . '/api/_action/media/' . $mediaId . '/download';
            $download = $this->downloadFile($apiUrl, $magentoToken, $mime);
            if (!isset($download['error'])) {
                $result = $this->stageAndCreate($shop, $download, $displayName, $mime);
                if (!isset($result['error'])) {
                    $result['strategy'] = 'api_download';
                    return $result;
                }
                $errors[] = '[S4 api_download] ' . $result['error'];
            } else {
                $errors[] = '[S4 api_download] ' . $download['error'];
            }
        }

        // All strategies exhausted
        $allErrors = implode('; ', $errors);
        Log::debug('Digital file: all download strategies failed', [
            'shop'     => $shop->shop_domain,
            'mediaId'  => $mediaId,
            'fileName' => $displayName,
            'private'  => $isPrivate,
            'errors'   => $allErrors,
        ]);

        return ['error' => 'All download strategies failed: ' . $allErrors];
    }

    /**
     * Upload a file that already exists on the local filesystem.
     *
     * @return array{fileUrl?: string, fileGid?: string, error?: string}
     */
    private function uploadFromDisk(Shop $shop, string $diskPath, string $filename, string $mime): array
    {
        $size = @filesize($diskPath);
        $size = is_int($size) ? $size : 0;
        if ($size <= 0) {
            return ['error' => 'Local file is empty: ' . $diskPath];
        }

        // Detect actual MIME
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = (string) finfo_file($finfo, $diskPath);
                finfo_close($finfo);
                if ($detected !== '' && $detected !== 'application/octet-stream') {
                    $mime = $detected;
                }
            }
        }

        return $this->stageAndCreate($shop, [
            'path' => $diskPath,
            'size' => $size,
            'mime' => $mime,
            'own'  => false,   // do NOT unlink — the file belongs to Magento
        ], $filename, $mime);
    }

    /**
     * Download a file via HTTP (optionally authenticated) to a temp file.
     *
     * @return array{path?: string, size?: int, mime?: string, error?: string}
     */
    private function downloadFile(string $url, string $token, string $expectedMime): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'swdl_');
        if ($tmp === false) {
            return ['error' => 'Unable to create temp file'];
        }

        $headers = ['Accept' => '*/*'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = $this->http->get($url, [
                'sink'    => $tmp,
                'timeout' => 120,
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                @unlink($tmp);
                return ['error' => "File download returned HTTP {$status} for URL: {$url}"];
            }

            $size = @filesize($tmp);
            $size = is_int($size) ? $size : 0;
            if ($size <= 0) {
                @unlink($tmp);
                return ['error' => 'Downloaded file was empty'];
            }

            // Detect actual MIME
            $mime = $expectedMime;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected = (string) finfo_file($finfo, $tmp);
                    finfo_close($finfo);
                    if ($detected !== '' && $detected !== 'application/octet-stream') {
                        $mime = $detected;
                    }
                }
            }

            return ['path' => $tmp, 'size' => $size, 'mime' => $mime, 'own' => true];
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['error' => 'Download exception: ' . $e->getMessage()];
        }
    }

    /**
     * Stage → upload to S3 → fileCreate → poll for CDN URL.
     *
     * @param array{path?: string, size?: int, mime?: string, own?: bool} $download
     * @return array{fileUrl?: string, fileGid?: string, error?: string}
     */
    private function stageAndCreate(Shop $shop, array $download, string $filename, string $mime): array
    {
        $filePath = (string) ($download['path'] ?? '');
        $fileSize = (int)   ($download['size'] ?? 0);
        $mime     = (string) ($download['mime'] ?? $mime);
        $ownFile  = (bool)   ($download['own']  ?? true);

        if ($filePath === '' || $fileSize <= 0) {
            return ['error' => 'Invalid download result — no path or zero size'];
        }

        try {
            // Stage
            $staged = $this->createStagedUploadTarget($shop, $filename, $mime, $fileSize);
            if (isset($staged['error'])) {
                return ['error' => $staged['error']];
            }

            $uploadUrl   = (string) ($staged['uploadUrl'] ?? '');
            $resourceUrl = (string) ($staged['resourceUrl'] ?? '');
            $params      = $staged['params'] ?? [];

            if ($uploadUrl === '' || $resourceUrl === '') {
                return ['error' => 'Staged upload did not return upload URL'];
            }

            // Upload to S3
            $upload = $this->uploadToStagedTarget($filePath, $filename, $mime, $uploadUrl, $params);
            if (isset($upload['error'])) {
                return ['error' => $upload['error']];
            }

            // fileCreate
            $file = $this->createShopifyFile($shop, $resourceUrl, $filename);
            if (isset($file['error'])) {
                return ['error' => $file['error']];
            }

            $fileGid = (string) ($file['fileGid'] ?? '');
            $fileUrl = (string) ($file['fileUrl'] ?? '');

            // Poll for URL
            if ($fileGid !== '' && $fileUrl === '') {
                $fileUrl = $this->pollForFileUrl($shop, $fileGid);
            }

            return ['fileGid' => $fileGid, 'fileUrl' => $fileUrl];
        } finally {
            if ($ownFile && $filePath !== '') {
                @unlink($filePath);
            }
        }
    }

    /**
     * @return array{uploadUrl?: string, resourceUrl?: string, params?: array<int, mixed>, error?: string}
     */
    private function createStagedUploadTarget(Shop $shop, string $filename, string $mime, int $fileSize): array
    {
        $mutation = <<<'GQL'
mutation StageUpload($input: [StagedUploadInput!]!) {
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

        $res = $this->client->query($shop, $mutation, [
            'input' => [[
                'resource'   => 'FILE',
                'filename'   => $filename,
                'mimeType'   => $mime,
                'httpMethod' => 'POST',
                'fileSize'   => (string) $fileSize,
            ]],
        ]);

        if (isset($res['errors'])) {
            return ['error' => 'stagedUploadsCreate failed: ' . json_encode($res['errors'])];
        }

        $userErrors = data_get($res, 'data.stagedUploadsCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['error' => 'stagedUploadsCreate userErrors: ' . json_encode($userErrors)];
        }

        $target = data_get($res, 'data.stagedUploadsCreate.stagedTargets.0', []);
        if (!is_array($target)) {
            return ['error' => 'stagedUploadsCreate returned no targets'];
        }

        return [
            'uploadUrl'   => (string) data_get($target, 'url', ''),
            'resourceUrl' => (string) data_get($target, 'resourceUrl', ''),
            'params'      => (array) data_get($target, 'parameters', []),
        ];
    }

    /**
     * @param array<int, mixed> $params
     * @return array{ok?: bool, error?: string}
     */
    private function uploadToStagedTarget(
        string $filePath,
        string $filename,
        string $mime,
        string $uploadUrl,
        array  $params
    ): array {
        $multipart = [];
        foreach ($params as $p) {
            $name  = (string) data_get($p, 'name', '');
            $value = (string) data_get($p, 'value', '');
            if ($name !== '') {
                $multipart[] = ['name' => $name, 'contents' => $value];
            }
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return ['error' => 'Unable to open file for upload'];
        }

        $multipart[] = [
            'name'     => 'file',
            'contents' => $handle,
            'filename' => $filename,
            'headers'  => ['Content-Type' => $mime],
        ];

        try {
            $response = $this->http->post($uploadUrl, ['multipart' => $multipart]);
            $status   = $response->getStatusCode();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($status < 200 || $status >= 300) {
            return ['error' => "Upload to Shopify staging failed with HTTP {$status}"];
        }

        return ['ok' => true];
    }

    /**
     * @return array{fileUrl?: string, fileGid?: string, error?: string}
     */
    private function createShopifyFile(Shop $shop, string $resourceUrl, string $filename): array
    {
        $mutation = <<<'GQL'
mutation CreateFile($files: [FileCreateInput!]!) {
  fileCreate(files: $files) {
    files {
      id
      fileStatus
      ... on GenericFile {
        id
        url
        fileStatus
      }
    }
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'files' => [[
                'originalSource' => $resourceUrl,
                'filename'       => $filename,
                'contentType'    => 'FILE',
            ]],
        ]);

        if (isset($res['errors'])) {
            return ['error' => 'fileCreate failed: ' . json_encode($res['errors'])];
        }

        $userErrors = data_get($res, 'data.fileCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['error' => 'fileCreate userErrors: ' . json_encode($userErrors)];
        }

        $file = data_get($res, 'data.fileCreate.files.0', []);
        if (!is_array($file)) {
            return ['error' => 'fileCreate returned no file'];
        }

        return [
            'fileGid' => (string) data_get($file, 'id', ''),
            'fileUrl' => (string) data_get($file, 'url', ''),
        ];
    }

    private function pollForFileUrl(Shop $shop, string $fileGid, int $maxAttempts = 8): string
    {
        $query = <<<'GQL'
query GetFile($id: ID!) {
  node(id: $id) {
    ... on GenericFile {
      id
      url
      fileStatus
    }
  }
}
GQL;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($i > 0) {
                sleep(3);
            }

            $res = $this->client->query($shop, $query, ['id' => $fileGid]);
            if (isset($res['errors'])) {
                break;
            }

            $url    = (string) data_get($res, 'data.node.url', '');
            $status = (string) data_get($res, 'data.node.fileStatus', '');

            if ($url !== '') {
                return $url;
            }

            if ($status === 'FAILED') {
                break;
            }
        }

        return '';
    }
}
