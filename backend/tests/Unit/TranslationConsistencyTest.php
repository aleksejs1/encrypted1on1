<?php

namespace App\Tests\Unit;

use App\Anketa\AnketaAlreadyArchivedException;
use App\Anketa\AnketaTopicsChangedException;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class TranslationConsistencyTest extends TestCase
{
    private const LOCALES = User::SUPPORTED_LOCALES;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadAllTranslations(): array
    {
        $dir = __DIR__.'/../../translations';
        $translations = [];

        foreach (self::LOCALES as $locale) {
            $path = "{$dir}/messages.{$locale}.yaml";
            self::assertFileExists($path, "Translation file for locale '{$locale}' must exist.");
            $parsed = Yaml::parseFile($path);
            self::assertIsArray($parsed, "Translation file '{$path}' must parse to an array.");
            $translations[$locale] = $parsed;
        }

        return $translations;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $fullKey = '' !== $prefix ? "{$prefix}.{$key}" : $key;
            if (\is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }
        sort($keys);

        return $keys;
    }

    public function testAllTranslationFilesHaveSameKeysAsEnglish(): void
    {
        $translations = $this->loadAllTranslations();
        $englishKeys = $this->flattenKeys($translations['en']);

        foreach (self::LOCALES as $locale) {
            if ('en' === $locale) {
                continue;
            }

            $localeKeys = $this->flattenKeys($translations[$locale]);
            $missingInLocale = array_values(array_diff($englishKeys, $localeKeys));
            $extraInLocale = array_values(array_diff($localeKeys, $englishKeys));

            self::assertSame([], $missingInLocale, "Locale '{$locale}' is missing keys present in English.");
            self::assertSame([], $extraInLocale, "Locale '{$locale}' has extra keys not present in English.");
        }
    }

    public function testNoEmptyTranslationValues(): void
    {
        $translations = $this->loadAllTranslations();

        foreach (self::LOCALES as $locale) {
            $this->assertNoEmptyStrings($translations[$locale], $locale);
        }
    }

    public function testInternalServerErrorKeyExistsInAllLocales(): void
    {
        $translations = $this->loadAllTranslations();

        foreach (self::LOCALES as $locale) {
            self::assertArrayHasKey('errors', $translations[$locale]);
            self::assertArrayHasKey(
                'internal_server_error',
                $translations[$locale]['errors'],
                "Locale '{$locale}' must define 'errors.internal_server_error'."
            );
            self::assertNotEmpty(
                $translations[$locale]['errors']['internal_server_error'],
                "Locale '{$locale}' has empty 'errors.internal_server_error'."
            );
        }
    }

    /**
     * GitHub issue #195: the product's object is a "1:1" in every locale;
     * "anketa" survives only in keys, identifiers and routes. The other words
     * are the questionnaire terms es/de/fr used before the rename.
     */
    public function testNoTranslationCallsA1on1AnAnketa(): void
    {
        $translations = $this->loadAllTranslations();

        foreach (self::LOCALES as $locale) {
            self::assertSame([], $this->keysMatching($translations[$locale], '/anket|анкет|cuestionario|fragebogen|questionnaire/iu'), "Locale '{$locale}' still says \"anketa\" in these values.");
        }
    }

    public function testAlreadyArchivedExceptionMatchesEnglishTranslation(): void
    {
        $translations = $this->loadAllTranslations();

        self::assertSame($translations['en']['errors']['anketa_archived'], (new AnketaAlreadyArchivedException())->getMessage());
        self::assertSame($translations['en']['errors']['topics_conflict'], (new AnketaTopicsChangedException())->getMessage());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string> dotted keys whose value matches $pattern
     */
    private function keysMatching(array $data, string $pattern, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $fullKey = '' !== $prefix ? "{$prefix}.{$key}" : $key;
            if (\is_array($value)) {
                $keys = array_merge($keys, $this->keysMatching($value, $pattern, $fullKey));
            } elseif (\is_string($value) && 1 === preg_match($pattern, $value)) {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNoEmptyStrings(array $data, string $locale, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $fullKey = '' !== $prefix ? "{$prefix}.{$key}" : $key;
            if (\is_array($value)) {
                $this->assertNoEmptyStrings($value, $locale, $fullKey);
            } else {
                self::assertIsString($value, "Key '{$fullKey}' in '{$locale}' must be a string.");
                self::assertNotEmpty(trim($value), "Key '{$fullKey}' in '{$locale}' must not be empty.");
            }
        }
    }
}
