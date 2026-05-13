import { describe, expect, it } from 'vitest';

import {
  DEFAULT_SOURCE_LANGUAGE,
  DEFAULT_TARGET_LANGUAGE,
  SOURCE_LANGUAGE_OPTIONS,
  TARGET_LANGUAGE_OPTIONS,
  isSourceLanguage,
  isTargetLanguage,
  languageLabel,
  languageSearchText,
} from '../utils/languages';

describe('language catalog', () => {
  it('defaults to auto-detected source and English target', () => {
    expect(DEFAULT_SOURCE_LANGUAGE).toBe('auto');
    expect(DEFAULT_TARGET_LANGUAGE).toBe('eng');
  });

  it('allows Auto detect only for source languages', () => {
    expect(isSourceLanguage('auto')).toBe(true);
    expect(isTargetLanguage('auto')).toBe(false);
    expect(SOURCE_LANGUAGE_OPTIONS.some((language) => language.code === 'auto')).toBe(true);
    expect(TARGET_LANGUAGE_OPTIONS.some((language) => language.code === 'auto')).toBe(false);
  });

  it('keeps WER accuracy tiers searchable', () => {
    const excellent = SOURCE_LANGUAGE_OPTIONS.find((language) => language.code === 'jpn');
    const high = TARGET_LANGUAGE_OPTIONS.find((language) => language.code === 'swa');
    const moderate = TARGET_LANGUAGE_OPTIONS.find((language) => language.code === 'zul');

    expect(excellent?.tier).toBe('excellent');
    expect(high?.tier).toBe('high');
    expect(moderate?.tier).toBe('moderate');
    expect(languageLabel('cmn')).toBe('Mandarin');
    expect(languageSearchText(excellent!)).toContain('ja');
  });

  it('rejects unknown display language codes instead of inventing labels', () => {
    expect(() => languageLabel('xx')).toThrow(TypeError);
  });
});
