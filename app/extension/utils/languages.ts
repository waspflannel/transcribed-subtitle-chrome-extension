import languageCatalog from '@transcribed-subtitle-extension/contracts/languages.json';

import type { CreateSubtitleJobRequest } from './contracts';

export type SourceLanguage = CreateSubtitleJobRequest['sourceLanguage'];
export type TargetLanguage = CreateSubtitleJobRequest['targetLanguage'];
export type LanguageTier = 'auto' | 'excellent' | 'high' | 'good' | 'moderate';

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
export const DEFAULT_TARGET_LANGUAGE: TargetLanguage = 'eng';

const sourceLanguageCodes = new Set(SOURCE_LANGUAGE_OPTIONS.map((language) => language.code));
const targetLanguageCodes = new Set(TARGET_LANGUAGE_OPTIONS.map((language) => language.code));
const languageAliases = new Map<string, string>();

for (const language of LANGUAGE_OPTIONS) {
  for (const alias of language.aliases ?? []) {
    languageAliases.set(alias.toLowerCase(), language.code);
  }
}

export function isSourceLanguage(value: unknown): value is SourceLanguage {
  return typeof value === 'string' && sourceLanguageCodes.has(value);
}

export function isTargetLanguage(value: unknown): value is TargetLanguage {
  return typeof value === 'string' && targetLanguageCodes.has(value);
}

export function normalizeSourceLanguage(value: unknown): SourceLanguage | null {
  const code = normalizeLanguageCode(value);

  return isSourceLanguage(code) ? code : null;
}

export function normalizeTargetLanguage(value: unknown): TargetLanguage | null {
  const code = normalizeLanguageCode(value);

  return isTargetLanguage(code) ? code : null;
}

export function languageLabel(code: string | null | undefined): string {
  const normalizedCode = normalizeLanguageCode(code);

  return LANGUAGE_OPTIONS.find((language) => language.code === normalizedCode)?.label ?? code ?? '';
}

export function languageSearchText(language: LanguageOption): string {
  return [
    language.label,
    language.code,
    language.tier,
    ...(language.aliases ?? []),
  ].join(' ').toLowerCase();
}

function normalizeLanguageCode(value: unknown): string | null {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }

  const normalized = value.trim().toLowerCase().replaceAll('_', '-');
  const primary = normalized.split('-')[0] ?? normalized;

  for (const candidate of [normalized, primary]) {
    if (sourceLanguageCodes.has(candidate)) {
      return candidate;
    }

    const alias = languageAliases.get(candidate);

    if (alias) {
      return alias;
    }
  }

  return null;
}
