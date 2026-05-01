import './style.css';

import { browser } from 'wxt/browser';

import type { PopupState, SubtitleState } from '../../utils/messages';
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
      type: 'popup.generateSubtitles';
    };

type PopupErrorResponse = { ok: false; error: string };
type PopupResponse = PopupState | PopupErrorResponse;

const installIdText = document.querySelector<HTMLParagraphElement>('[data-install-id]')!;
const statusText = document.querySelector<HTMLParagraphElement>('[data-status]')!;
const videoText = document.querySelector<HTMLElement>('[data-video-label]')!;
const trackText = document.querySelector<HTMLElement>('[data-track-label]')!;
const refreshButton = document.querySelector<HTMLButtonElement>('[data-action="refresh"]')!;
const generateButton = document.querySelector<HTMLButtonElement>('[data-action="generate"]')!;
const overlayPositionSelect = document.querySelector<HTMLSelectElement>('select[name="overlayPosition"]')!;
const overlayVisibleInput = document.querySelector<HTMLInputElement>('input[name="overlayVisible"]')!;
const showRomanizationInput = document.querySelector<HTMLInputElement>('input[name="showRomanization"]')!;
const showGlossInput = document.querySelector<HTMLInputElement>('input[name="showGloss"]')!;

refreshButton.addEventListener('click', () => void loadPopupState());
generateButton.addEventListener('click', () => void generateSubtitles());
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

async function generateSubtitles(): Promise<void> {
  await sendPopupRequest({ type: 'popup.generateSubtitles' });
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  await sendPopupRequest({ type: 'popup.updateSettings', patch });
}

async function sendPopupRequest(request: PopupRequest): Promise<void> {
  try {
    const response = (await browser.runtime.sendMessage(request)) as PopupResponse;

    if (isPopupErrorResponse(response)) {
      showError(response.error);

      return;
    }

    showPopupState(response);
  } catch (error) {
    showError(error);
  }
}

function handleOverlayPositionChange(): void {
  const overlayPosition = overlayPositionFromValue(overlayPositionSelect.value);

  if (overlayPosition) {
    void updateSettings({ overlayPosition });
  }
}

function isPopupErrorResponse(response: PopupResponse): response is PopupErrorResponse {
  return 'ok' in response;
}

function showPopupState(state: PopupState): void {
  const pageStatus = state.pageStatus;
  const supported = isSupportedVideoPage(pageStatus);

  installIdText.hidden = false;
  installIdText.textContent = shortInstallId(state.installId);
  statusText.className = `status ${statusClass(state.subtitleState, supported)}`;
  statusText.textContent = videoStateLabel(pageStatus);
  videoText.textContent = videoLabel(pageStatus);
  trackText.textContent = subtitleStateLabel(state.subtitleState);
  generateButton.disabled = !supported || state.subtitleState.type === 'processing';

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
  statusText.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  videoText.textContent = 'No supported video';
  trackText.textContent = 'No track';
  generateButton.disabled = true;
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

function subtitleStateLabel(state: SubtitleState): string {
  if (state.type === 'processing') {
    const progress = state.job.progress;

    return progress ? `${progress.stage} ${progress.percent}%` : 'Processing';
  }

  if (state.type === 'ready') {
    return `Ready ${shortInstallId(state.track.trackId)}`;
  }

  if (state.type === 'error') {
    return state.message;
  }

  return 'No track';
}

function statusClass(state: SubtitleState, supported: boolean): string {
  if (state.type === 'error') {
    return 'error';
  }

  return supported ? 'ok' : 'idle';
}

function shortInstallId(installId: string): string {
  return installId.length > 16 ? `${installId.slice(0, 15)}...` : installId;
}
