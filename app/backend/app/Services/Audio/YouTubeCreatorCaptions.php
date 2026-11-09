<?php

namespace App\Services\Audio;

use App\Models\SubtitleJob;
use App\Services\Languages\LanguageCatalog;
use App\Support\ChildProcessEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class YouTubeCreatorCaptions
{
    public function fetch(SubtitleJob $job, ?string $sourceLanguage = null): ?array
    {
        if (! $job->use_creator_captions) {
            return null;
        }
        $directory = rtrim((string) config('subtitles.youtube.temp_directory'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'captions-'.Str::uuid();
        try {
            File::ensureDirectoryExists($directory, 0700);
            $command = [(string) config('subtitles.youtube.binary'), '--ignore-config', '--no-playlist', '--skip-download'];
            $run = fn (array $arguments) => Process::timeout(20)->env(ChildProcessEnvironment::isolated($directory.'/process-temp'))->run([...$command, ...$arguments, '--', $job->youtube_url]);
            $metadata = $run(['--dump-single-json', '--no-warnings']);
            if ($metadata->failed()) {
                return null;
            }
            $info = json_decode($metadata->output(), true, flags: JSON_THROW_ON_ERROR);
            $source = $sourceLanguage ?? ($job->source_language === 'auto' ? LanguageCatalog::normalizeCode($info['language'] ?? null) : $job->source_language);
            if ($source === null) {
                return empty($info['subtitles']) ? null : ['deferred' => true];
            }
            foreach (array_keys($info['subtitles'] ?? []) as $tag) {
                if (LanguageCatalog::normalizeCode($tag) !== $source || ! preg_match('/^[a-zA-Z0-9-]+$/', $tag)) {
                    continue;
                }
                $download = $run(['--write-subs', '--no-write-auto-subs', '--sub-langs', '^'.$tag.'$', '--sub-format', 'vtt', '--paths', $directory, '--output', 'captions.%(ext)s']);
                if ($download->failed()) {
                    return null;
                }
                foreach (glob($directory.'/captions*.vtt') as $path) {
                    if (filesize($path) > 1_000_000) {
                        return null;
                    }
                    $text = $this->parse(file_get_contents($path));
                    if ($text !== '') {
                        return ['language' => $source, 'text' => $text];
                    }
                }
            }
        } catch (Throwable) {
            Log::info('backend.creator_captions_unavailable', ['job_id' => $job->public_id]);
        } finally {
            File::deleteDirectory($directory);
        }

        return null;
    }

    public function parse(string $vtt): string
    {
        if (! str_starts_with(ltrim($vtt, "\xEF\xBB\xBF \r\n\t"), 'WEBVTT')) {
            return '';
        }
        $lines = [];
        foreach (preg_split('/\R\s*\R/u', trim($vtt)) as $block) {
            if (preg_match('/^(?:WEBVTT|NOTE|STYLE|REGION)(?:\s|$)/u', ltrim($block))) {
                continue;
            }
            if (preg_match('/(?:^|\n)[^\n]*-->[^\n]*\n(.*)/s', $block, $match)) {
                $text = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim(preg_replace('/\s+/u', ' ', $text));
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }

        $text = implode("\n", $lines);

        return mb_strlen($text) <= 100000 ? $text : '';
    }
}
