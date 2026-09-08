import { storage } from 'wxt/utils/storage';

import type { AccountSummary, ExtensionAuthResponse } from './contracts';

export interface StoredExtensionSession {
  sessionId: string;
  plainTextToken: string;
  tokenType: 'Bearer';
  expiresAt: string;
  account: AccountSummary;
}

const extensionSessionStorage = storage.defineItem<StoredExtensionSession | null>('local:extensionSession', {
  fallback: null,
});
let sessionWriteQueue = Promise.resolve();

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
    sessionId: createSessionId(),
    plainTextToken: response.token.plainTextToken,
    tokenType: response.token.tokenType,
    expiresAt: response.token.expiresAt,
    account: response.account,
  };

  const write = sessionWriteQueue.then(() => extensionSessionStorage.setValue(session));
  sessionWriteQueue = write.then(() => undefined, () => undefined);
  await write;

  return session;
}

export async function updateStoredAccount(
  account: AccountSummary,
  expectedSessionId?: string,
): Promise<StoredExtensionSession | null> {
  const write = sessionWriteQueue.then(async () => {
    const session = await getStoredExtensionSession();

    if (!session || (expectedSessionId !== undefined && session.sessionId !== expectedSessionId)) {
      return null;
    }

    const nextSession: StoredExtensionSession = {
      ...session,
      account,
    };

    await extensionSessionStorage.setValue(nextSession);

    return nextSession;
  });
  sessionWriteQueue = write.then(() => undefined, () => undefined);

  return write;
}

export async function clearExtensionSession(): Promise<void> {
  const clear = sessionWriteQueue.then(() => extensionSessionStorage.removeValue());
  sessionWriteQueue = clear.then(() => undefined, () => undefined);
  await clear;
}

function isStoredExtensionSession(value: unknown): value is StoredExtensionSession {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const session = value as Partial<StoredExtensionSession>;

  return typeof session.sessionId === 'string'
    && session.sessionId.trim().length > 0
    && typeof session.plainTextToken === 'string'
    && session.plainTextToken.trim().length > 0
    && session.tokenType === 'Bearer'
    && typeof session.expiresAt === 'string'
    && !Number.isNaN(Date.parse(session.expiresAt))
    && typeof session.account === 'object'
    && session.account !== null
    && session.account.status === 'authenticated';
}

function createSessionId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
