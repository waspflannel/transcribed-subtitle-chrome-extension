<?php

namespace App\Services\Text;

/**
 * Removes the artifact whitespace that Scribe inserts between adjacent
 * no-space-script characters (CJK, kana, Hangul, Thai, Lao, Khmer, Burmese).
 *
 * The producer of sourceText (ScribeTranscriptNormalizer) and the comparer
 * that re-derives canonical text from it (LearningTokenOutputValidator) must
 * apply the exact same rule or token alignment silently breaks. This class is
 * the single owner of that rule.
 */
final class NoSpaceArtifactBoundary
{
    /**
     * Matches one or more whitespace characters framed by no-space-script
     * characters or punctuation/symbols, so only inter-character artifact
     * spaces are collapsed (a real space between Latin words is preserved).
     */
    public const PATTERN = '/(?<=[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}\x{AC00}-\x{D7AF}\x{FF66}-\x{FF9D}\x{0E00}-\x{0E7F}\x{0E80}-\x{0EFF}\x{1780}-\x{17FF}\x{1000}-\x{109F}\p{P}\p{S}])\s+(?=[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}\x{AC00}-\x{D7AF}\x{FF66}-\x{FF9D}\x{0E00}-\x{0E7F}\x{0E80}-\x{0EFF}\x{1780}-\x{17FF}\x{1000}-\x{109F}\p{P}\p{S}])/u';

    /**
     * Strip artifact-boundary whitespace from already whitespace-collapsed text.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace(self::PATTERN, '', $text);
    }
}
