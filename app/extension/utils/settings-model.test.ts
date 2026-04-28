import { describe, expect, it } from 'vitest';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createAnonymousInstallId,
  isAnonymousInstallId,
  normalizeExtensionSettings,
} from './settings-model';

describe('settings model', () => {
  it('uses safe defaults for missing or invalid settings', () => {
    expect(
      normalizeExtensionSettings({
        overlayVisible: false,
        overlayPosition: 'side' as never,
      }),
    ).toEqual({
      ...DEFAULT_EXTENSION_SETTINGS,
      overlayVisible: false,
    });
  });

  it('creates anonymous install IDs without personal data', () => {
    const installId = createAnonymousInstallId(
      new Uint8Array([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]),
    );

    expect(installId).toBe('install_000102030405060708090a0b0c0d0e0f');
    expect(isAnonymousInstallId(installId)).toBe(true);
    expect(isAnonymousInstallId('user@example.com')).toBe(false);
  });
});
