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
    expect(DEFAULT_TARGET_LANGUAGE).toBe('en');
  });

  it('allows Auto detect only for source languages', () => {
    expect(isSourceLanguage('auto')).toBe(true);
    expect(isTargetLanguage('auto')).toBe(false);
    expect(SOURCE_LANGUAGE_OPTIONS.some((language) => language.code === 'auto')).toBe(true);
    expect(TARGET_LANGUAGE_OPTIONS.some((language) => language.code === 'auto')).toBe(false);
  });

  it('keeps supported and experimental languages searchable', () => {
    const supported = SOURCE_LANGUAGE_OPTIONS.find((language) => language.code === 'ja');
    const experimental = TARGET_LANGUAGE_OPTIONS.find((language) => language.code === 'sw');

    expect(supported?.tier).toBe('supported');
    expect(experimental?.tier).toBe('experimental');
    expect(languageLabel('zh')).toBe('Chinese');
    expect(languageSearchText(supported!)).toContain('jpn');
  });
});
