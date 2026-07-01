<?php

namespace Tests\Unit;

use App\Services\Text\NoSpaceArtifactBoundary;
use App\Services\Text\SubtitleText;
use Tests\TestCase;

class SubtitleTextTest extends TestCase
{
    public function test_collapses_internal_whitespace_and_trims(): void
    {
        $this->assertSame('Hello world', SubtitleText::collapseWhitespace("  Hello\n  world "));
    }

    public function test_canonical_comparable_strips_no_space_artifact_spaces(): void
    {
        $this->assertSame(
            "\u{65E5}\u{672C}\u{8A9E}",
            SubtitleText::canonicalComparable("\u{65E5} \u{672C} \u{8A9E}"),
        );
    }

    public function test_canonical_comparable_preserves_real_spaces_between_spaced_words(): void
    {
        $this->assertSame('Hello world', SubtitleText::canonicalComparable('Hello  world'));
    }

    public function test_canonical_matches_legacy_stripping_implementation(): void
    {
        // Pins the equivalence the NoSpaceArtifactBoundary docblock warns about:
        // producer (canonical) and comparer (canonical + lowercase) must agree
        // on artifact stripping forever.
        $text = "\u{65E5} \u{672C}\u{3002} hello ";

        $this->assertSame(
            NoSpaceArtifactBoundary::strip(trim((string) preg_replace('/\s+/u', ' ', $text))),
            SubtitleText::canonicalComparable($text),
        );
    }
}
