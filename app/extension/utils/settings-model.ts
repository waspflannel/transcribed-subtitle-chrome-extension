export type OverlayPosition = 'bottom' | 'top' | 'compact';

export interface ExtensionSettings {
  overlayVisible: boolean;
  overlayPosition: OverlayPosition;
  showRomanization: boolean;
  showGloss: boolean;
}

export const DEFAULT_EXTENSION_SETTINGS: ExtensionSettings = {
  overlayVisible: true,
  overlayPosition: 'bottom',
  showRomanization: true,
  showGloss: true,
};

export function createExtensionSettingsFromPartial(value: Partial<ExtensionSettings> | null | undefined): ExtensionSettings {
  const settings: ExtensionSettings = { ...DEFAULT_EXTENSION_SETTINGS };

  if (typeof value?.overlayVisible === 'boolean') {
    settings.overlayVisible = value.overlayVisible;
  }

  if (
    value?.overlayPosition === 'bottom' ||
    value?.overlayPosition === 'top' ||
    value?.overlayPosition === 'compact'
  ) {
    settings.overlayPosition = value.overlayPosition;
  }

  if (typeof value?.showRomanization === 'boolean') {
    settings.showRomanization = value.showRomanization;
  }

  if (typeof value?.showGloss === 'boolean') {
    settings.showGloss = value.showGloss;
  }

  return settings;
}

export function createAnonymousInstallId(): string {
  return `install_${globalThis.crypto.randomUUID().replaceAll('-', '')}`;
}

export function isAnonymousInstallId(value: unknown): value is string {
  return typeof value === 'string' && /^install_[0-9a-f]{32}$/.test(value);
}
