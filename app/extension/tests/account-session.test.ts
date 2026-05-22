import { beforeEach, describe, expect, it, vi } from 'vitest';

const storageState = vi.hoisted(() => ({
  values: new Map<string, unknown>(),
}));

vi.mock('wxt/utils/storage', () => ({
  storage: {
    defineItem<T>(key: string, options: { fallback: T }) {
      return {
        async getValue(): Promise<T> {
          return storageState.values.has(key) ? (storageState.values.get(key) as T) : options.fallback;
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

import type { ExtensionAuthResponse } from '../utils/contracts';

describe('extension account session storage', () => {
  beforeEach(() => {
    storageState.values.clear();
  });

  it('stores only the scoped token and safe account summary', async () => {
    const { getStoredExtensionSession, storeExtensionSession } = await accountSession();
    const session = await storeExtensionSession(authResponse());

    expect(session.plainTextToken).toBe('1|aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    expect(JSON.stringify(session)).not.toContain('correct-password');
    expect(await getStoredExtensionSession(new Date('2026-06-01T00:00:00Z'))).toEqual(session);
  });

  it('drops expired sessions and updates safe account summaries', async () => {
    const { getStoredExtensionSession, storeExtensionSession, updateStoredAccount } = await accountSession();
    await storeExtensionSession(authResponse());
    await updateStoredAccount({
      ...authResponse().account,
      monthlyMinutesUsed: 12,
      monthlyMinutesRemaining: 48,
    });

    expect(await getStoredExtensionSession(new Date('2026-06-01T00:00:00Z'))).toMatchObject({
      account: {
        monthlyMinutesUsed: 12,
        monthlyMinutesRemaining: 48,
      },
    });

    expect(await getStoredExtensionSession(new Date('2026-07-01T00:00:00Z'))).toBeNull();
  });

  it('clears the stored extension token on logout', async () => {
    const { clearExtensionSession, getStoredExtensionSession, storeExtensionSession } = await accountSession();
    await storeExtensionSession(authResponse());
    await clearExtensionSession();

    expect(await getStoredExtensionSession()).toBeNull();
  });
});

async function accountSession(): Promise<typeof import('../utils/account-session')> {
  return import('../utils/account-session');
}

function authResponse(): ExtensionAuthResponse {
  return {
    account: {
      status: 'authenticated',
      id: '1',
      email: 'learner@example.com',
      name: 'Beta Learner',
      emailVerified: true,
      planName: 'Local beta',
      tierName: 'Base',
      tierSpeedLabel: 'Standard queue',
      monthlyMinuteLimit: 60,
      monthlyMinutesUsed: 0,
      monthlyMinutesPending: 0,
      monthlyMinutesRemaining: 60,
      resetAt: '2026-06-01T00:00:00.000Z',
      upgradeAvailable: true,
    },
    token: {
      plainTextToken: '1|aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      tokenType: 'Bearer',
      expiresAt: '2026-06-21T00:00:00.000Z',
      abilities: ['extension:account:read', 'extension:subtitles:write', 'extension:tokens:revoke'],
    },
  };
}
