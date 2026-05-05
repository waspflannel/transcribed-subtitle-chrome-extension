import './style.css';

import { browser } from 'wxt/browser';

import type { PopupState } from '../../utils/messages';
import { normalizeSubtitleTimingOffsetSeconds, type ExtensionSettings } from '../../utils/settings-model';

type PopupRequest =
  | {
      type: 'popup.getState';
    }
  | {
      type: 'popup.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'popup.generateSubtitles';
    }
  | {
      type: 'popup.clearLocalState';
    };

type PopupErrorResponse = { ok: false; error: string };
type PopupResponse = PopupState | PopupErrorResponse;

const installIdText = document.querySelector<HTMLParagraphElement>('[data-install-id]')!;
const statusText = document.querySelector<HTMLParagraphElement>('[data-status]')!;
const videoText = document.querySelector<HTMLElement>('[data-video-label]')!;
const trackText = document.querySelector<HTMLElement>('[data-track-label]')!;
const refreshButton = document.querySelector<HTMLButtonElement>('[data-action="refresh"]')!;
const generateButton = document.querySelector<HTMLButtonElement>('[data-action="generate"]')!;
const clearStateButton = document.querySelector<HTMLButtonElement>('[data-action="clear-state"]')!;
const resetTimingButton = document.querySelector<HTMLButtonElement>('[data-action="reset-timing"]')!;
const overlayPositionSelect = document.querySelector<HTMLSelectElement>('select[name="overlayPosition"]')!;
const overlayVisibleInput = document.querySelector<HTMLInputElement>('input[name="overlayVisible"]')!;
const showRomanizationInput = document.querySelector<HTMLInputElement>('input[name="showRomanization"]')!;
const showGlossInput = document.querySelector<HTMLInputElement>('input[name="showGloss"]')!;
const timingOffsetRangeInput = document.querySelector<HTMLInputElement>('input[name="subtitleTimingOffsetSeconds"]')!;
const timingOffsetNumberInput = document.querySelector<HTMLInputElement>('input[name="subtitleTimingOffsetNumber"]')!;
const timingOffsetOutput = document.querySelector<HTMLOutputElement>('[data-timing-offset]')!;

refreshButton.addEventListener('click', () => void loadPopupState());
generateButton.addEventListener('click', () => void generateSubtitles());
clearStateButton.addEventListener('click', () => void clearLocalState());
resetTimingButton.addEventListener('click', () => void updateTimingOffset(0));
overlayPositionSelect.addEventListener('change', handleOverlayPositionChange);
overlayVisibleInput.addEventListener('change', () => void updateSettings({ overlayVisible: overlayVisibleInput.checked }));
showRomanizationInput.addEventListener('change', () =>
  void updateSettings({ showRomanization: showRomanizationInput.checked }),
);
showGlossInput.addEventListener('change', () => void updateSettings({ showGloss: showGlossInput.checked }));
timingOffsetRangeInput.addEventListener('input', () => void updateTimingOffset(Number(timingOffsetRangeInput.value)));
timingOffsetNumberInput.addEventListener('change', () =>
  void updateTimingOffset(Number(timingOffsetNumberInput.value)),
);

void loadPopupState();

async function loadPopupState(): Promise<void> {
  await sendPopupRequest({ type: 'popup.getState' });
}

async function generateSubtitles(): Promise<void> {
  await sendPopupRequest({ type: 'popup.generateSubtitles' });
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  await sendPopupRequest({ type: 'popup.updateSettings', patch });
}

async function clearLocalState(): Promise<void> {
  await sendPopupRequest({ type: 'popup.clearLocalState' });
}

async function updateTimingOffset(value: number): Promise<void> {
  const subtitleTimingOffsetSeconds = normalizeSubtitleTimingOffsetSeconds(value);

  showTimingOffset(subtitleTimingOffsetSeconds);
  await updateSettings({ subtitleTimingOffsetSeconds });
}

async function sendPopupRequest(request: PopupRequest): Promise<void> {
  try {
    const response = (await browser.runtime.sendMessage(request)) as PopupResponse;

    if ('ok' in response) {
      showError(response.error);

      return;
    }

    showPopupState(response);
  } catch (error) {
    showError(error);
  }
}

function handleOverlayPositionChange(): void {
  const { value } = overlayPositionSelect;

  if (value === 'bottom' || value === 'top' || value === 'compact') {
    void updateSettings({ overlayPosition: value });
  }
}

function showPopupState(state: PopupState): void {
  const pageStatus = state.pageStatus;
  const subtitleState = state.subtitleState;
  const settings = state.settings;
  const supported = Boolean(pageStatus?.supported);

  installIdText.hidden = false;
  installIdText.textContent = shortInstallId(state.installId);
  statusText.className = `status ${statusClass(subtitleState.type, supported)}`;
  statusText.textContent = statusLabel(subtitleState.type, supported);
  videoText.textContent = pageStatus?.supported ? pageStatus.videoId : 'No supported video';

  if (subtitleState.type === 'ready') {
    trackText.textContent = `Ready ${shortInstallId(subtitleState.track.trackId)}`;
  } else if (subtitleState.type === 'loading') {
    trackText.textContent = subtitleState.message;
  } else if (subtitleState.type === 'error') {
    trackText.textContent = subtitleState.message;
  } else {
    trackText.textContent = 'No track';
  }

  generateButton.disabled = !supported || subtitleState.type === 'loading';
  generateButton.textContent = subtitleState.type === 'loading' ? 'Generating...' : 'Generate subtitles';

  overlayVisibleInput.checked = settings.overlayVisible;
  overlayPositionSelect.value = settings.overlayPosition;
  showRomanizationInput.checked = settings.showRomanization;
  showGlossInput.checked = settings.showGloss;
  showTimingOffset(settings.subtitleTimingOffsetSeconds);
  setSettingsDisabled(false);
}

function showError(error: unknown): void {
  installIdText.hidden = true;
  statusText.className = 'status error';
  statusText.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  videoText.textContent = 'No supported video';
  trackText.textContent = 'No track';
  generateButton.disabled = true;
  generateButton.textContent = 'Generate subtitles';
  showTimingOffset(0);
  setSettingsDisabled(true);
}

function statusClass(subtitleStateType: PopupState['subtitleState']['type'], supported: boolean): string {
  if (subtitleStateType === 'error') {
    return 'error';
  }

  if (subtitleStateType === 'loading') {
    return 'loading';
  }

  return supported ? 'ok' : 'idle';
}

function statusLabel(subtitleStateType: PopupState['subtitleState']['type'], supported: boolean): string {
  if (subtitleStateType === 'error') {
    return 'Generation failed';
  }

  if (subtitleStateType === 'loading') {
    return 'Generating subtitles';
  }

  return supported ? 'Ready to generate' : 'Unsupported page';
}

function setSettingsDisabled(disabled: boolean): void {
  overlayVisibleInput.disabled = disabled;
  overlayPositionSelect.disabled = disabled;
  showRomanizationInput.disabled = disabled;
  showGlossInput.disabled = disabled;
  clearStateButton.disabled = disabled;
  resetTimingButton.disabled = disabled;
  timingOffsetRangeInput.disabled = disabled;
  timingOffsetNumberInput.disabled = disabled;
}

function shortInstallId(installId: string): string {
  return installId.length > 16 ? `${installId.slice(0, 15)}...` : installId;
}

function showTimingOffset(value: number): void {
  const normalized = normalizeSubtitleTimingOffsetSeconds(value);
  const label = `${normalized >= 0 ? '+' : ''}${normalized.toFixed(1)}s`;

  timingOffsetRangeInput.value = String(normalized);
  timingOffsetNumberInput.value = normalized.toFixed(1);
  timingOffsetOutput.value = label;
  timingOffsetOutput.textContent = label;
}
