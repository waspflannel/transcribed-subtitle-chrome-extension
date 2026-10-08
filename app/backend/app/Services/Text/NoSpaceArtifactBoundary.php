<?php

namespace App\Services\Text;

/**
 * Removes the artifact whitespace that Scribe inserts between adjacent
 * no-space-script characters (CJK, kana, Thai, Lao, Khmer, Burmese).
 *
 * The producer of sourceText (ScribeTranscriptNormalizer) and the comparer
 * that re-derives canonical text from it (LearningTokenOutputValidator) must
 * apply the exact same rule or token alignment silently breaks. This class is
 * the single owner of that rule.
 */
final class NoSpaceArtifactBoundary
{
    /**
     * Unicode ranges for scripts written without inter-word spaces
     * (CJK including 々〆〇 and supplementary ideographs, kana, halfwidth
     * kana and sound marks, Thai, Lao, Khmer, Burmese).
     * Consumed by segmentation, artifact stripping, and validation so they
     * share one definition of "no-space script".
     */
    public const SCRIPT_CLASS = '\x{3005}-\x{3007}\x{303B}\x{303C}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF66}-\x{FF9F}\x{20000}-\x{2FA1F}\x{0E00}-\x{0E7F}\x{0E80}-\x{0EFF}\x{1780}-\x{17FF}\x{1000}-\x{109F}';

    /**
     * Matches one or more whitespace characters framed by no-space-script
     * characters or punctuation/symbols, so only inter-character artifact
     * spaces are collapsed (a real space between Latin words is preserved).
     */
    public const PATTERN = '/(?<=['.self::SCRIPT_CLASS.'\p{P}\p{S}])\s+(?=['.self::SCRIPT_CLASS.'\p{P}\p{S}])/u';

    /**
     * Strip artifact-boundary whitespace from already whitespace-collapsed text.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace(self::PATTERN, '', $text);
    }

    /**
     * True when the character belongs to a no-space script, so callers can
     * stop treating single Scribe per-character "words" as logical words.
     */
    public static function isNoSpaceScriptChar(string $char): bool
    {
        return preg_match('/^['.self::SCRIPT_CLASS.']/u', $char) === 1;
    }
}
