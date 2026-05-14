<?php

namespace App\Services\Languages;

use Illuminate\Support\Str;
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
        $codes = [];

        foreach (self::languages() as $language) {
            $codes[] = self::languageCode($language);
        }

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    public static function targetLanguageCodes(): array
    {
        $codes = [];

        foreach (self::languages() as $language) {
            if (self::isSourceOnly($language)) {
                continue;
            }

            $codes[] = self::languageCode($language);
        }

        return $codes;
    }

    public static function label(string $code): string
    {
        $language = self::languagesByCode()[$code] ?? null;

        if (! is_array($language) || ! isset($language['label'])) {
            return $code;
        }

        return (string) $language['label'];
    }

    public static function normalizeCode(?string $code): ?string
    {
        foreach (self::codeCandidates($code) as $candidate) {
            $normalizedCode = self::normalizeCandidate($candidate);

            if ($normalizedCode !== null) {
                return $normalizedCode;
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

        self::$languages = self::loadLanguages();

        return self::$languages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadLanguages(): array
    {
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

        return array_values($catalog['languages']);
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

    /**
     * @param  array<string, mixed>  $language
     */
    private static function languageCode(array $language): string
    {
        return (string) $language['code'];
    }

    /**
     * @param  array<string, mixed>  $language
     */
    private static function isSourceOnly(array $language): bool
    {
        return ($language['sourceOnly'] ?? false) === true;
    }

    /**
     * @return array<int, string>
     */
    private static function codeCandidates(?string $code): array
    {
        $normalizedCode = self::normalizeInputCode($code);

        if ($normalizedCode === null) {
            return [];
        }

        $primaryCode = Str::before($normalizedCode, '-');

        if ($primaryCode === $normalizedCode) {
            return [$normalizedCode];
        }

        return [$normalizedCode, $primaryCode];
    }

    private static function normalizeInputCode(?string $code): ?string
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return Str::of($code)
            ->trim()
            ->replace('_', '-')
            ->lower()
            ->toString();
    }

    private static function normalizeCandidate(string $candidate): ?string
    {
        $language = self::languagesByCode()[$candidate] ?? null;

        if (is_array($language) && ! self::isSourceOnly($language)) {
            return $candidate;
        }

        return self::aliases()[$candidate] ?? null;
    }
}
