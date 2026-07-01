<?php

namespace App\Services\Text;

/**
 * Single owner of the two canonical text operations every subtitle pipeline
 * stage agrees on:
 *   - collapseWhitespace: trim + collapse internal runs of whitespace to one
 *     space (the form producer code stores and the UI renders).
 *   - canonicalComparable: collapseWhitespace + strip Scribe artifact spaces
 *     between no-space-script characters (the form the validator compares
 *     against, so producer and comparer cannot drift).
 *
 * NoSpaceArtifactBoundary owns the strip rule; this class composes it.
 */
final class SubtitleText
{
    public static function collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function canonicalComparable(string $text): string
    {
        return NoSpaceArtifactBoundary::strip(self::collapseWhitespace($text));
    }
}
