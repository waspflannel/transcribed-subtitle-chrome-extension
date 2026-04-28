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

export function normalizeExtensionSettings(value: Partial<ExtensionSettings> | null | undefined): ExtensionSettings {
  return {
    overlayVisible:
      typeof value?.overlayVisible === 'boolean'
        ? value.overlayVisible
        : DEFAULT_EXTENSION_SETTINGS.overlayVisible,
    overlayPosition: isOverlayPosition(value?.overlayPosition)
      ? value.overlayPosition
      : DEFAULT_EXTENSION_SETTINGS.overlayPosition,
    showRomanization:
      typeof value?.showRomanization === 'boolean'
        ? value.showRomanization
        : DEFAULT_EXTENSION_SETTINGS.showRomanization,
    showGloss:
      typeof value?.showGloss === 'boolean' ? value.showGloss : DEFAULT_EXTENSION_SETTINGS.showGloss,
  };
}

export function isOverlayPosition(value: unknown): value is OverlayPosition {
  return value === 'bottom' || value === 'top' || value === 'compact';
}

export function createAnonymousInstallId(bytes?: Uint8Array): string {
  const sourceBytes = bytes ?? createRandomBytes(16);

  return `install_${Array.from(sourceBytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
}

export function isAnonymousInstallId(value: unknown): value is string {
  return typeof value === 'string' && /^install_[0-9a-f]{32}$/.test(value);
}

function createRandomBytes(length: number): Uint8Array {
  const bytes = new Uint8Array(length);

  globalThis.crypto.getRandomValues(bytes);

  return bytes;
}
