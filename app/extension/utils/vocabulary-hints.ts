export function validVocabularyHints(value: unknown): value is string[] {
  return Array.isArray(value) && value.length <= 20 && value.every((hint) =>
    typeof hint === 'string' && [...hint].length <= 49
    && /^[^<>{}\[\]\\\s]+(?:\s+[^<>{}\[\]\\\s]+){0,4}$/u.test(hint));
}

export function parseVocabularyHints(text: string): string[] {
  const hints = [...new Set(text.split(/\r?\n/).map((line) => line.trim().replace(/\s+/gu, ' ')).filter(Boolean))];
  if (!validVocabularyHints(hints)) {
    throw new Error('Use up to 20 terms, one per line, with at most 5 words and 49 characters each. Avoid < > { } [ ] and backslashes.');
  }
  return hints;
}
