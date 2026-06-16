<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

class ShopifyRedirectSyncService
{
    public function __construct(private ShopifyAdminGraphqlClient $client)
    {
    }

    /**
     * @return array{ok?: bool, id?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function upsertRedirect(Shop $shop, string $fromPath, string $toPath): array
    {
        $fromPath = $this->normalizePath($fromPath);
        $toPath = $this->normalizePath($toPath);

        if ($fromPath === '' || $toPath === '') {
            return ['userErrors' => [['message' => 'Redirect path/target is empty']]];
        }

        // Write-first strategy:
        // Some stores/apps have write access for redirects but no read access to urlRedirects.
        // Creating directly avoids ACCESS_DENIED on the read query.
        $created = $this->createRedirect($shop, $fromPath, $toPath);
        if (!empty($created['errors'])) {
            return $created;
        }

        $userErrors = $created['userErrors'] ?? [];
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) === 0) {
            return $created;
        }

        // Treat duplicate/already-exists as success to keep import idempotent without read scope.
        $allDuplicate = true;
        foreach ($userErrors as $e) {
            $msg = strtolower((string) data_get($e, 'message', ''));
            if (!str_contains($msg, 'already') && !str_contains($msg, 'exists') && !str_contains($msg, 'taken')) {
                $allDuplicate = false;
                break;
            }
        }

        if ($allDuplicate) {
            return ['ok' => true];
        }

        return ['userErrors' => $userErrors];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = preg_replace('~^https?://[^/]+~i', '', $path) ?? $path;
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return '/'.trim($path, '/');
    }

    /**
     * @return array{ok?: bool, id?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createRedirect(Shop $shop, string $path, string $target): array
    {
        $mutation = <<<'GQL'
mutation UrlRedirectCreate($urlRedirect: UrlRedirectInput!) {
  urlRedirectCreate(urlRedirect: $urlRedirect) {
    urlRedirect { id path target }
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'urlRedirect' => [
                'path' => $path,
                'target' => $target,
            ],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.urlRedirectCreate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        $id = (string) data_get($res, 'data.urlRedirectCreate.urlRedirect.id', '');
        return ['ok' => true, 'id' => $id];
    }

}
