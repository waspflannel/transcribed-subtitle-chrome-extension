<?php

namespace App\Services\Languages;

use JsonException;
use RuntimeException;

class LanguageCatalog
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $languages = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $languagesByCode = null;

    /**
     * @var array<string, string>|null
     */
    private static ?array $aliases = null;

    /**
     * @return array<int, string>
     */
    public static function sourceLanguageCodes(): array
    {
        return array_map(
            fn (array $language): string => (string) $language['code'],
            self::languages(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function targetLanguageCodes(): array
    {
        return array_values(array_map(
            fn (array $language): string => (string) $language['code'],
            array_filter(
                self::languages(),
                fn (array $language): bool => ($language['sourceOnly'] ?? false) !== true,
            ),
        ));
    }

    public static function label(string $code): string
    {
        return (string) (self::languagesByCode()[$code]['label'] ?? $code);
    }

    public static function normalizeCode(?string $code): ?string
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $normalized = strtolower(trim(str_replace('_', '-', $code)));
        $primary = explode('-', $normalized)[0] ?? $normalized;

        foreach ([$normalized, $primary] as $candidate) {
            $language = self::languagesByCode()[$candidate] ?? null;

            if (is_array($language) && ($language['sourceOnly'] ?? false) !== true) {
                return $candidate;
            }

            if (isset(self::aliases()[$candidate])) {
                return self::aliases()[$candidate];
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function languages(): array
    {
        if (self::$languages !== null) {
            return self::$languages;
        }

        $path = base_path('../../packages/contracts/languages.json');
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read language catalog.');
        }

        try {
            $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to parse language catalog.', previous: $exception);
        }

        if (! is_array($catalog) || ! is_array($catalog['languages'] ?? null)) {
            throw new RuntimeException('Language catalog is missing languages.');
        }

        self::$languages = array_values($catalog['languages']);

        return self::$languages;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function languagesByCode(): array
    {
        if (self::$languagesByCode !== null) {
            return self::$languagesByCode;
        }

        self::$languagesByCode = [];

        foreach (self::languages() as $language) {
            self::$languagesByCode[(string) $language['code']] = $language;
        }

        return self::$languagesByCode;
    }

    /**
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        self::$aliases = [];

        foreach (self::languages() as $language) {
            if (! is_array($language['aliases'] ?? null)) {
                continue;
            }

            foreach ($language['aliases'] as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    self::$aliases[strtolower($alias)] = (string) $language['code'];
                }
            }
        }

        return self::$aliases;
    }
}
