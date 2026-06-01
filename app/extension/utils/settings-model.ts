import {
  DEFAULT_SOURCE_LANGUAGE,
  DEFAULT_TARGET_LANGUAGE,
  normalizeSourceLanguage,
  normalizeTargetLanguage,
  type SourceLanguage,
  type TargetLanguage,
} from './languages';

export type OverlayPosition = 'bottom' | 'top' | 'compact';

export interface ExtensionSettings {
  sourceLanguage: SourceLanguage;
  targetLanguage: TargetLanguage;
  overlayVisible: boolean;
  overlayPosition: OverlayPosition;
  showRomanization: boolean;
  showTranslation: boolean;
  showGloss: boolean;
  blurSourceWords: boolean;
  blurRomanization: boolean;
  blurTranslation: boolean;
  pauseOnWordHover: boolean;
  fullTrackEnrichment: boolean;
  subtitleTimingOffsetSeconds: number;
}

export const MIN_SUBTITLE_TIMING_OFFSET_SECONDS = -10;
export const MAX_SUBTITLE_TIMING_OFFSET_SECONDS = 10;

export const DEFAULT_EXTENSION_SETTINGS: ExtensionSettings = {
  sourceLanguage: DEFAULT_SOURCE_LANGUAGE,
  targetLanguage: DEFAULT_TARGET_LANGUAGE,
  overlayVisible: true,
  overlayPosition: 'bottom',
  showRomanization: true,
  showTranslation: false,
  showGloss: true,
  blurSourceWords: false,
  blurRomanization: false,
  blurTranslation: false,
  pauseOnWordHover: true,
  fullTrackEnrichment: false,
  subtitleTimingOffsetSeconds: 0,
};

export function createExtensionSettingsFromPartial(value: Partial<ExtensionSettings> | null | undefined): ExtensionSettings {
  const settings: ExtensionSettings = { ...DEFAULT_EXTENSION_SETTINGS };
  const sourceLanguage = normalizeSourceLanguage(value?.sourceLanguage);
  const targetLanguage = normalizeTargetLanguage(value?.targetLanguage);

  if (sourceLanguage) {
    settings.sourceLanguage = sourceLanguage;
  }

  if (targetLanguage) {
    settings.targetLanguage = targetLanguage;
  }

  if (typeof value?.overlayVisible === 'boolean') {
    settings.overlayVisible = value.overlayVisible;
  }

  if (
    value?.overlayPosition === 'bottom' ||
    value?.overlayPosition === 'top' ||
    value?.overlayPosition === 'compact'
  ) {
    settings.overlayPosition = value.overlayPosition;
  }

  if (typeof value?.showRomanization === 'boolean') {
    settings.showRomanization = value.showRomanization;
  }

  if (typeof value?.showTranslation === 'boolean') {
    settings.showTranslation = value.showTranslation;
  }

  if (typeof value?.showGloss === 'boolean') {
    settings.showGloss = value.showGloss;
  }

  if (typeof value?.blurSourceWords === 'boolean') {
    settings.blurSourceWords = value.blurSourceWords;
  }

  if (typeof value?.blurRomanization === 'boolean') {
    settings.blurRomanization = value.blurRomanization;
  }

  if (typeof value?.blurTranslation === 'boolean') {
    settings.blurTranslation = value.blurTranslation;
  }

  if (typeof value?.pauseOnWordHover === 'boolean') {
    settings.pauseOnWordHover = value.pauseOnWordHover;
  }

  if (typeof value?.fullTrackEnrichment === 'boolean') {
    settings.fullTrackEnrichment = value.fullTrackEnrichment;
  }

  settings.subtitleTimingOffsetSeconds = normalizeSubtitleTimingOffsetSeconds(value?.subtitleTimingOffsetSeconds);

  return settings;
}

export function normalizeSubtitleTimingOffsetSeconds(value: unknown): number {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return 0;
  }

  const clamped = Math.min(
    MAX_SUBTITLE_TIMING_OFFSET_SECONDS,
    Math.max(MIN_SUBTITLE_TIMING_OFFSET_SECONDS, value),
  );

  return Math.round(clamped * 10) / 10;
}

export function createAnonymousInstallId(): string {
  return `install_${globalThis.crypto.randomUUID().replaceAll('-', '')}`;
}

export function isAnonymousInstallId(value: unknown): value is string {
  return typeof value === 'string' && /^install_[0-9a-f]{32}$/.test(value);
}
