<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Environment for external tools (yt-dlp, its JavaScript runtime, ffmpeg).
 * Symfony Process merges the full parent environment into any env array and
 * treats false as "unset", so every inherited variable is set to false first.
 * Only platform basics and a private temp directory reach the child, never
 * APP_KEY, database passwords or provider keys.
 */
final class ChildProcessEnvironment
{
    private const INHERITED = ['SystemRoot', 'WINDIR', 'COMSPEC', 'Path', 'PATH', 'PATHEXT', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA', 'PROGRAMDATA'];

    /**
     * @param  array<string, string|false>  $variables  Extra values; they override the defaults.
     * @return array<string, string|false>
     */
    public static function isolated(string $tempDirectory, array $variables = []): array
    {
        File::ensureDirectoryExists($tempDirectory, 0700);

        $environment = array_fill_keys(array_keys($_ENV + $_SERVER + getenv()), false);

        foreach (self::INHERITED as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return [
            ...$environment,
            'TEMP' => $tempDirectory,
            'TMP' => $tempDirectory,
            'TMPDIR' => $tempDirectory,
            ...$variables,
        ];
    }
}
