import { storage } from 'wxt/utils/storage';

import type { AccountSummary, ExtensionAuthResponse } from './contracts';

export interface StoredExtensionSession {
  plainTextToken: string;
  tokenType: 'Bearer';
  expiresAt: string;
  account: AccountSummary;
}

const extensionSessionStorage = storage.defineItem<StoredExtensionSession | null>('local:extensionSession', {
  fallback: null,
});

export async function getStoredExtensionSession(now: Date = new Date()): Promise<StoredExtensionSession | null> {
  const session = await extensionSessionStorage.getValue();

  if (!isStoredExtensionSession(session) || Date.parse(session.expiresAt) <= now.getTime()) {
    await extensionSessionStorage.removeValue();

    return null;
  }

  return session;
}

export async function storeExtensionSession(response: ExtensionAuthResponse): Promise<StoredExtensionSession> {
  const session: StoredExtensionSession = {
    plainTextToken: response.token.plainTextToken,
    tokenType: response.token.tokenType,
    expiresAt: response.token.expiresAt,
    account: response.account,
  };

  await extensionSessionStorage.setValue(session);

  return session;
}

export async function updateStoredAccount(account: AccountSummary): Promise<StoredExtensionSession | null> {
  const session = await getStoredExtensionSession();

  if (!session) {
    return null;
  }

  const nextSession: StoredExtensionSession = {
    ...session,
    account,
  };

  await extensionSessionStorage.setValue(nextSession);

  return nextSession;
}

export async function clearExtensionSession(): Promise<void> {
  await extensionSessionStorage.removeValue();
}

function isStoredExtensionSession(value: unknown): value is StoredExtensionSession {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const session = value as Partial<StoredExtensionSession>;

  return typeof session.plainTextToken === 'string'
    && session.plainTextToken.trim().length > 0
    && session.tokenType === 'Bearer'
    && typeof session.expiresAt === 'string'
    && !Number.isNaN(Date.parse(session.expiresAt))
    && typeof session.account === 'object'
    && session.account !== null
    && session.account.status === 'authenticated';
}
