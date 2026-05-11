import type { CreateSubtitleJobRequest } from './contracts';

export type SourceLanguage = CreateSubtitleJobRequest['sourceLanguage'];

export interface SupportedSourceLanguage {
  code: SourceLanguage;
  label: string;
}

export const SUPPORTED_SOURCE_LANGUAGES = [
  { code: 'auto', label: 'Auto detect' },
  { code: 'ar', label: 'Arabic' },
  { code: 'en', label: 'English' },
  { code: 'es', label: 'Spanish' },
  { code: 'pt', label: 'Portuguese' },
  { code: 'fr', label: 'French' },
  { code: 'de', label: 'German' },
  { code: 'it', label: 'Italian' },
] as const satisfies readonly SupportedSourceLanguage[];

export const DEFAULT_SOURCE_LANGUAGE: SourceLanguage = 'ar';

export function isSupportedSourceLanguage(value: unknown): value is SourceLanguage {
  return typeof value === 'string' && SUPPORTED_SOURCE_LANGUAGES.some((language) => language.code === value);
}
