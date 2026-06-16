<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

class ShopifyOrderSyncService
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Ensure order document URL metafield definitions exist AND are pinned so they
     * appear directly on the order detail page in Shopify Admin.
     * Called once per shop, result cached for 7 days.
     *
     * @return array{ok?: bool, errors?: mixed}
     */
    public function ensureOrderDocumentMetafieldDefinitions(Shop $shop): array
    {
        $cacheKey = 'shopify:order_doc_metafields_ensured_v8:' . $shop->id;
        if (\Illuminate\Support\Facades\Cache::get($cacheKey)) {
            return ['ok' => true];
        }

        // magento namespace — user-visible order metadata
        $magentoDefinitions = [
            ['name' => 'Magento Order Number',         'namespace' => 'magento', 'key' => 'order_number',         'ownerType' => 'ORDER', 'type' => 'single_line_text_field'],
            ['name' => 'Magento Order ID',             'namespace' => 'magento', 'key' => 'order_id',             'ownerType' => 'ORDER', 'type' => 'single_line_text_field'],
            ['name' => 'Magento Status',               'namespace' => 'magento', 'key' => 'status',               'ownerType' => 'ORDER', 'type' => 'single_line_text_field'],
            ['name' => 'Magento State',                'namespace' => 'magento', 'key' => 'state',                'ownerType' => 'ORDER', 'type' => 'single_line_text_field'],
            ['name' => 'Magento Payment Method',       'namespace' => 'magento', 'key' => 'payment_method',       'ownerType' => 'ORDER', 'type' => 'single_line_text_field'],
            ['name' => 'Magento Shipping Description', 'namespace' => 'magento', 'key' => 'shipping_description', 'ownerType' => 'ORDER', 'type' => 'single_line_text_field'],
            ['name' => 'Magento Invoices',             'namespace' => 'magento', 'key' => 'invoices_json',        'ownerType' => 'ORDER', 'type' => 'json'],
            ['name' => 'Magento Shipments',            'namespace' => 'magento', 'key' => 'shipments_json',       'ownerType' => 'ORDER', 'type' => 'json'],
            ['name' => 'Magento Credit Notes',         'namespace' => 'magento', 'key' => 'credit_notes_json',    'ownerType' => 'ORDER', 'type' => 'json'],
        ];

        $existingQuery = <<<'GQL'
query ExistingOrderDefs($namespace: String!) {
  metafieldDefinitions(first: 50, ownerType: ORDER, namespace: $namespace) {
    nodes { id key pinnedPosition }
  }
}
GQL;

        $createMutation = <<<'GQL'
mutation CreateDef($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message }
  }
}
GQL;

        $pinMutation = <<<'GQL'
mutation PinDef($id: ID!) {
  metafieldDefinitionPin(definitionId: $id) {
    pinnedDefinition { id pinnedPosition }
    userErrors { field message }
  }
}
GQL;

        $deleteMutation = <<<'GQL'
mutation DeleteDef($id: ID!, $deleteAllAssociatedMetafields: Boolean) {
  metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: $deleteAllAssociatedMetafields) {
    deletedDefinitionId
    userErrors { field message }
  }
}
GQL;

        // Step 1: Clean up legacy namespaces
        $legacyNamespaces = ['shopware', 'shopware_docs', 'magento_docs'];
        foreach ($legacyNamespaces as $legacyNs) {
            try {
                $legacyRes = $this->client->query($shop, $existingQuery, ['namespace' => $legacyNs]);
                $legacyNodes = data_get($legacyRes, 'data.metafieldDefinitions.nodes', []);
                if (is_array($legacyNodes)) {
                    foreach ($legacyNodes as $node) {
                        $defId = $node['id'] ?? '';
                        if ($defId !== '') {
                            $this->client->query($shop, $deleteMutation, [
                                'id' => $defId,
                                'deleteAllAssociatedMetafields' => true,
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-blocking cleanup
            }
        }

        // Clean up documents_json in magento namespace if it exists
        try {
            $magentoRes = $this->client->query($shop, $existingQuery, ['namespace' => 'magento']);
            $magentoNodes = data_get($magentoRes, 'data.metafieldDefinitions.nodes', []);
            if (is_array($magentoNodes)) {
                foreach ($magentoNodes as $node) {
                    if (($node['key'] ?? '') === 'documents_json') {
                        $defId = $node['id'] ?? '';
                        if ($defId !== '') {
                            $this->client->query($shop, $deleteMutation, [
                                'id' => $defId,
                                'deleteAllAssociatedMetafields' => true,
                            ]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking cleanup
        }

        // Step 2: Create and pin Magento definitions
        $namespaceGroups = [
            'magento' => $magentoDefinitions,
        ];

        foreach ($namespaceGroups as $namespace => $definitions) {
            $existingRes   = $this->client->query($shop, $existingQuery, ['namespace' => $namespace]);
            $existingNodes = data_get($existingRes, 'data.metafieldDefinitions.nodes', []);
            $existingNodes = is_array($existingNodes) ? $existingNodes : [];

            $existingMap = [];
            foreach ($existingNodes as $node) {
                $k = (string) ($node['key'] ?? '');
                if ($k !== '') {
                    $existingMap[$k] = [
                        'id'     => (string) ($node['id'] ?? ''),
                        'pinned' => ($node['pinnedPosition'] !== null),
                    ];
                }
            }

            foreach ($definitions as $definition) {
                $key      = (string) ($definition['key'] ?? '');
                $existing = $existingMap[$key] ?? null;

                if ($existing !== null) {
                    if (!$existing['pinned'] && $existing['id'] !== '') {
                        $this->client->query($shop, $pinMutation, ['id' => $existing['id']]);
                    }
                } else {
                    $res = $this->client->query($shop, $createMutation, ['definition' => $definition]);
                    if (isset($res['errors'])) {
                        continue;
                    }
                    $createdId = (string) data_get($res, 'data.metafieldDefinitionCreate.createdDefinition.id', '');
                    if ($createdId !== '') {
                        $this->client->query($shop, $pinMutation, ['id' => $createdId]);
                    }
                }
            }
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, 1, now()->addDays(7));
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $orderPayload
     * @param array<int, array<string, mixed>> $metafields
     * @return array{orderGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function createOrder(Shop $shop, array $orderPayload, array $metafields = []): array
    {
        $tags = [];
        if (isset($orderPayload['tags']) && is_array($orderPayload['tags'])) {
            $tags = array_values(array_filter($orderPayload['tags'], fn ($t) => is_string($t) && trim($t) !== ''));
        }

        if (isset($orderPayload['tags'])) {
            unset($orderPayload['tags']);
        }

        $core = $this->createOrderCore($shop, $orderPayload);
        if (!empty($core['errors']) || !empty($core['userErrors'])) {
            return $core;
        }

        $gid = (string) ($core['orderGid'] ?? '');
        if ($gid === '') {
            return ['userErrors' => [['message' => 'orderCreate did not return an order id']]];
        }

        if (count($tags) > 0) {
            $addTags = $this->addOrderTags($shop, $gid, $tags);
            if (!empty($addTags['errors']) || !empty($addTags['userErrors'])) {
                // don't fail the order creation if tags fail to apply
            }
        }

        if (count($metafields) > 0) {
            $set = $this->setOrderMetafields($shop, $gid, $metafields);
            if (!empty($set['errors']) || !empty($set['userErrors'])) {
                return $set + ['orderGid' => $gid];
            }
        }

        return ['orderGid' => $gid];
    }

    /**
     * @param array<string, mixed> $orderPayload
     * @return array{orderGid?: string, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function createOrderCore(Shop $shop, array $orderPayload): array
    {
        $mutation = <<<'GQL'
mutation CreateOrder($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
  orderCreate(order: $order, options: $options) {
    userErrors { field message }
    order { id name }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'order' => $orderPayload,
            'options' => [
                'inventoryBehaviour' => 'BYPASS',
                'sendReceipt' => false,
                'sendFulfillmentReceipt' => false,
            ],
        ]);

        if (isset($res['errors'])) {
            $out = ['errors' => $res['errors']];
            if (isset($res['extensions'])) {
                $out['extensions'] = $res['extensions'];
            }
            return $out;
        }

        $userErrors = data_get($res, 'data.orderCreate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            $out = ['userErrors' => $userErrors];
            if (isset($res['extensions'])) {
                $out['extensions'] = $res['extensions'];
            }
            return $out;
        }

        $gid = (string) data_get($res, 'data.orderCreate.order.id', '');
        if ($gid === '') {
            return ['userErrors' => [['message' => 'orderCreate did not return an order id']]];
        }

        return ['orderGid' => $gid];
    }

    /**
     * Record a manual payment on an imported order (Shopify payment timeline).
     *
     * @param array{amount?: string, currencyCode?: string, paymentMethodName?: string, processedAt?: string} $capture
     * @return array{ok?: bool, skipped?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function createManualPayment(Shop $shop, string $orderGid, array $capture): array
    {
        $orderGid = trim($orderGid);
        $amount = trim((string) ($capture['amount'] ?? ''));
        $currencyCode = strtoupper(trim((string) ($capture['currencyCode'] ?? '')));

        if ($orderGid === '' || $amount === '' || $currencyCode === '') {
            return ['ok' => true, 'skipped' => true];
        }

        $variables = [
            'id' => $orderGid,
            'amount' => [
                'amount' => $amount,
                'currencyCode' => $currencyCode,
            ],
        ];

        $paymentMethodName = trim((string) ($capture['paymentMethodName'] ?? ''));
        if ($paymentMethodName !== '') {
            $variables['paymentMethodName'] = $paymentMethodName;
        }

        $processedAt = trim((string) ($capture['processedAt'] ?? ''));
        if ($processedAt !== '') {
            $variables['processedAt'] = $processedAt;
        }

        $mutation = <<<'GQL'
mutation OrderCreateManualPayment($id: ID!, $amount: MoneyInput!, $paymentMethodName: String, $processedAt: DateTime) {
  orderCreateManualPayment(id: $id, amount: $amount, paymentMethodName: $paymentMethodName, processedAt: $processedAt) {
    userErrors { field message }
    order { id displayFinancialStatus }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, $variables);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.orderCreateManualPayment.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Mark an imported order fulfilled when orderCreate did not apply fulfillmentStatus.
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function fulfillImportedOrder(Shop $shop, string $orderGid, string $locationGid): array
    {
        $orderGid = trim($orderGid);
        $locationGid = trim($locationGid);
        if ($orderGid === '' || $locationGid === '') {
            return ['ok' => true];
        }

        $query = <<<'GQL'
query OrderFulfillmentOrders($id: ID!) {
  order(id: $id) {
    fulfillmentOrders(first: 20) {
      nodes {
        id
        status
      }
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['id' => $orderGid]);
        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $nodes = data_get($res, 'data.order.fulfillmentOrders.nodes', []);
        if (!is_array($nodes) || count($nodes) === 0) {
            return ['ok' => true];
        }

        $lineItemsByFulfillmentOrder = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $status = strtoupper((string) ($node['status'] ?? ''));
            if ($status !== '' && !in_array($status, ['OPEN', 'IN_PROGRESS', 'SCHEDULED'], true)) {
                continue;
            }

            $fulfillmentOrderId = trim((string) ($node['id'] ?? ''));
            if ($fulfillmentOrderId === '') {
                continue;
            }

            $lineItemsByFulfillmentOrder[] = [
                'fulfillmentOrderId' => $fulfillmentOrderId,
            ];
        }

        if (count($lineItemsByFulfillmentOrder) === 0) {
            return ['ok' => true];
        }

        $mutation = <<<'GQL'
mutation FulfillImportedOrder($fulfillment: FulfillmentInput!) {
  fulfillmentCreate(fulfillment: $fulfillment) {
    userErrors { field message }
    fulfillment { id status }
  }
}
GQL;

        $create = $this->client->query($shop, $mutation, [
            'fulfillment' => [
                'notifyCustomer' => false,
                'lineItemsByFulfillmentOrder' => $lineItemsByFulfillmentOrder,
            ],
        ]);

        if (isset($create['errors'])) {
            return ['errors' => $create['errors']];
        }

        $userErrors = data_get($create, 'data.fulfillmentCreate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * @param array<int, array<string, mixed>>|null $customAttributes
     */
    public function updateOrderMetadata(Shop $shop, string $orderGid, array $tags = [], array $metafields = [], ?array $customAttributes = null): array
    {
        $tags = is_array($tags) ? array_values(array_filter($tags, fn ($t) => is_string($t) && trim($t) !== '')) : [];

        if (count($tags) > 0) {
            $addTags = $this->addOrderTags($shop, $orderGid, $tags);
            if (!empty($addTags['errors']) || !empty($addTags['userErrors'])) {
                // ignore
            }
        }

        if (is_array($customAttributes)) {
            $setAttributes = $this->setOrderCustomAttributes($shop, $orderGid, $customAttributes);
            if (!empty($setAttributes['errors']) || !empty($setAttributes['userErrors'])) {
                return $setAttributes;
            }
        }

        if (count($metafields) > 0) {
            $set = $this->setOrderMetafields($shop, $orderGid, $metafields);
            if (!empty($set['errors']) || !empty($set['userErrors'])) {
                return $set;
            }
        }

        return ['ok' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $customAttributes
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function setOrderCustomAttributes(Shop $shop, string $orderGid, array $customAttributes): array
    {
        $attributes = $this->mergeOrderCustomAttributes($shop, $orderGid, $customAttributes);

        $mutation = <<<'GQL'
mutation UpdateOrderCustomAttributes($input: OrderInput!) {
  orderUpdate(input: $input) {
    userErrors { field message }
    order { id }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'input' => [
                'id' => $orderGid,
                'customAttributes' => $attributes,
            ],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.orderUpdate.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Merge new attributes over existing order custom attributes (orderUpdate replaces the full set).
     *
     * @param array<int, array<string, mixed>> $customAttributes
     * @return array<int, array{key: string, value: string}>
     */
    private function mergeOrderCustomAttributes(Shop $shop, string $orderGid, array $customAttributes): array
    {
        $merged = [];

        foreach ($this->fetchOrderCustomAttributes($shop, $orderGid) as $attr) {
            $key = trim((string) ($attr['key'] ?? ''));
            $value = trim((string) ($attr['value'] ?? ''));
            if ($key === '') {
                continue;
            }
            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        foreach ($customAttributes as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $key = trim((string) ($attr['key'] ?? ''));
            $value = trim((string) ($attr['value'] ?? ''));
            if ($key === '') {
                continue;
            }

            if ($value === '') {
                // Empty value = delete this key from the merged set
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        $out = [];
        foreach ($merged as $key => $value) {
            $out[] = ['key' => $key, 'value' => $value];
        }

        return $out;
    }

    /**
     * @return array<int, array{key?: string, value?: string}>
     */
    private function fetchOrderCustomAttributes(Shop $shop, string $orderGid): array
    {
        $query = <<<'GQL'
query OrderCustomAttributes($id: ID!) {
  order(id: $id) {
    customAttributes {
      key
      value
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['id' => $orderGid]);
        if (isset($res['errors'])) {
            return [];
        }

        $attrs = data_get($res, 'data.order.customAttributes', []);

        return is_array($attrs) ? $attrs : [];
    }

    public function shopifyOrderExists(Shop $shop, string $orderGid): bool
    {
        $orderGid = trim($orderGid);
        if ($orderGid === '') {
            return false;
        }

        $query = <<<'GQL'
query OrderExists($id: ID!) {
  node(id: $id) {
    __typename
    ... on Order { id }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['id' => $orderGid]);
        if (isset($res['errors'])) {
            return true;
        }

        $type = (string) data_get($res, 'data.node.__typename', '');
        $id = (string) data_get($res, 'data.node.id', '');

        return $type === 'Order' && $id !== '';
    }

    /**
     * @param array<int, string> $tags
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function addOrderTags(Shop $shop, string $orderGid, array $tags): array
    {
        $mutation = <<<'GQL'
mutation AddTags($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    userErrors { field message }
    node { id }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'id' => $orderGid,
            'tags' => array_values($tags),
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.tagsAdd.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $metafields
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function setOrderMetafields(Shop $shop, string $orderGid, array $metafields): array
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
            if (!is_array($m)) {
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
                'ownerId' => $orderGid,
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
}
