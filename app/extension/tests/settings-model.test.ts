import { describe, expect, it } from 'vitest';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createExtensionSettingsFromPartial,
  createAnonymousInstallId,
  isAnonymousInstallId,
  normalizeSubtitleTimingOffsetSeconds,
} from '../utils/settings-model';

describe('settings model', () => {
  it('keeps existing installs attached and saves only boolean movement preferences', () => {
    expect(createExtensionSettingsFromPartial(undefined).overlayAttachedToVideo).toBe(true);
    expect(createExtensionSettingsFromPartial({ overlayAttachedToVideo: false }).overlayAttachedToVideo).toBe(false);
    expect(createExtensionSettingsFromPartial({ overlayAttachedToVideo: 'false' as never }).overlayAttachedToVideo).toBe(true);
  });

  it('defaults to OpenAI, remembers Cerebras, and resets retired Auto and unknown providers', () => {
    expect(createExtensionSettingsFromPartial(undefined).aiProvider).toBe('openai');
    expect(createExtensionSettingsFromPartial({ aiProvider: 'auto' as never }).aiProvider).toBe('openai');
    expect(createExtensionSettingsFromPartial({ sourceLanguage: 'auto' }).sourceLanguage).toBe('auto');
    expect(createExtensionSettingsFromPartial({ aiProvider: 'cerebras' }).aiProvider).toBe('cerebras');
    expect(createExtensionSettingsFromPartial({ aiProvider: 'hybrid' as never }).aiProvider).toBe('openai');
  });

  it('uses safe defaults for missing or invalid settings', () => {
    expect(
      createExtensionSettingsFromPartial({
        overlayVisible: false,
        overlayPosition: 'side' as never,
        sourceLanguage: 'spa',
        targetLanguage: 'jpn',
        captionFontSize: 'large',
        captionDensity: 'compact',
        captionContrastTheme: 'high',
        keyboardShortcutsEnabled: false,
        showTranslation: true,
        blurSourceWords: true,
        blurRomanization: true,
        blurTranslation: true,
        pauseOnWordHover: false,
        subtitleTimingOffsetSeconds: 4.54,
      }),
    ).toEqual({
      ...DEFAULT_EXTENSION_SETTINGS,
      sourceLanguage: 'spa',
      targetLanguage: 'jpn',
      captionFontSize: 'large',
      captionDensity: 'compact',
      captionContrastTheme: 'high',
      keyboardShortcutsEnabled: false,
      overlayVisible: false,
      showTranslation: true,
      blurSourceWords: true,
      blurRomanization: true,
      blurTranslation: true,
      pauseOnWordHover: false,
      subtitleTimingOffsetSeconds: 4.5,
    });
  });

  it('rejects invalid stored source and target languages', () => {
    expect(
      createExtensionSettingsFromPartial({
        sourceLanguage: 'zz' as never,
        targetLanguage: 'auto' as never,
      }),
    ).toEqual(DEFAULT_EXTENSION_SETTINGS);
  });

  it('rejects invalid caption and shortcut display settings', () => {
    expect(
      createExtensionSettingsFromPartial({
        captionFontSize: 'huge' as never,
        captionDensity: 'spacious' as never,
        captionContrastTheme: 'solarized' as never,
        keyboardShortcutsEnabled: 'yes' as never,
      }),
    ).toEqual(DEFAULT_EXTENSION_SETTINGS);
  });

  it('does not keep old stored ISO-639-1 language compatibility aliases', () => {
    expect(
      createExtensionSettingsFromPartial({
        sourceLanguage: 'es' as never,
        targetLanguage: 'ja' as never,
      }),
    ).toEqual(DEFAULT_EXTENSION_SETTINGS);
  });

  it('normalizes subtitle timing offsets to the supported slider range', () => {
    expect(normalizeSubtitleTimingOffsetSeconds(12)).toBe(10);
    expect(normalizeSubtitleTimingOffsetSeconds(-12)).toBe(-10);
    expect(normalizeSubtitleTimingOffsetSeconds(4.56)).toBe(4.6);
    expect(normalizeSubtitleTimingOffsetSeconds(Number.NaN)).toBe(0);
  });

  it('creates anonymous install IDs without personal data', () => {
    const installId = createAnonymousInstallId();

    expect(installId).toMatch(/^install_[0-9a-f]{32}$/);
    expect(isAnonymousInstallId(installId)).toBe(true);
    expect(isAnonymousInstallId('user@example.com')).toBe(false);
  });
});
