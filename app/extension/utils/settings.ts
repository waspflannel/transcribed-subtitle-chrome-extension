import { storage } from 'wxt/utils/storage';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createAnonymousInstallId,
  isAnonymousInstallId,
  normalizeExtensionSettings,
  type ExtensionSettings,
} from './settings-model';

const settingsStorage = storage.defineItem<ExtensionSettings>('local:extensionSettings', {
  fallback: DEFAULT_EXTENSION_SETTINGS,
});

const installIdStorage = storage.defineItem<string | null>('local:installId', {
  fallback: null,
});

export async function getExtensionSettings(): Promise<ExtensionSettings> {
  const storedSettings = await settingsStorage.getValue();
  const settings = normalizeExtensionSettings(storedSettings);

  if (JSON.stringify(settings) !== JSON.stringify(storedSettings)) {
    await settingsStorage.setValue(settings);
  }

  return settings;
}

export async function updateExtensionSettings(patch: Partial<ExtensionSettings>): Promise<ExtensionSettings> {
  const currentSettings = await getExtensionSettings();
  const nextSettings = normalizeExtensionSettings({
    ...currentSettings,
    ...patch,
  });

  await settingsStorage.setValue(nextSettings);

  return nextSettings;
}

export async function getOrCreateInstallId(): Promise<string> {
  const storedInstallId = await installIdStorage.getValue();

  if (isAnonymousInstallId(storedInstallId)) {
    return storedInstallId;
  }

  const nextInstallId = createAnonymousInstallId();
  await installIdStorage.setValue(nextInstallId);

  return nextInstallId;
}
