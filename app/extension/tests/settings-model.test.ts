import { describe, expect, it } from 'vitest';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createExtensionSettingsFromPartial,
  createAnonymousInstallId,
  isAnonymousInstallId,
  normalizeSubtitleTimingOffsetSeconds,
} from '../utils/settings-model';

describe('settings model', () => {
  it('uses safe defaults for missing or invalid settings', () => {
    expect(
      createExtensionSettingsFromPartial({
        overlayVisible: false,
        overlayPosition: 'side' as never,
        subtitleTimingOffsetSeconds: 4.54,
      }),
    ).toEqual({
      ...DEFAULT_EXTENSION_SETTINGS,
      overlayVisible: false,
      subtitleTimingOffsetSeconds: 4.5,
    });
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
