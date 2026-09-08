import { storage } from 'wxt/utils/storage';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createExtensionSettingsFromPartial,
  createAnonymousInstallId,
  isAnonymousInstallId,
  type ExtensionSettings,
} from './settings-model';

const settingsStorage = storage.defineItem<ExtensionSettings>('local:extensionSettings', {
  fallback: DEFAULT_EXTENSION_SETTINGS,
});

const installIdStorage = storage.defineItem<string | null>('local:installId', {
  fallback: null,
});

let settingsWriteQueue = Promise.resolve();
let installIdWriteQueue = Promise.resolve();

export async function getExtensionSettings(): Promise<ExtensionSettings> {
  return createExtensionSettingsFromPartial(await settingsStorage.getValue());
}

export async function updateExtensionSettings(patch: Partial<ExtensionSettings>): Promise<ExtensionSettings> {
  const write = settingsWriteQueue.then(async () => {
    const currentSettings = await getExtensionSettings();
    const nextSettings = createExtensionSettingsFromPartial({
      ...currentSettings,
      ...patch,
    });

    await settingsStorage.setValue(nextSettings);

    return nextSettings;
  });
  settingsWriteQueue = write.then(() => undefined, () => undefined);

  return write;
}

export function waitForExtensionSettingsWrites(): Promise<void> {
  return settingsWriteQueue;
}

export async function clearLocalExtensionState(): Promise<void> {
  const clearSettings = settingsWriteQueue.then(() => settingsStorage.removeValue());
  const clearInstallId = installIdWriteQueue.then(() => installIdStorage.removeValue());
  settingsWriteQueue = clearSettings.then(() => undefined, () => undefined);
  installIdWriteQueue = clearInstallId.then(() => undefined, () => undefined);
  await Promise.all([clearSettings, clearInstallId]);
}

export async function getOrCreateInstallId(): Promise<string> {
  const readOrCreate = installIdWriteQueue.then(async () => {
    const storedInstallId = await installIdStorage.getValue();

    if (isAnonymousInstallId(storedInstallId)) {
      return storedInstallId;
    }

    const nextInstallId = createAnonymousInstallId();
    await installIdStorage.setValue(nextInstallId);

    return nextInstallId;
  });
  installIdWriteQueue = readOrCreate.then(() => undefined, () => undefined);

  return readOrCreate;
}
