import type { CreateSubtitleJobRequest } from '@transcribed-subtitle-extension/contracts';
import { isInterfaceLocale, type InterfaceLocale } from './i18n';

import {
  DEFAULT_SOURCE_LANGUAGE,
  DEFAULT_TARGET_LANGUAGE,
  normalizeSourceLanguage,
  normalizeTargetLanguage,
  type SourceLanguage,
  type TargetLanguage,
} from './languages';

export type OverlayPosition = 'bottom' | 'top' | 'compact';
export type CaptionFontSize = 'small' | 'medium' | 'large';
export type CaptionDensity = 'compact' | 'comfortable';
export type CaptionContrastTheme = 'default' | 'high';
// Subscription aliases opus/sonnet/haiku are pinned to the 5.5 models by the backend.
export const CLAUDE_MODELS = {
  opus: 'Opus 5.5', sonnet: 'Sonnet 5.5', haiku: 'Haiku 5.5',
  'claude-fable-5-1': 'Fable 5.1', 'claude-opus-5': 'Opus 5', 'claude-opus-4-8': 'Opus 4.8', 'claude-sonnet-5': 'Sonnet 5',
} as const;
export type ClaudeModel = keyof typeof CLAUDE_MODELS;
export const CLAUDE_API_MODELS = {
  'claude-opus-5-5': 'Opus 5.5', 'claude-sonnet-5-5': 'Sonnet 5.5', 'claude-haiku-5-5': 'Haiku 5.5',
  'claude-fable-5-1': 'Fable 5.1', 'claude-opus-5': 'Opus 5', 'claude-opus-4-8': 'Opus 4.8', 'claude-sonnet-5': 'Sonnet 5',
} as const;
export type ClaudeApiModel = keyof typeof CLAUDE_API_MODELS;
/** Anthropic fast mode exists only for these models on the Claude API. */
export const CLAUDE_FAST_MODELS: readonly string[] = ['claude-opus-5-5', 'claude-opus-5', 'claude-opus-4-8'];

export function isClaudeModel(value: unknown): value is ClaudeModel {
  return typeof value === 'string' && Object.hasOwn(CLAUDE_MODELS, value);
}

export function isClaudeApiModel(value: unknown): value is ClaudeApiModel {
  return typeof value === 'string' && Object.hasOwn(CLAUDE_API_MODELS, value);
}

export interface ExtensionSettings {
  interfaceLocale: InterfaceLocale | 'auto';
  aiProvider: NonNullable<CreateSubtitleJobRequest['aiProvider']>;
  codexModel: string;
  codexFastMode: boolean;
  claudeModel: ClaudeModel;
  claudeApiModel: ClaudeApiModel;
  claudeApiFastMode: boolean;
  sourceLanguage: SourceLanguage;
  targetLanguage: TargetLanguage;
  overlayVisible: boolean;
  overlayAttachedToVideo: boolean;
  overlayPosition: OverlayPosition;
  captionFontSize: CaptionFontSize;
  captionDensity: CaptionDensity;
  captionContrastTheme: CaptionContrastTheme;
  keyboardShortcutsEnabled: boolean;
  showRomanization: boolean;
  showTranslation: boolean;
  showGloss: boolean;
  blurSourceWords: boolean;
  blurRomanization: boolean;
  blurTranslation: boolean;
  pauseOnWordHover: boolean;
  subtitleTimingOffsetSeconds: number;
}

export const MIN_SUBTITLE_TIMING_OFFSET_SECONDS = -10;
export const MAX_SUBTITLE_TIMING_OFFSET_SECONDS = 10;

export const DEFAULT_EXTENSION_SETTINGS: ExtensionSettings = {
  interfaceLocale: 'auto',
  aiProvider: 'openai',
  codexModel: '',
  codexFastMode: false,
  claudeModel: 'sonnet',
  claudeApiModel: 'claude-opus-5-5',
  claudeApiFastMode: false,
  sourceLanguage: DEFAULT_SOURCE_LANGUAGE,
  targetLanguage: DEFAULT_TARGET_LANGUAGE,
  overlayVisible: true,
  overlayAttachedToVideo: true,
  overlayPosition: 'bottom',
  captionFontSize: 'medium',
  captionDensity: 'comfortable',
  captionContrastTheme: 'default',
  keyboardShortcutsEnabled: true,
  showRomanization: true,
  showTranslation: false,
  showGloss: true,
  blurSourceWords: false,
  blurRomanization: false,
  blurTranslation: false,
  pauseOnWordHover: true,
  subtitleTimingOffsetSeconds: 0,
};

export function createExtensionSettingsFromPartial(value: Partial<ExtensionSettings> | null | undefined): ExtensionSettings {
  const settings: ExtensionSettings = { ...DEFAULT_EXTENSION_SETTINGS };
  if (isInterfaceLocale(value?.interfaceLocale)) settings.interfaceLocale = value.interfaceLocale;
  const sourceLanguage = normalizeSourceLanguage(value?.sourceLanguage);
  const targetLanguage = normalizeTargetLanguage(value?.targetLanguage);

  if (value?.aiProvider === 'openai' || value?.aiProvider === 'cerebras' || value?.aiProvider === 'codex' || value?.aiProvider === 'claude' || value?.aiProvider === 'anthropic') {
    settings.aiProvider = value.aiProvider;
  }
  if (typeof value?.codexModel === 'string' && /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(value.codexModel)) settings.codexModel = value.codexModel;
  if (typeof value?.codexFastMode === 'boolean') settings.codexFastMode = value.codexFastMode;
  if (isClaudeModel(value?.claudeModel)) settings.claudeModel = value.claudeModel;
  if (isClaudeApiModel(value?.claudeApiModel)) settings.claudeApiModel = value.claudeApiModel;
  if (typeof value?.claudeApiFastMode === 'boolean') settings.claudeApiFastMode = value.claudeApiFastMode;

  if (sourceLanguage) {
    settings.sourceLanguage = sourceLanguage;
  }

  if (targetLanguage) {
    settings.targetLanguage = targetLanguage;
  }

  if (typeof value?.overlayVisible === 'boolean') {
    settings.overlayVisible = value.overlayVisible;
  }

  if (typeof value?.overlayAttachedToVideo === 'boolean') {
    settings.overlayAttachedToVideo = value.overlayAttachedToVideo;
  }

  if (
    value?.overlayPosition === 'bottom' ||
    value?.overlayPosition === 'top' ||
    value?.overlayPosition === 'compact'
  ) {
    settings.overlayPosition = value.overlayPosition;
  }

  if (value?.captionFontSize === 'small' || value?.captionFontSize === 'medium' || value?.captionFontSize === 'large') {
    settings.captionFontSize = value.captionFontSize;
  }

  if (value?.captionDensity === 'compact' || value?.captionDensity === 'comfortable') {
    settings.captionDensity = value.captionDensity;
  }

  if (value?.captionContrastTheme === 'default' || value?.captionContrastTheme === 'high') {
    settings.captionContrastTheme = value.captionContrastTheme;
  }

  if (typeof value?.keyboardShortcutsEnabled === 'boolean') {
    settings.keyboardShortcutsEnabled = value.keyboardShortcutsEnabled;
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

export function aiProviderLabel(provider: ExtensionSettings['aiProvider']): string {
  return provider === 'codex' ? 'Codex' : provider === 'claude' || provider === 'anthropic' ? 'Claude' : provider === 'cerebras' ? 'Cerebras' : 'OpenAI';
}

/** "Codex · gpt-x", "Claude · sonnet", "Claude API · claude-opus-5-5". */
export function aiSelectionLabel(provider: ExtensionSettings['aiProvider'], model: string): string {
  return `${aiProviderLabel(provider)}${provider === 'codex' || provider === 'claude' ? '' : ' API'} · ${model}`;
}

export function createAnonymousInstallId(): string {
  return `install_${globalThis.crypto.randomUUID().replaceAll('-', '')}`;
}

export function isAnonymousInstallId(value: unknown): value is string {
  return typeof value === 'string' && /^install_[0-9a-f]{32}$/.test(value);
}
