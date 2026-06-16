<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

class ShopifyCustomerSyncService
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{customerGid?: string, userErrors?: array<int, mixed>, errors?: mixed, action?: string}
     */
    public function upsertBySourceId(Shop $shop, string $sourceId, array $customerPayload): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return ['userErrors' => [['message' => 'Missing customer sourceId']]];
        }

        $mapping = ShopifyIdMapping::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', 'customer')
            ->where('source_id', $sourceId)
            ->first();

        if ($mapping && is_string($mapping->shopify_gid) && $mapping->shopify_gid !== '') {
            $exists = $this->shopifyCustomerExists($shop, $mapping->shopify_gid);
            if (!$exists) {
                $mapping->delete();
                $mapping = null;
            }
        }

        $metafields = [];
        if (isset($customerPayload['__metafields']) && is_array($customerPayload['__metafields'])) {
            $metafields = $customerPayload['__metafields'];
        }

        $inputPayload = $customerPayload;
        unset($inputPayload['__metafields']);

        if ($mapping && is_string($mapping->shopify_gid) && $mapping->shopify_gid !== '') {
            $res = $this->updateCustomer($shop, $mapping->shopify_gid, $inputPayload);
            if (!empty($res['customerGid'])) {
                if (is_array($metafields) && count($metafields) > 0) {
                    $mf = $this->setCustomerMetafields($shop, (string) $res['customerGid'], $metafields);
                    if (!empty($mf['errors']) || !empty($mf['userErrors'])) {
                        return $mf + ['action' => 'updated_metafields_failed', 'customerGid' => (string) $res['customerGid']];
                    }
                }
                return ['customerGid' => $res['customerGid'], 'action' => 'updated'];
            }

            return $res + ['action' => 'update_failed'];
        }

        $res = $this->createCustomer($shop, $inputPayload);
        if (!empty($res['customerGid']) && is_string($res['customerGid'])) {
            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id' => $shop->id,
                'entity_type' => 'customer',
                'source_id' => $sourceId,
            ], [
                'shopify_gid' => $res['customerGid'],
            ]);

            if (is_array($metafields) && count($metafields) > 0) {
                $mf = $this->setCustomerMetafields($shop, (string) $res['customerGid'], $metafields);
                if (!empty($mf['errors']) || !empty($mf['userErrors'])) {
                    return $mf + ['action' => 'created_metafields_failed', 'customerGid' => (string) $res['customerGid']];
                }
            }

            return ['customerGid' => $res['customerGid'], 'action' => 'created'];
        }

        return $res + ['action' => 'create_failed'];
    }

    /**
     * @return array{customerGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function createCustomer(Shop $shop, array $payload): array
    {
        $mutation = <<<'GQL'
mutation CreateCustomer($input: CustomerInput!) {
  customerCreate(input: $input) {
    customer { id email }
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, ['input' => $payload]);
        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.customerCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        $id = data_get($res, 'data.customerCreate.customer.id');
        if (is_string($id) && $id !== '') {
            return ['customerGid' => $id];
        }

        return ['userErrors' => [['message' => 'Shopify customerCreate did not return a customer id']]];
    }

    /**
     * @return array{customerGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function updateCustomer(Shop $shop, string $customerGid, array $payload): array
    {
        $mutation = <<<'GQL'
mutation UpdateCustomer($input: CustomerInput!) {
  customerUpdate(input: $input) {
    customer { id email }
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'input' => array_merge(['id' => $customerGid], $payload),
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.customerUpdate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        $id = data_get($res, 'data.customerUpdate.customer.id');
        if (is_string($id) && $id !== '') {
            return ['customerGid' => $id];
        }

        return ['userErrors' => [['message' => 'Shopify customerUpdate did not return a customer id']]];
    }

    private function shopifyCustomerExists(Shop $shop, string $customerGid): bool
    {
        try {
            $q = <<<'GQL'
query CustomerExists($id: ID!) {
  customer(id: $id) { id }
}
GQL;

            $res = $this->client->query($shop, $q, ['id' => $customerGid]);
            if (isset($res['errors'])) {
                return true;
            }

            $id = (string) data_get($res, 'data.customer.id', '');
            return $id !== '';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $metafields
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function setCustomerMetafields(Shop $shop, string $customerGid, array $metafields): array
    {
        $mutation = <<<'GQL'
mutation SetCustomerMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id key namespace }
    userErrors { field message }
  }
}
GQL;

        $inputs = [];
        foreach ($metafields as $mf) {
            if (!is_array($mf)) {
                continue;
            }

            $ns = (string) ($mf['namespace'] ?? '');
            $key = (string) ($mf['key'] ?? '');
            $type = (string) ($mf['type'] ?? 'single_line_text_field');
            $value = (string) ($mf['value'] ?? '');

            if ($ns === '' || $key === '' || $value === '') {
                continue;
            }

            $inputs[] = [
                'ownerId' => $customerGid,
                'namespace' => $ns,
                'key' => $key,
                'type' => $type,
                'value' => $value,
            ];
        }

        if (count($inputs) === 0) {
            return ['ok' => true];
        }

        $res = $this->client->query($shop, $mutation, ['metafields' => $inputs]);
        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.metafieldsSet.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }
}
