import './style.css';

import { browser } from 'wxt/browser';

import { isOverlayMode, type OverlayMode, type PopupState } from '../../utils/messages';
import type { ExtensionSettings, OverlayPosition } from '../../utils/settings-model';

type PopupRequest =
  | {
      type: 'popup.getState';
    }
  | {
      type: 'popup.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'popup.setOverlayMode';
      mode: OverlayMode;
    };

const installIdText = document.querySelector<HTMLParagraphElement>('[data-install-id]')!;
const statusText = document.querySelector<HTMLParagraphElement>('[data-status]')!;
const videoText = document.querySelector<HTMLElement>('[data-video-label]')!;
const trackText = document.querySelector<HTMLElement>('[data-track-label]')!;
const refreshButton = document.querySelector<HTMLButtonElement>('[data-action="refresh"]')!;
const overlayModeSelect = document.querySelector<HTMLSelectElement>('select[name="overlayMode"]')!;
const overlayPositionSelect = document.querySelector<HTMLSelectElement>('select[name="overlayPosition"]')!;
const overlayVisibleInput = document.querySelector<HTMLInputElement>('input[name="overlayVisible"]')!;
const showRomanizationInput = document.querySelector<HTMLInputElement>('input[name="showRomanization"]')!;
const showGlossInput = document.querySelector<HTMLInputElement>('input[name="showGloss"]')!;

refreshButton.addEventListener('click', () => void loadPopupState());
overlayModeSelect.addEventListener('change', handleOverlayModeChange);
overlayPositionSelect.addEventListener('change', handleOverlayPositionChange);
overlayVisibleInput.addEventListener('change', () => void updateSettings({ overlayVisible: overlayVisibleInput.checked }));
showRomanizationInput.addEventListener('change', () =>
  void updateSettings({ showRomanization: showRomanizationInput.checked }),
);
showGlossInput.addEventListener('change', () => void updateSettings({ showGloss: showGlossInput.checked }));

void loadPopupState();

async function loadPopupState(): Promise<void> {
  await sendPopupRequest({ type: 'popup.getState' });
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  await sendPopupRequest({ type: 'popup.updateSettings', patch });
}

async function setOverlayMode(mode: OverlayMode): Promise<void> {
  await sendPopupRequest({ type: 'popup.setOverlayMode', mode });
}

async function sendPopupRequest(request: PopupRequest): Promise<void> {
  try {
    const state = (await browser.runtime.sendMessage(request)) as PopupState;
    showPopupState(state);
  } catch (error) {
    showError(error);
  }
}

function handleOverlayModeChange(): void {
  if (isOverlayMode(overlayModeSelect.value)) {
    void setOverlayMode(overlayModeSelect.value);
  }
}

function handleOverlayPositionChange(): void {
  const overlayPosition = overlayPositionFromValue(overlayPositionSelect.value);

  if (overlayPosition) {
    void updateSettings({ overlayPosition });
  }
}

function showPopupState(state: PopupState): void {
  const pageStatus = state.pageStatus;
  const supported = isSupportedVideoPage(pageStatus);

  installIdText.hidden = false;
  installIdText.textContent = shortInstallId(state.installId);
  statusText.className = `status ${supported ? 'ok' : 'idle'}`;
  statusText.textContent = videoStateLabel(pageStatus);
  videoText.textContent = videoLabel(pageStatus);
  trackText.textContent = overlayModeLabel(state.overlayMode);
  overlayModeSelect.value = state.overlayMode;
  overlayModeSelect.disabled = !supported;

  showSettings(state.settings);
  setSettingsDisabled(false);
}

function showSettings(settings: ExtensionSettings): void {
  overlayVisibleInput.checked = settings.overlayVisible;
  overlayPositionSelect.value = settings.overlayPosition;
  showRomanizationInput.checked = settings.showRomanization;
  showGlossInput.checked = settings.showGloss;
}

function showError(error: unknown): void {
  installIdText.hidden = true;
  statusText.className = 'status error';
  statusText.textContent = error instanceof Error ? error.message : 'Unable to load extension state';
  videoText.textContent = 'No supported video';
  trackText.textContent = 'No track';
  overlayModeSelect.value = 'no-track';
  overlayModeSelect.disabled = true;
  setSettingsDisabled(true);
}

function setSettingsDisabled(disabled: boolean): void {
  overlayVisibleInput.disabled = disabled;
  overlayPositionSelect.disabled = disabled;
  showRomanizationInput.disabled = disabled;
  showGlossInput.disabled = disabled;
}

function overlayPositionFromValue(value: string): OverlayPosition | null {
  if (value === 'bottom' || value === 'top' || value === 'compact') {
    return value;
  }

  return null;
}

function isSupportedVideoPage(pageStatus: PopupState['pageStatus']): boolean {
  return Boolean(pageStatus?.page.supported);
}

function videoLabel(pageStatus: PopupState['pageStatus']): string {
  return pageStatus?.page.supported ? pageStatus.page.videoId : 'No supported video';
}

function videoStateLabel(pageStatus: PopupState['pageStatus']): string {
  if (!isSupportedVideoPage(pageStatus)) {
    return 'Unsupported page';
  }

  return pageStatus?.videoElementFound ? 'Video element detected' : 'Waiting for video element';
}

function overlayModeLabel(mode: OverlayMode): string {
  if (mode === 'processing') {
    return 'Processing';
  }

  if (mode === 'ready') {
    return 'Ready placeholder';
  }

  if (mode === 'error') {
    return 'Error placeholder';
  }

  return 'No track';
}

function shortInstallId(installId: string): string {
  return installId.length > 16 ? `${installId.slice(0, 15)}...` : installId;
}
