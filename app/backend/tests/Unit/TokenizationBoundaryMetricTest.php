<?php

namespace Tests\Unit;

use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use App\Services\TranslationAnalysis\TokenizationBoundaryMetric;
use Tests\TestCase;

class TokenizationBoundaryMetricTest extends TestCase
{
    public function test_invented_and_out_of_order_predictions_reduce_word_precision(): void
    {
        foreach ([
            [['hello', 'world', 'invented'], 2, 1, 2 / 3],
            [['invented', 'fiction'], 0, 2, 0.0],
            [['world', 'hello'], 1, 1, 0.5],
            [['hello', 'world', '!'], 2, 0, 1.0],
        ] as [$predictions, $correct, $unlocatable, $precision]) {
            $evaluation = $this->metric()->evaluate('test', 'eng', 'hello world', ['hello', 'world'], $predictions);
            $summary = $this->metric()->aggregate('eng', [$evaluation]);
            $this->assertSame($correct, $evaluation->correctWords);
            $this->assertSame($unlocatable, $evaluation->unlocatablePredictedTokens);
            $this->assertSame($unlocatable, $summary->unlocatablePredictedTokens);
            $this->assertEqualsWithDelta($precision, $summary->wordPrecision, 0.0001);
        }
    }

    public function test_perfect_segmentation_scores_full_boundary_and_word_f1(): void
    {
        $evaluation = $this->metric()->evaluate(
            'jpn-ok',
            'jpn',
            '私は毎日学校へ行きます',
            ['私', 'は', '毎日', '学校', 'へ', '行きます'],
            ['私', 'は', '毎日', '学校', 'へ', '行きます'],
        );

        $this->assertSame(5, $evaluation->truePositiveBoundaries);
        $this->assertSame(5, $evaluation->predictedBoundaryCount);
        $this->assertSame(5, $evaluation->goldBoundaryCount);
        $this->assertSame(6, $evaluation->correctWords);
        $this->assertFalse($evaluation->transcriptionFault);
        $this->assertSame(0, $evaluation->goldWordSplits);
        $this->assertSame(0, $evaluation->orphanFragments);
        $this->assertSame(0, $evaluation->truncatedWords);
        $this->assertSame(0, $evaluation->unlocatableGoldTokens);
    }

    public function test_flags_orphan_fragment_when_a_content_word_is_split_losing_a_single_mora(): void
    {
        $evaluation = $this->metric()->evaluate(
            'jpn-orphan',
            'jpn',
            '寝返りばっか',
            ['寝返り', 'ばっか'],
            ['寝返', 'り', 'ばっか'],
        );

        $this->assertSame(1, $evaluation->orphanFragments);
        $this->assertSame(1, $evaluation->goldWordSplits);
        $this->assertSame(0, $evaluation->truncatedWords);
        $this->assertSame(1, $evaluation->correctWords);
        $this->assertSame(3, $evaluation->predictedWordCount);
        $this->assertSame(2, $evaluation->goldWordCount);
    }

    public function test_flags_truncated_word_when_a_conjugation_tail_is_dropped(): void
    {
        $evaluation = $this->metric()->evaluate(
            'jpn-truncated',
            'jpn',
            'ならなくちゃ',
            ['ならなくちゃ'],
            ['ならなく'],
        );

        $this->assertSame(1, $evaluation->truncatedWords);
        $this->assertSame(0, $evaluation->goldWordSplits);
        $this->assertSame(0, $evaluation->orphanFragments);
        $this->assertSame(0, $evaluation->correctWords);
        $this->assertTrue($evaluation->goldWordCount >= 1);
    }

    public function test_flags_transcription_fault_when_gold_references_characters_absent_from_source(): void
    {
        $evaluation = $this->metric()->evaluate(
            'jpn-scribe-fault',
            'jpn',
            '寝返りうてばっか',
            ['寝返り', '打って', 'ばっか'],
            ['寝返り', 'うて', 'ばっか'],
        );

        $this->assertSame(1, $evaluation->unlocatableGoldTokens);
        $this->assertTrue($evaluation->transcriptionFault);
    }

    public function test_collapses_no_space_artifact_spaces_before_scoring_boundaries(): void
    {
        $evaluation = $this->metric()->evaluate(
            'jpn-artifacts',
            'jpn',
            '日 本 語 を 勉 強',
            ['日本語', 'を', '勉強'],
            ['日本語', 'を', '勉強'],
        );

        $this->assertSame(2, $evaluation->truePositiveBoundaries);
        $this->assertSame(3, $evaluation->correctWords);
        $this->assertFalse($evaluation->transcriptionFault);
    }

    public function test_aggregate_micro_averages_scored_cues_and_excludes_transcription_faults(): void
    {
        $metric = $this->metric();
        $perfect = $metric->evaluate('a', 'jpn', '私は学校へ行きます', ['私', 'は', '学校', 'へ', '行きます'], ['私', 'は', '学校', 'へ', '行きます']);
        $faulty = $metric->evaluate('b', 'jpn', '寝返りうて', ['寝返り', '打って'], ['寝返り', 'うて']);

        $summary = $metric->aggregate('jpn', [$perfect, $faulty]);

        $this->assertSame(2, $summary->totalCues);
        $this->assertSame(1, $summary->scoredCues);
        $this->assertSame(1, $summary->transcriptionFaultCues);
        $this->assertSame(1.0, $summary->boundaryF1);
        $this->assertSame(1.0, $summary->wordF1);
        $this->assertSame(1, $summary->unlocatableGoldTokens);
    }

    private function metric(): TokenizationBoundaryMetric
    {
        return new TokenizationBoundaryMetric(new LearningTokenOutputValidator);
    }
}
