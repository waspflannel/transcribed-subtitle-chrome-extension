import { describe, expect, it } from 'vitest';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createExtensionSettingsFromPartial,
  createAnonymousInstallId,
  isAnonymousInstallId,
} from '../utils/settings-model';

describe('settings model', () => {
  it('uses safe defaults for missing or invalid settings', () => {
    expect(
      createExtensionSettingsFromPartial({
        overlayVisible: false,
        overlayPosition: 'side' as never,
      }),
    ).toEqual({
      ...DEFAULT_EXTENSION_SETTINGS,
      overlayVisible: false,
    });
  });

  it('creates anonymous install IDs without personal data', () => {
    const installId = createAnonymousInstallId();

    expect(installId).toMatch(/^install_[0-9a-f]{32}$/);
    expect(isAnonymousInstallId(installId)).toBe(true);
    expect(isAnonymousInstallId('user@example.com')).toBe(false);
  });
});
