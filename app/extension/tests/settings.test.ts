import { beforeEach, describe, expect, it, vi } from 'vitest';

const storageState = vi.hoisted(() => ({ values: new Map<string, unknown>() }));

vi.mock('wxt/utils/storage', () => ({
  storage: {
    defineItem<T>(key: string, options: { fallback: T }) {
      return {
        async getValue(): Promise<T> {
          return storageState.values.has(key) ? storageState.values.get(key) as T : options.fallback;
        },
        async setValue(value: T): Promise<void> {
          storageState.values.set(key, value);
        },
        async removeValue(): Promise<void> {
          storageState.values.delete(key);
        },
      };
    },
  },
}));

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';

describe('extension settings writes', () => {
  beforeEach(() => storageState.values.clear());

  it('serializes concurrent patches so neither update is lost', async () => {
    const { updateExtensionSettings, getExtensionSettings } = await import('../utils/settings');

    await Promise.all([
      updateExtensionSettings({ showTranslation: true }),
      updateExtensionSettings({ showRomanization: false }),
    ]);

    await expect(getExtensionSettings()).resolves.toMatchObject({
      ...DEFAULT_EXTENSION_SETTINGS,
      showTranslation: true,
      showRomanization: false,
    });
  });

  it('persists the clear-local recovery block until deliberate generation', async () => {
    const { isSubtitleRecoveryBlocked, setSubtitleRecoveryBlocked } = await import('../utils/settings');

    await expect(isSubtitleRecoveryBlocked()).resolves.toBe(false);
    await setSubtitleRecoveryBlocked(true);
    await expect(isSubtitleRecoveryBlocked()).resolves.toBe(true);
    await setSubtitleRecoveryBlocked(false);
    await expect(isSubtitleRecoveryBlocked()).resolves.toBe(false);
  });
});
