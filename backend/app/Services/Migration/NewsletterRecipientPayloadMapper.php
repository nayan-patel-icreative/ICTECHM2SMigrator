<?php

namespace App\Services\Migration;

use App\Models\Shop;

class NewsletterRecipientPayloadMapper
{
    /**
     * @return array{email: string|null, firstName?: string|null, lastName?: string|null, emailMarketingConsent: array<string, mixed>, tags?: string}
     */
    public function mapToShopifyCustomerPayload(array $recipient, bool $subscribe, ?Shop $shop = null): array
    {
        $email = trim((string) ($recipient['email'] ?? ''));
        $firstName = trim((string) ($recipient['firstName'] ?? ''));
        $lastName = trim((string) ($recipient['lastName'] ?? ''));
        $tags = ['magento_newsletter'];

        if ($shop) {
            $assignments = app(StateAssignmentMapper::class);
            $mappedSalutation = $assignments->mappedValue($shop, 'salutations', $this->salutationKey($recipient));
            if (is_string($mappedSalutation) && $mappedSalutation !== '') {
                $tags[] = 'magento_salutation:'.$assignments->optionLabel('salutations', $mappedSalutation);
            }
        }

        $payload = [
            'email' => $email !== '' ? $email : null,
            'firstName' => $firstName !== '' ? $firstName : null,
            'lastName' => $lastName !== '' ? $lastName : null,
            'emailMarketingConsent' => [
                'marketingState' => $subscribe ? 'SUBSCRIBED' : 'UNSUBSCRIBED',
                'marketingOptInLevel' => 'CONFIRMED_OPT_IN',
            ],
            'tags' => implode(', ', $tags),
        ];

        return array_filter($payload, function ($v) {
            return $v !== null;
        });
    }

    public function isActiveRecipient(array $recipient): bool
    {
        if (array_key_exists('active', $recipient)) {
            return (bool) $recipient['active'];
        }

        $status = strtolower(trim((string) ($recipient['status'] ?? '')));
        if ($status === '') {
            return false;
        }

        return in_array($status, ['active', 'instantly active', 'direct', 'optin', 'opt_in', 'subscribed'], true);
    }

    public function email(array $recipient): string
    {
        return trim((string) ($recipient['email'] ?? ''));
    }

    private function salutationKey(array $recipient): string
    {
        $key = (string) (data_get($recipient, 'salutation.salutationKey')
            ?: data_get($recipient, 'salutation.technicalName')
            ?: data_get($recipient, 'salutation.translated.letterName')
            ?: data_get($recipient, 'salutation.displayName')
            ?: data_get($recipient, 'salutation.translated.displayName')
            ?: data_get($recipient, 'salutation')
            ?: '');

        return strtolower(trim($key));
    }
}
