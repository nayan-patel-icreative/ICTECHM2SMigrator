<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyMarketSyncService
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch existing markets and domains from Shopify in a single GraphQL query.
     */
    public function getMarketsAndDomains(Shop $shop): array
    {
        $query = <<<'GQL'
query GetMarketsAndDomains {
  markets(first: 50) {
    edges {
      node {
        id
        name
        handle
        enabled
        regions(first: 50) {
          edges {
            node {
              ... on MarketRegionCountry {
                code
                name
              }
            }
          }
        }
        webPresences(first: 10) {
          edges {
            node {
              id
              subfolderSuffix
              defaultLocale {
                locale
              }
              domain {
                id
                host
                url
              }
            }
          }
        }
      }
    }
  }
  shop {
    domains {
      id
      host
      url
    }
    primaryDomain {
      id
      host
      url
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query);

        if (isset($res['errors'])) {
            Log::error('ShopifyMarketSyncService: Failed to fetch markets and domains', [
                'shop' => $shop->shop_domain,
                'errors' => $res['errors'],
            ]);
            return ['markets' => [], 'domains' => [], 'primaryDomain' => null];
        }

        $markets = [];
        $marketEdges = data_get($res, 'data.markets.edges', []);
        foreach ($marketEdges as $edge) {
            $node = $edge['node'] ?? null;
            if (!$node) {
                continue;
            }

            $regions = [];
            $regionEdges = data_get($node, 'regions.edges', []);
            foreach ($regionEdges as $rEdge) {
                $code = data_get($rEdge, 'node.code');
                if ($code) {
                    $regions[] = strtoupper($code);
                }
            }

            $webPresences = [];
            $wpEdges = data_get($node, 'webPresences.edges', []);
            foreach ($wpEdges as $wpEdge) {
                $wpNode = $wpEdge['node'] ?? null;
                if ($wpNode) {
                    $webPresences[] = [
                        'id' => $wpNode['id'],
                        'subfolderSuffix' => $wpNode['subfolderSuffix'],
                        'defaultLocale' => data_get($wpNode, 'defaultLocale.locale'),
                        'domainId' => data_get($wpNode, 'domain.id'),
                        'host' => data_get($wpNode, 'domain.host'),
                    ];
                }
            }

            $markets[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'handle' => $node['handle'],
                'enabled' => (bool) $node['enabled'],
                'regions' => $regions,
                'webPresences' => $webPresences,
            ];
        }

        $domains = [];
        $shopDomains = data_get($res, 'data.shop.domains', []);
        foreach ($shopDomains as $node) {
            if ($node) {
                $domains[] = [
                    'id' => $node['id'],
                    'host' => $node['host'],
                    'url' => $node['url'],
                ];
            }
        }

        $primaryDomain = data_get($res, 'data.shop.primaryDomain');

        return [
            'markets' => $markets,
            'domains' => $domains,
            'primaryDomain' => $primaryDomain,
        ];
    }

    /**
     * Sync a single Magento Store View to a Shopify Market and Web Presence.
     * Handles force-reassigning of country code if it already belongs to another market.
     */
    public function syncMarket(Shop $shop, array $salesChannel): array
    {
        $name = trim($salesChannel['name']);
        $defaultLocale = $salesChannel['locale'] ?? 'en-US';
        $defaultCountry = null;
        if (str_contains($defaultLocale, '-')) {
            $parts = explode('-', $defaultLocale);
            $defaultCountry = strtoupper(trim(end($parts)));
        }

        Log::info('ShopifyMarketSyncService: Syncing sales channel to market', [
            'shop' => $shop->shop_domain,
            'name' => $name,
            'default_country' => $defaultCountry,
        ]);

        // 1. Fetch current markets and domains
        $state = $this->getMarketsAndDomains($shop);
        $markets = $state['markets'];
        $domains = $state['domains'];

        // 2. Find if market already exists by name
        $targetMarket = null;
        foreach ($markets as $m) {
            if (strtolower($m['name']) === strtolower($name)) {
                $targetMarket = $m;
                break;
            }
        }

        // 3. Resolve country collision if creating/updating and defaultCountry is provided
        if ($defaultCountry) {
            $collisionMarket = null;
            foreach ($markets as $m) {
                // If targetMarket already owns it, no collision
                if ($targetMarket && $targetMarket['id'] === $m['id']) {
                    continue;
                }
                if (in_array($defaultCountry, $m['regions'], true)) {
                    $collisionMarket = $m;
                    break;
                }
            }

            if ($collisionMarket) {
                Log::info('ShopifyMarketSyncService: Country collision detected. Attempting to force-reassign country code', [
                    'country' => $defaultCountry,
                    'colliding_market' => $collisionMarket['name'],
                ]);

                // Remove country from colliding market
                $remainingRegions = array_filter($collisionMarket['regions'], fn($r) => $r !== $defaultCountry);
                $remainingRegionsInput = array_map(fn($r) => ['countryCode' => $r], array_values($remainingRegions));

                $updateRes = $this->updateMarketRegions($shop, $collisionMarket['id'], $remainingRegionsInput);
                if (isset($updateRes['errors']) || !empty($updateRes['userErrors'])) {
                    Log::warning('ShopifyMarketSyncService: Failed to remove colliding country from source market', [
                        'market' => $collisionMarket['name'],
                        'result' => $updateRes,
                    ]);
                    // Continue anyway, Shopify will throw validation error if it is still locked
                } else {
                    Log::info('ShopifyMarketSyncService: Successfully removed country from colliding market', [
                        'country' => $defaultCountry,
                        'market' => $collisionMarket['name'],
                    ]);
                }
            }
        }

        // 4. Create or update Market
        $marketId = null;
        if ($targetMarket) {
            $marketId = $targetMarket['id'];
            Log::info('ShopifyMarketSyncService: Market already exists', ['market_id' => $marketId]);
        } else {
            // Create new Market
            $regions = [];
            if ($defaultCountry) {
                $regions[] = ['countryCode' => $defaultCountry];
            }

            $createMutation = <<<'GQL'
mutation MarketCreate($input: MarketCreateInput!) {
  marketCreate(input: $input) {
    market {
      id
      name
    }
    userErrors {
      field
      message
      code
    }
  }
}
GQL;

            $input = [
                'name' => $name,
            ];

            if (!empty($regions)) {
                $input['conditions'] = [
                    'regionsCondition' => [
                        'regions' => $regions,
                    ],
                ];
            }

            $res = $this->client->query($shop, $createMutation, ['input' => $input]);

            if (isset($res['errors'])) {
                return [
                    'ok' => false,
                    'error' => 'GraphQL error creating market: ' . json_encode($res['errors']),
                ];
            }

            $userErrors = data_get($res, 'data.marketCreate.userErrors', []);
            if (!empty($userErrors)) {
                return [
                    'ok' => false,
                    'error' => 'Shopify error creating market: ' . $userErrors[0]['message'],
                    'userErrors' => $userErrors,
                ];
            }

            $marketId = data_get($res, 'data.marketCreate.market.id');
            Log::info('ShopifyMarketSyncService: Market created successfully', ['market_id' => $marketId]);
        }

        // 5. Build and upsert Web Presence (URL configuration)
        if (!$marketId) {
            return [
                'ok' => false,
                'error' => 'Market ID could not be resolved.',
            ];
        }

        // Extract subfolder suffix or custom domain from Magento store views
        $webPresenceInput = $this->buildWebPresenceInput($salesChannel, $domains, $defaultLocale);
        $currentWebPresenceId = null;
        if (is_array($targetMarket) && isset($targetMarket['webPresences']) && is_array($targetMarket['webPresences'])) {
            foreach ($targetMarket['webPresences'] as $wp) {
                if (!is_array($wp)) {
                    continue;
                }
                $currentWebPresenceId = trim((string) ($wp['id'] ?? ''));
                if ($currentWebPresenceId !== '') {
                    break;
                }
            }
        }

        Log::info('ShopifyMarketSyncService: Upserting web presence', [
            'market_id' => $marketId,
            'web_presence_id' => $currentWebPresenceId,
            'input' => $webPresenceInput,
        ]);

        $wpError = $this->upsertWebPresence($shop, $marketId, $currentWebPresenceId, $webPresenceInput);

        if ($wpError) {
            Log::warning('ShopifyMarketSyncService: Web presence creation failed, continuing to activate market without custom URL configuration', [
                'error' => $wpError,
            ]);
        }

        // 6. Enable market if not already enabled
        $enableMutation = <<<'GQL'
mutation MarketUpdate($id: ID!, $input: MarketUpdateInput!) {
  marketUpdate(id: $id, input: $input) {
    market {
      id
      enabled
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $enableRes = $this->client->query($shop, $enableMutation, [
            'id' => $marketId,
            'input' => [
                'status' => 'ACTIVE',
            ],
        ]);

        if (isset($enableRes['errors']) || !empty(data_get($enableRes, 'data.marketUpdate.userErrors'))) {
            Log::warning('ShopifyMarketSyncService: Failed to activate market', [
                'market_id' => $marketId,
                'result' => $enableRes,
            ]);
        } else {
            Log::info('ShopifyMarketSyncService: Market activated successfully', [
                'market_id' => $marketId,
            ]);
        }

        $resPayload = [
            'ok' => true,
            'market_id' => $marketId,
        ];
        if ($wpError !== null) {
            $resPayload['warning'] = $wpError;
        }
        return $resPayload;
    }

    private function upsertWebPresence(Shop $shop, string $marketId, ?string $webPresenceId, array $webPresenceInput): ?string
    {
        $webPresenceId = is_string($webPresenceId) ? trim($webPresenceId) : '';

        // Existing web presence: update directly (2026-04 API shape).
        if ($webPresenceId !== '') {
            $mutation = <<<'GQL'
mutation WebPresenceUpdate($id: ID!, $input: WebPresenceUpdateInput!) {
  webPresenceUpdate(id: $id, input: $input) {
    userErrors { field message }
    webPresence { id }
  }
}
GQL;

            $res = $this->client->query($shop, $mutation, [
                'id' => $webPresenceId,
                'input' => $webPresenceInput,
            ]);

            if (isset($res['errors'])) {
                return 'GraphQL error updating web presence: ' . json_encode($res['errors']);
            }

            $userErrors = data_get($res, 'data.webPresenceUpdate.userErrors', []);
            if (is_array($userErrors) && count($userErrors) > 0) {
                return 'Shopify error updating web presence: ' . (string) ($userErrors[0]['message'] ?? 'Unknown error');
            }

            return null;
        }

        // New web presence: create, then attach to market via marketUpdate(webPresencesToAdd).
        $createMutation = <<<'GQL'
mutation WebPresenceCreate($input: WebPresenceCreateInput!) {
  webPresenceCreate(input: $input) {
    userErrors { field message }
    webPresence { id }
  }
}
GQL;

        $createRes = $this->client->query($shop, $createMutation, [
            'input' => $webPresenceInput,
        ]);

        if (isset($createRes['errors'])) {
            return 'GraphQL error creating web presence: ' . json_encode($createRes['errors']);
        }

        $createUserErrors = data_get($createRes, 'data.webPresenceCreate.userErrors', []);
        if (is_array($createUserErrors) && count($createUserErrors) > 0) {
            return 'Shopify error creating web presence: ' . (string) ($createUserErrors[0]['message'] ?? 'Unknown error');
        }

        $newWebPresenceId = trim((string) data_get($createRes, 'data.webPresenceCreate.webPresence.id', ''));
        if ($newWebPresenceId === '') {
            return 'Shopify webPresenceCreate did not return a web presence id';
        }

        $attachMutation = <<<'GQL'
mutation MarketAttachWebPresence($id: ID!, $input: MarketUpdateInput!) {
  marketUpdate(id: $id, input: $input) {
    market { id }
    userErrors { field message }
  }
}
GQL;

        $attachRes = $this->client->query($shop, $attachMutation, [
            'id' => $marketId,
            'input' => [
                'webPresencesToAdd' => [$newWebPresenceId],
            ],
        ]);

        if (isset($attachRes['errors'])) {
            return 'GraphQL error attaching web presence to market: ' . json_encode($attachRes['errors']);
        }

        $attachUserErrors = data_get($attachRes, 'data.marketUpdate.userErrors', []);
        if (is_array($attachUserErrors) && count($attachUserErrors) > 0) {
            return 'Shopify error attaching web presence to market: ' . (string) ($attachUserErrors[0]['message'] ?? 'Unknown error');
        }

        return null;
    }

    /**
     * Check whether a Shopify market GID still exists.
     * Returns true if the market is found, false if deleted or inaccessible.
     */
    public function marketExists(Shop $shop, string $marketGid): bool
    {
        $query = <<<'GQL'
query MarketExists($id: ID!) {
  market(id: $id) {
    id
  }
}
GQL;
        $res = $this->client->query($shop, $query, ['id' => $marketGid]);
        return data_get($res, 'data.market.id') !== null;
    }

    /**
     * Update conditions/regions for a market.
     */
    private function updateMarketRegions(Shop $shop, string $marketId, array $regions): array
    {
        $mutation = <<<'GQL'
mutation MarketUpdate($id: ID!, $input: MarketUpdateInput!) {
  marketUpdate(id: $id, input: $input) {
    market {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $input = [
            'conditions' => [
                'regionsCondition' => [
                    'regions' => $regions,
                ],
            ],
        ];

        return $this->client->query($shop, $mutation, [
            'id' => $marketId,
            'input' => $input,
        ]);
    }

    /**
     * Build input payload for webPresenceCreate / webPresenceUpdate.
     * Collects the default locale plus any additional locales from Magento store views
     * and maps them to Shopify's defaultLocale + alternateLocales fields.
     */
    private function buildWebPresenceInput(array $salesChannel, array $shopifyDomains, string $defaultLocale): array
    {
        $normalizedDefault = $this->normalizeLocale($defaultLocale);

        $input = [
            'defaultLocale' => $normalizedDefault,
        ];

        // Use store view code as subfolder suffix candidate
        $extractedSuffix = trim((string) ($salesChannel['code'] ?? ''));
        if ($extractedSuffix === '') {
            $extractedSuffix = Str::slug($salesChannel['name']);
        }

        // Clean suffix to be strictly alphanumeric + hyphens
        $extractedSuffix = preg_replace('/[^a-z0-9-]/i', '', $extractedSuffix);
        if ($extractedSuffix === '') {
            $extractedSuffix = 'market-' . substr(md5($salesChannel['name']), 0, 5);
        }

        $input['subfolderSuffix'] = $extractedSuffix;

        return $input;
    }

    /**
     * Map Magento locale codes (e.g. de-DE, en-GB) to Shopify format.
     */
    private function normalizeLocale(string $locale): string
    {
        $locale = str_replace('_', '-', $locale);
        // Shopify locales are lowercase, e.g. en, de, fr-ca, en-us
        return strtolower($locale);
    }
}
