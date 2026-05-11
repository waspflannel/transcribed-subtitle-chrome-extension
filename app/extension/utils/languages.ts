import languageCatalog from '@transcribed-subtitle-extension/contracts/languages.json';

import type { CreateSubtitleJobRequest } from './contracts';

export type SourceLanguage = CreateSubtitleJobRequest['sourceLanguage'];
export type TargetLanguage = CreateSubtitleJobRequest['targetLanguage'];
export type LanguageTier = 'supported' | 'experimental';

export interface LanguageOption {
  code: string;
  label: string;
  tier: LanguageTier;
  sourceOnly?: boolean;
  aliases?: readonly string[];
}

interface LanguageCatalog {
  languages: readonly LanguageOption[];
}

const languages = (languageCatalog as LanguageCatalog).languages;

export const LANGUAGE_OPTIONS = languages;
export const SOURCE_LANGUAGE_OPTIONS = languages;
export const TARGET_LANGUAGE_OPTIONS = languages.filter((language) => !language.sourceOnly);

export const DEFAULT_SOURCE_LANGUAGE: SourceLanguage = 'auto';
export const DEFAULT_TARGET_LANGUAGE: TargetLanguage = 'en';

const sourceLanguageCodes = new Set(SOURCE_LANGUAGE_OPTIONS.map((language) => language.code));
const targetLanguageCodes = new Set(TARGET_LANGUAGE_OPTIONS.map((language) => language.code));

export function isSourceLanguage(value: unknown): value is SourceLanguage {
  return typeof value === 'string' && sourceLanguageCodes.has(value);
}

export function isTargetLanguage(value: unknown): value is TargetLanguage {
  return typeof value === 'string' && targetLanguageCodes.has(value);
}

export function languageLabel(code: string | null | undefined): string {
  return LANGUAGE_OPTIONS.find((language) => language.code === code)?.label ?? code ?? '';
}

export function languageSearchText(language: LanguageOption): string {
  return [
    language.label,
    language.code,
    language.tier,
    ...(language.aliases ?? []),
  ].join(' ').toLowerCase();
}
