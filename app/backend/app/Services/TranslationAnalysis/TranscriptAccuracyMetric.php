<?php

namespace App\Services\TranslationAnalysis;

use InvalidArgumentException;
use Normalizer;

class TranscriptAccuracyMetric
{
    public function score(string $reference, string $candidate, string $language): array
    {
        $characters = in_array($language, ['jpn', 'cmn', 'zho', 'yue', 'tha', 'lao', 'mya'], true);
        $gold = $this->units($reference, $characters);
        $actual = $this->units($candidate, $characters);
        if ($gold === []) {
            throw new InvalidArgumentException('Reference transcript has no letters or numbers.');
        }
        $previous = range(0, count($actual));
        foreach ($gold as $i => $unit) {
            $current = [$i + 1];
            foreach ($actual as $j => $prediction) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($unit === $prediction ? 0 : 1));
            }
            $previous = $current;
        }
        $edits = $previous[count($actual)];

        return ['metric' => $characters ? 'cer' : 'wer', 'edits' => $edits, 'referenceUnits' => count($gold), 'errorRate' => $edits / count($gold)];
    }

    private function units(string $text, bool $characters): array
    {
        $text = mb_strtolower(Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\s]/u', '', $text);
        if ($characters) {
            return preg_split('//u', preg_replace('/\s+/u', '', $text), flags: PREG_SPLIT_NO_EMPTY);
        }

        return preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY);
    }
}
