<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

/**
 * Downloads Shopware order documents (invoices, delivery notes, etc.) and uploads
 * them to Shopify Files, returning the Shopify CDN URLs.
 *
 * Uses the EXACT same pattern as ShopifyMediaSyncService:
 *   1. Download PDF from Shopware (with Bearer token — required for /api/_action/document/)
 *   2. stagedUploadsCreate (resource: FILE) → get S3 staging URL + params
 *   3. POST multipart to S3 staging URL (same as product images)
 *   4. fileCreate with resourceUrl → Shopify CDN URL
 *   5. Poll for URL if still PROCESSING (fileCreate is async)
 *
 * Requires write_files scope on the Shopify app.
 */
class ShopifyOrderDocumentSyncService
{
    private ShopifyAdminGraphqlClient $client;

    private GuzzleClient $http;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
        $this->http = new GuzzleClient([
            'timeout'         => 60,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'verify'          => false,
        ]);
    }

    /**
     * Upload all documents for an order to Shopify Files.
     * Returns enriched document records with shopifyFileUrl and shopifyFileGid.
     *
     * @param array<int, array{id: string, typeKey: string, typeName: string, documentNumber: string, createdAt: string, downloadUrl: string}> $documents
     * @param string $shopwareToken  Shopware API access token for authenticated download
     * @return array<int, array{id: string, typeKey: string, typeName: string, documentNumber: string, createdAt: string, downloadUrl: string, shopifyFileUrl: string, shopifyFileGid: string}>
     */
    public function uploadDocuments(Shop $shop, array $documents, string $shopwareToken): array
    {
        $results = [];

        foreach ($documents as $doc) {
            $downloadUrl = (string) ($doc['downloadUrl'] ?? '');
            $docNumber   = (string) ($doc['documentNumber'] ?? '');
            $typeName    = (string) ($doc['typeName'] ?? 'Document');
            $typeKey     = (string) ($doc['typeKey'] ?? '');

            // Skip XML-only documents (ZUGFeRD XML) — only upload PDFs
            if (str_contains(strtolower($typeKey), 'zugferd') && str_contains(strtolower($typeKey), 'xml')) {
                $results[] = array_merge($doc, ['shopifyFileUrl' => '', 'shopifyFileGid' => '']);
                continue;
            }

            if ($downloadUrl === '') {
                $results[] = array_merge($doc, ['shopifyFileUrl' => '', 'shopifyFileGid' => '']);
                continue;
            }

            $filename = $this->buildFilename($typeName, $docNumber);
            $uploaded = $this->uploadSingleDocument($shop, $downloadUrl, $filename, $shopwareToken);

            $results[] = array_merge($doc, [
                'shopifyFileUrl' => $uploaded['fileUrl'] ?? '',
                'shopifyFileGid' => $uploaded['fileGid'] ?? '',
            ]);

            if (!empty($uploaded['error'])) {
                Log::warning('Order document upload failed (order still migrated)', [
                    'shop'       => $shop->shop_domain,
                    'doc_id'     => $doc['id'] ?? '',
                    'doc_number' => $docNumber,
                    'type'       => $typeName,
                    'error'      => $uploaded['error'],
                ]);
            } else {
                Log::info('Order document uploaded to Shopify', [
                    'shop'       => $shop->shop_domain,
                    'doc_number' => $docNumber,
                    'type'       => $typeName,
                    'file_url'   => $uploaded['fileUrl'] ?? '',
                ]);
            }
        }

        return $results;
    }

    /**
     * Upload a single document to Shopify Files using the same pattern as product images.
     *
     * @return array{fileUrl?: string, fileGid?: string, error?: string}
     */
    private function uploadSingleDocument(Shop $shop, string $downloadUrl, string $filename, string $shopwareToken): array
    {
        // Step 1: Download PDF from Shopware to a temp file
        $download = $this->downloadDocument($downloadUrl, $shopwareToken);
        if (isset($download['error'])) {
            return ['error' => $download['error']];
        }

        $filePath = (string) ($download['path'] ?? '');
        $fileSize = (int) ($download['size'] ?? 0);
        $mime     = (string) ($download['mime'] ?? 'application/pdf');

        if ($filePath === '' || $fileSize <= 0) {
            return ['error' => 'Downloaded document was empty or invalid'];
        }

        try {
            // Step 2: Create staged upload target (resource: FILE, same pattern as IMAGE)
            $staged = $this->createStagedUploadTarget($shop, $filename, $mime, $fileSize);
            if (isset($staged['error'])) {
                return ['error' => $staged['error']];
            }

            $uploadUrl   = (string) ($staged['uploadUrl'] ?? '');
            $resourceUrl = (string) ($staged['resourceUrl'] ?? '');
            $params      = $staged['params'] ?? [];

            if ($uploadUrl === '' || $resourceUrl === '') {
                return ['error' => 'Shopify staged upload did not return upload URL'];
            }

            // Step 3: POST file to S3 staging URL (exact same as uploadDownloadedFileToStagedTarget)
            $upload = $this->uploadToStagedTarget($filePath, $filename, $mime, $uploadUrl, $params);
            if (isset($upload['error'])) {
                return ['error' => $upload['error']];
            }

            // Step 4: Create file in Shopify Files using resourceUrl
            $file = $this->createShopifyFile($shop, $resourceUrl, $filename);
            if (isset($file['error'])) {
                return ['error' => $file['error']];
            }

            $fileGid = (string) ($file['fileGid'] ?? '');
            $fileUrl = (string) ($file['fileUrl'] ?? '');

            // Step 5: Poll for URL if still processing (fileCreate is async)
            if ($fileGid !== '' && $fileUrl === '') {
                $fileUrl = $this->pollForFileUrl($shop, $fileGid);
            }

            return ['fileGid' => $fileGid, 'fileUrl' => $fileUrl];
        } finally {
            if ($filePath !== '') {
                @unlink($filePath);
            }
        }
    }

    /**
     * Download a document from Shopware.
     * The /api/_action/document/ endpoint ALWAYS requires Authorization: Bearer.
     * For ZUGFeRD documents that return XML, we request PDF explicitly.
     *
     * @return array{path?: string, size?: int, mime?: string, error?: string}
     */
    private function downloadDocument(string $url, string $shopwareToken): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'swdoc_');
        if ($tmp === false) {
            return ['error' => 'Unable to create temp file'];
        }

        // Always use .pdf extension
        $tmpPdf = $tmp . '.pdf';
        @rename($tmp, $tmpPdf);
        $tmp = $tmpPdf;

        // Try with explicit PDF accept header first, then fall back to generic
        $acceptHeaders = [
            'application/pdf',
            'application/pdf,application/octet-stream,*/*',
        ];

        foreach ($acceptHeaders as $accept) {
            // Clean up previous attempt
            if (file_exists($tmp) && filesize($tmp) === 0) {
                // keep the file, just re-download
            }

            try {
                $response = $this->http->get($url, [
                    'sink'    => $tmp,
                    'timeout' => 60,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $shopwareToken,
                        'Accept'        => $accept,
                    ],
                ]);

                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    @unlink($tmp);
                    return ['error' => "Document download returned HTTP {$status}"];
                }

                $size = @filesize($tmp);
                $size = is_int($size) ? $size : 0;
                if ($size <= 0) {
                    // Try next accept header
                    continue;
                }

                // Detect MIME type
                $mime = 'application/pdf';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $detected = (string) finfo_file($finfo, $tmp);
                        finfo_close($finfo);
                        if ($detected !== '' && !str_contains($detected, 'octet-stream')) {
                            $mime = $detected;
                        }
                    }
                }

                // Skip non-PDF (e.g. ZUGFeRD XML returns text/xml or application/xml)
                if (!str_contains($mime, 'pdf')) {
                    // Try next accept header
                    continue;
                }

                return ['path' => $tmp, 'size' => $size, 'mime' => 'application/pdf'];
            } catch (\Throwable $e) {
                @unlink($tmp);
                return ['error' => 'Download exception: ' . $e->getMessage()];
            }
        }

        // All attempts failed — document is XML-only (ZUGFeRD pure XML), no PDF available
        @unlink($tmp);
        return ['error' => 'Document is XML-only (ZUGFeRD E-invoice) — no PDF available to upload'];
    }

    /**
     * Create a staged upload target for a FILE (same as IMAGE but resource: FILE).
     *
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
     * POST the file to the Shopify S3 staging URL.
     * Exact same logic as ShopifyMediaSyncService::uploadDownloadedFileToStagedTarget().
     *
     * @param array<int, mixed> $params
     * @return array{ok?: bool, error?: string}
     */
    private function uploadToStagedTarget(string $filePath, string $filename, string $mime, string $uploadUrl, array $params): array
    {
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
            return ['error' => 'Unable to open document file for upload'];
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
     * Create a file in Shopify Files using the staged resourceUrl.
     * Uses fileCreate mutation with contentType: FILE.
     *
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

    /**
     * Poll Shopify for the file URL after async processing.
     * fileCreate is async — the URL may be empty immediately after creation.
     * Polls up to 5 times with 2-second intervals.
     */
    private function pollForFileUrl(Shop $shop, string $fileGid, int $maxAttempts = 5): string
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
                sleep(2);
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

    /**
     * Build a clean filename for the document.
     * e.g. "Invoice_1003.pdf", "Delivery_Note_1001.pdf"
     */
    private function buildFilename(string $typeName, string $docNumber): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $typeName) ?? 'Document';
        $base = trim($base, '_');
        if ($docNumber !== '') {
            $base .= '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $docNumber);
        }
        return $base . '.pdf';
    }
}
