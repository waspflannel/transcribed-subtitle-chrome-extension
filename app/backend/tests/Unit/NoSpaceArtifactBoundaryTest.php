<?php

namespace Tests\Unit;

use App\Services\Text\NoSpaceArtifactBoundary;
use Tests\TestCase;

class NoSpaceArtifactBoundaryTest extends TestCase
{
    public function test_strips_artifact_space_between_no_space_characters(): void
    {
        $this->assertSame("\u{65E5}\u{672C}\u{8A9E}", NoSpaceArtifactBoundary::strip("\u{65E5} \u{672C} \u{8A9E}"));
    }

    public function test_preserves_real_space_between_latin_words(): void
    {
        $this->assertSame('hello world', NoSpaceArtifactBoundary::strip('hello world'));
    }

    public function test_strips_spaces_around_iteration_marks_and_supplementary_ideographs(): void
    {
        $this->assertSame('時々人々様々な二〇二〇〆切𠮷野家ｶﾞ', NoSpaceArtifactBoundary::strip('時 々 人 々 様 々 な 二 〇 二 〇 〆 切 𠮷 野 家 ｶ ﾞ'));
    }

    public function test_only_collapses_inter_character_artifact_spaces(): void
    {
        $this->assertSame("\u{65E5}\u{672C} hello \u{4E16}\u{754C}", NoSpaceArtifactBoundary::strip("\u{65E5} \u{672C} hello \u{4E16} \u{754C}"));
    }
}
