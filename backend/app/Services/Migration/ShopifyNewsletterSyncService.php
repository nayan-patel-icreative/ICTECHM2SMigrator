<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

class ShopifyNewsletterSyncService
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Upsert newsletter recipient into Shopify as a Customer with email marketing consent.
     *
     * @return array{customerGid?: string, action?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function upsertRecipient(Shop $shop, string $sourceId, string $email, array $customerPayload): array
    {
        $sourceId = trim($sourceId);
        $email = trim($email);
        if ($sourceId === '' || $email === '') {
            return ['userErrors' => [['message' => 'Missing newsletter recipient sourceId or email']]];
        }

        $consent = isset($customerPayload['emailMarketingConsent']) && is_array($customerPayload['emailMarketingConsent'])
            ? $customerPayload['emailMarketingConsent']
            : null;

        $tags = isset($customerPayload['tags']) ? (string) $customerPayload['tags'] : '';

        $customerCore = $customerPayload;
        unset($customerCore['emailMarketingConsent']);

        $mapping = ShopifyIdMapping::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', 'newsletter')
            ->where('source_id', $sourceId)
            ->first();

        if ($mapping && is_string($mapping->shopify_gid) && $mapping->shopify_gid !== '') {
            $exists = $this->shopifyCustomerExists($shop, $mapping->shopify_gid);
            if (!$exists) {
                $mapping->delete();
                $mapping = null;
            }
        }

        if ($mapping && is_string($mapping->shopify_gid) && $mapping->shopify_gid !== '') {
            // Do not overwrite core customer fields for newsletter migration.
            $gid = (string) $mapping->shopify_gid;

            $consentRes = $consent ? $this->updateEmailMarketingConsent($shop, $gid, $consent) : ['ok' => true];
            if (!empty($consentRes['errors']) || !empty($consentRes['userErrors'])) {
                return $consentRes + ['action' => 'consent_update_failed', 'customerGid' => $gid];
            }

            if ($tags !== '') {
                $tagRes = $this->updateCustomer($shop, $gid, ['tags' => $tags]);
                if (!empty($tagRes['errors']) || !empty($tagRes['userErrors'])) {
                    return $tagRes + ['action' => 'tag_update_failed', 'customerGid' => $gid];
                }
            }

            return ['customerGid' => $gid, 'action' => 'updated'];
        }

        $existingCustomerGid = $this->findCustomerIdByEmail($shop, $email);
        if ($existingCustomerGid !== '') {
            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id' => $shop->id,
                'entity_type' => 'newsletter',
                'source_id' => $sourceId,
            ], [
                'shopify_gid' => $existingCustomerGid,
            ]);

            // Linked existing: only update consent + tag (do not overwrite name/phone/etc).
            $consentRes = $consent ? $this->updateEmailMarketingConsent($shop, $existingCustomerGid, $consent) : ['ok' => true];
            if (!empty($consentRes['errors']) || !empty($consentRes['userErrors'])) {
                return $consentRes + ['action' => 'linked_existing_consent_failed', 'customerGid' => $existingCustomerGid];
            }

            if ($tags !== '') {
                $tagRes = $this->updateCustomer($shop, $existingCustomerGid, ['tags' => $tags]);
                if (!empty($tagRes['errors']) || !empty($tagRes['userErrors'])) {
                    return $tagRes + ['action' => 'linked_existing_tag_failed', 'customerGid' => $existingCustomerGid];
                }
            }

            return ['customerGid' => $existingCustomerGid, 'action' => 'linked_existing_updated'];
        }

        // Create new customer first, then set marketing consent via dedicated mutation.
        $res = $this->createCustomer($shop, $customerCore);
        if (!empty($res['customerGid']) && is_string($res['customerGid'])) {
            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id' => $shop->id,
                'entity_type' => 'newsletter',
                'source_id' => $sourceId,
            ], [
                'shopify_gid' => $res['customerGid'],
            ]);

            if ($consent) {
                $consentRes = $this->updateEmailMarketingConsent($shop, (string) $res['customerGid'], $consent);
                if (!empty($consentRes['errors']) || !empty($consentRes['userErrors'])) {
                    return $consentRes + ['action' => 'created_consent_failed', 'customerGid' => (string) $res['customerGid']];
                }
            }

            return ['customerGid' => $res['customerGid'], 'action' => 'created'];
        }

        return $res + ['action' => 'create_failed'];
    }

    private function findCustomerIdByEmail(Shop $shop, string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        $q = <<<'GQL'
query CustomerByEmail($query: String!) {
  customers(first: 1, query: $query) {
    edges { node { id email } }
  }
}
GQL;

        $res = $this->client->query($shop, $q, ['query' => 'email:'.$email]);
        if (isset($res['errors'])) {
            return '';
        }

        $id = (string) data_get($res, 'data.customers.edges.0.node.id', '');
        return $id;
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

    /**
     * @param array{marketingState?: string, marketingOptInLevel?: string, consentUpdatedAt?: string} $consent
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function updateEmailMarketingConsent(Shop $shop, string $customerGid, array $consent): array
    {
        $state = strtoupper(trim((string) ($consent['marketingState'] ?? '')));
        $optIn = strtoupper(trim((string) ($consent['marketingOptInLevel'] ?? '')));
        $updatedAt = trim((string) ($consent['consentUpdatedAt'] ?? ''));

        if ($state === '') {
            return ['userErrors' => [['message' => 'Missing marketingState']]];
        }
        if ($optIn === '') {
            $optIn = 'CONFIRMED_OPT_IN';
        }

        $mutation = <<<'GQL'
mutation UpdateEmailMarketingConsent($input: CustomerEmailMarketingConsentUpdateInput!) {
  customerEmailMarketingConsentUpdate(input: $input) {
    customer { id }
    userErrors { field message }
  }
}
GQL;

        $emailMarketingConsent = [
            'marketingState' => $state,
            'marketingOptInLevel' => $optIn,
        ];

        if ($updatedAt !== '') {
            $emailMarketingConsent['consentUpdatedAt'] = $updatedAt;
        }

        $res = $this->client->query($shop, $mutation, [
            'input' => [
                'customerId' => $customerGid,
                'emailMarketingConsent' => $emailMarketingConsent,
            ],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.customerEmailMarketingConsentUpdate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
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
}
