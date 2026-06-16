<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MigrationLanguageResolutionTest extends TestCase
{
    public function test_customer_language_resolution(): void
    {
        $enabledLanguages = [
            ['id' => 'lang-de', 'locale' => 'de-DE', 'name' => 'Deutsch'],
            ['id' => 'lang-en', 'locale' => 'en-US', 'name' => 'English'],
        ];

        // Case 1: Match by languageId
        $c1 = [
            'languageId' => 'lang-de',
            'language' => [
                'name' => 'Incorrect Name',
                'locale' => [
                    'code' => 'incorrect-locale'
                ]
            ]
        ];

        $customerLocale = '';
        $customerLangName = '';
        $customerLangId = trim((string) ($c1['languageId'] ?? ''));
        if ($customerLangId !== '') {
            $matched = array_filter($enabledLanguages, fn ($l) => ($l['id'] ?? '') === $customerLangId);
            $matched = array_values($matched);
            if (count($matched) > 0) {
                $customerLocale = (string) $matched[0]['locale'];
                $customerLangName = (string) ($matched[0]['name'] ?? '');
            }
        }
        if ($customerLocale === '') {
            $customerLocale = trim((string) ($c1['language']['locale']['code'] ?? $c1['language']['locale'] ?? ''));
        }
        if ($customerLangName === '') {
            $customerLangName = trim((string) ($c1['language']['name'] ?? ''));
        }

        $this->assertSame('de-DE', $customerLocale);
        $this->assertSame('Deutsch', $customerLangName);

        // Case 2: Fallback when languageId not in enabledLanguages
        $c2 = [
            'languageId' => 'lang-fr',
            'language' => [
                'name' => 'French',
                'locale' => [
                    'code' => 'fr-FR'
                ]
            ]
        ];

        $customerLocale = '';
        $customerLangName = '';
        $customerLangId = trim((string) ($c2['languageId'] ?? ''));
        if ($customerLangId !== '') {
            $matched = array_filter($enabledLanguages, fn ($l) => ($l['id'] ?? '') === $customerLangId);
            $matched = array_values($matched);
            if (count($matched) > 0) {
                $customerLocale = (string) $matched[0]['locale'];
                $customerLangName = (string) ($matched[0]['name'] ?? '');
            }
        }
        if ($customerLocale === '') {
            $customerLocale = trim((string) ($c2['language']['locale']['code'] ?? $c2['language']['locale'] ?? ''));
        }
        if ($customerLangName === '') {
            $customerLangName = trim((string) ($c2['language']['name'] ?? ''));
        }

        $this->assertSame('fr-FR', $customerLocale);
        $this->assertSame('French', $customerLangName);
    }

    public function test_newsletter_recipient_language_resolution(): void
    {
        $enabledLanguages = [
            ['id' => 'lang-de', 'locale' => 'de-DE', 'name' => 'Deutsch'],
            ['id' => 'lang-en', 'locale' => 'en-US', 'name' => 'English'],
        ];

        $recipient = [
            'languageId' => 'lang-en',
            'email' => 'test@example.com'
        ];

        $recipientLangId = trim((string) ($recipient['languageId'] ?? ''));
        $this->assertSame('lang-en', $recipientLangId);

        $matched = array_filter($enabledLanguages, fn ($l) => ($l['id'] ?? '') === $recipientLangId);
        $matched = array_values($matched);

        $this->assertCount(1, $matched);
        $this->assertSame('en-US', $matched[0]['locale']);
        $this->assertSame('English', $matched[0]['name']);
    }

    public function test_order_language_resolution(): void
    {
        $enabledLanguages = [
            ['id' => 'lang-de', 'locale' => 'de-DE', 'name' => 'Deutsch'],
            ['id' => 'lang-en', 'locale' => 'en-US', 'name' => 'English'],
        ];

        $order = [
            'languageId' => 'lang-de',
            'orderNumber' => '10001'
        ];

        $orderLangId = trim((string) ($order['languageId'] ?? ''));
        $this->assertSame('lang-de', $orderLangId);

        $matched = array_filter($enabledLanguages, fn ($l) => ($l['id'] ?? '') === $orderLangId);
        $matched = array_values($matched);

        $this->assertCount(1, $matched);
        $this->assertSame('de-DE', $matched[0]['locale']);
        $this->assertSame('Deutsch', $matched[0]['name']);
    }

    public function test_discount_language_resolution(): void
    {
        $enabledLanguages = [
            ['id' => 'lang-de', 'locale' => 'de-DE', 'name' => 'Deutsch'],
            ['id' => 'lang-en', 'locale' => 'en-US', 'name' => 'English'],
        ];

        $locales = array_map(fn ($l) => $l['locale'], $enabledLanguages);
        $imploded = implode(',', $locales);

        $this->assertSame('de-DE,en-US', $imploded);
    }
}
