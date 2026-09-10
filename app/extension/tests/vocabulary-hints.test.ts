import { expect, it } from 'vitest';
import { parseVocabularyHints, validVocabularyHints } from '../utils/vocabulary-hints';
import { isRuntimeMessage } from '../utils/messages';

it('accepts and normalizes per-video names while preserving spelling', () => {
  expect(parseVocabularyHints(' Marie  Curie\n\nمرحبا\r\nMarie Curie ')).toEqual(['Marie Curie', 'مرحبا']);
  expect(parseVocabularyHints('')).toEqual([]);
  expect(isRuntimeMessage({ type: 'panel.generateSubtitles', vocabularyHints: ['Marie Curie'] })).toBe(true);
});

it('rejects oversized or unsupported hints before generation', () => {
  for (const hints of [['a'.repeat(50)], Array(21).fill('term'), ['one two three four five six'], ['bad\\term'], ['<term>'], [10]]) {
    expect(validVocabularyHints(hints)).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.generateSubtitles', vocabularyHints: hints })).toBe(false);
  }
  expect(() => parseVocabularyHints('a'.repeat(50))).toThrow('49 characters');
});
