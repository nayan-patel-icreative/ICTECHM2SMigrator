<?php

namespace App\Services\Migration;

use App\Models\Shop;

class CustomerPayloadMapper
{
    public function mapCustomer(array $customer, ?Shop $shop = null): array
    {
        $email = (string) ($customer['email'] ?? '');
        $firstName = (string) ($customer['firstname'] ?? '');
        $lastName = (string) ($customer['lastname'] ?? '');

        // Resolve phone number from default billing address or first address
        $rawPhone = '';
        $addresses = $customer['addresses'] ?? [];
        if (is_array($addresses) && count($addresses) > 0) {
            foreach ($addresses as $addr) {
                if (!empty($addr['default_billing']) || !empty($addr['default_shipping'])) {
                    $rawPhone = (string) ($addr['telephone'] ?? '');
                    break;
                }
            }
            if ($rawPhone === '') {
                $rawPhone = (string) ($addresses[0]['telephone'] ?? '');
            }
        }

        $noteParts = [];
        $customerId = (string) ($customer['id'] ?? '');
        if ($customerId !== '') {
            $noteParts[] = 'Magento customer ID: ' . $customerId;
        }
        $dob = (string) ($customer['dob'] ?? '');
        if ($dob !== '') {
            $noteParts[] = 'Magento Date of Birth: ' . $dob;
        }

        $phone = $this->normalizeE164Phone($rawPhone);
        if ($phone === null && trim($rawPhone) !== '') {
            $noteParts[] = 'Magento phone: ' . trim($rawPhone);
        }

        $payload = [
            'email' => $email !== '' ? $email : null,
            'firstName' => $firstName !== '' ? $firstName : null,
            'lastName' => $lastName !== '' ? $lastName : null,
            'phone' => $phone,
            'note' => count($noteParts) > 0 ? implode("\n", $noteParts) : null,
        ];

        $mappedAddresses = $this->mapAddresses($customer);
        if (count($mappedAddresses) > 0) {
            $payload['addresses'] = $mappedAddresses;
        }

        $tags = [];
        $tags[] = 'Magento';
        if (isset($customer['group_id'])) {
            $tags[] = 'magento_group_id:' . $customer['group_id'];
        }

        if (count($tags) > 0) {
            $payload['tags'] = implode(', ', $tags);
        }

        return array_filter($payload, function ($v) {
            return $v !== null;
        });
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    public function mapShopwareMetafields(array $customer, ?Shop $shop = null): array
    {
        $out = [];

        $this->pushMetafield($out, 'customer_id', (string) ($customer['id'] ?? ''));
        $this->pushMetafield($out, 'group_id', (string) ($customer['group_id'] ?? ''));
        $this->pushMetafield($out, 'dob', (string) ($customer['dob'] ?? ''));
        $this->pushMetafield($out, 'created_at', (string) ($customer['created_at'] ?? ''));
        $this->pushMetafield($out, 'updated_at', (string) ($customer['updated_at'] ?? ''));
        $this->pushMetafield($out, 'store_id', (string) ($customer['store_id'] ?? ''));

        $raw = json_encode($customer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($raw) && $raw !== '') {
            $this->pushMetafield($out, 'raw', $raw, 'json');
        }

        return $out;
    }

    private function normalizeE164Phone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Accept only E.164 format: +[1-9][0-9]{7,14}
        if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1) {
            return $phone;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapAddresses(array $customer): array
    {
        $out = [];
        $addresses = $customer['addresses'] ?? [];
        if (!is_array($addresses)) {
            return [];
        }

        foreach ($addresses as $a) {
            if (!is_array($a)) {
                continue;
            }

            $countryIso = (string) ($a['country_id'] ?? '');

            // Region/Province handling
            $province = '';
            if (isset($a['region'])) {
                if (is_array($a['region'])) {
                    $province = (string) ($a['region']['region'] ?? $a['region']['region_code'] ?? '');
                } else {
                    $province = (string) $a['region'];
                }
            }

            $street = $a['street'] ?? [];
            $address1 = '';
            $address2 = '';
            if (is_array($street)) {
                $address1 = (string) ($street[0] ?? '');
                $address2 = (string) ($street[1] ?? '');
            } else if (is_string($street)) {
                $address1 = $street;
            }

            $address = [
                'firstName' => (string) ($a['firstname'] ?? ''),
                'lastName' => (string) ($a['lastname'] ?? ''),
                'company' => (string) ($a['company'] ?? ''),
                'address1' => $address1,
                'address2' => $address2,
                'zip' => (string) ($a['postcode'] ?? ''),
                'city' => (string) ($a['city'] ?? ''),
                'phone' => $this->normalizeE164Phone((string) ($a['telephone'] ?? '')),
                'province' => $province,
                'countryCode' => $countryIso,
            ];

            $address = array_filter($address, function ($v) {
                return is_string($v) && $v !== '';
            });

            if (count($address) === 0) {
                continue;
            }

            $out[] = $address;

            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $out
     */
    private function pushMetafield(array &$out, string $key, string $value, string $type = 'single_line_text_field'): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $out[] = [
            'namespace' => 'magento',
            'key' => $key,
            'type' => $type,
            'value' => $value,
        ];
    }
}
