import './style.css';

import { browser } from 'wxt/browser';

import { isOverlayMode, type OverlayMode, type PopupState } from '../../utils/messages';
import type { ExtensionSettings, OverlayPosition } from '../../utils/settings-model';

const app = document.querySelector<HTMLDivElement>('#app')!;

let popupState: PopupState | null = null;
let popupError: string | null = null;

app.addEventListener('change', (event) => {
  const target = event.target;

  if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) {
    return;
  }

  if (target.name === 'overlayMode' && isOverlayMode(target.value)) {
    void setOverlayMode(target.value);

    return;
  }

  const overlayPosition = target.value;

  if (
    target.name === 'overlayPosition' &&
    (overlayPosition === 'bottom' || overlayPosition === 'top' || overlayPosition === 'compact')
  ) {
    void updateSettings({ overlayPosition });

    return;
  }

  if (target instanceof HTMLInputElement && target.name === 'overlayVisible') {
    void updateSettings({ overlayVisible: target.checked });

    return;
  }

  if (target instanceof HTMLInputElement && target.name === 'showRomanization') {
    void updateSettings({ showRomanization: target.checked });

    return;
  }

  if (target instanceof HTMLInputElement && target.name === 'showGloss') {
    void updateSettings({ showGloss: target.checked });
  }
});

app.addEventListener('click', (event) => {
  const target = event.target;

  if (target instanceof HTMLButtonElement && target.dataset.action === 'refresh') {
    void refreshState();
  }
});

void refreshState();

async function refreshState(): Promise<void> {
  try {
    popupState = await browser.runtime.sendMessage({ type: 'popup.getState' });
    popupError = null;
  } catch (error) {
    popupError = error instanceof Error ? error.message : 'Unable to load extension state';
  }

  render();
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  popupState = await browser.runtime.sendMessage({ type: 'popup.updateSettings', patch });
  popupError = null;
  render();
}

async function setOverlayMode(mode: OverlayMode): Promise<void> {
  popupState = await browser.runtime.sendMessage({ type: 'popup.setOverlayMode', mode });
  popupError = null;
  render();
}

function render(): void {
  if (popupError) {
    app.innerHTML = `
      <main>
        <header class="header">
          <h1>AI Subtitles</h1>
          <button type="button" class="refresh-button" data-action="refresh">Refresh</button>
        </header>
        <p class="status error">${escapeHtml(popupError)}</p>
      </main>
    `;

    return;
  }

  if (!popupState) {
    app.innerHTML = `
      <main>
        <header class="header">
          <h1>AI Subtitles</h1>
        </header>
        <p class="muted">Loading</p>
      </main>
    `;

    return;
  }

  const pageStatus = popupState.pageStatus;
  const supported = Boolean(pageStatus?.page.supported);
  const settings = popupState.settings;
  const videoLabel = pageStatus?.page.supported ? pageStatus.page.videoId : 'No supported video';
  const videoState = supported
    ? pageStatus?.videoElementFound
      ? 'Video element detected'
      : 'Waiting for video element'
    : 'Unsupported page';

  app.innerHTML = `
    <main>
      <header class="header">
        <div>
          <h1>AI Subtitles</h1>
          <p class="muted">${escapeHtml(shortInstallId(popupState.installId))}</p>
        </div>
        <button type="button" class="refresh-button" data-action="refresh">Refresh</button>
      </header>

      <section class="section">
        <p class="status ${supported ? 'ok' : 'idle'}">${escapeHtml(videoState)}</p>
        <dl class="facts">
          <div>
            <dt>Video</dt>
            <dd>${escapeHtml(videoLabel)}</dd>
          </div>
          <div>
            <dt>Track</dt>
            <dd>${escapeHtml(overlayModeLabel(popupState.overlayMode))}</dd>
          </div>
        </dl>
      </section>

      <section class="section">
        <label class="field">
          <span>Overlay state</span>
          <select name="overlayMode" ${supported ? '' : 'disabled'}>
            ${overlayModeOption('no-track', popupState.overlayMode)}
            ${overlayModeOption('processing', popupState.overlayMode)}
            ${overlayModeOption('ready', popupState.overlayMode)}
            ${overlayModeOption('error', popupState.overlayMode)}
          </select>
        </label>
      </section>

      <section class="section">
        <label class="toggle">
          <input type="checkbox" name="overlayVisible" ${settings.overlayVisible ? 'checked' : ''} />
          <span>Show overlay</span>
        </label>
        <label class="field">
          <span>Position</span>
          <select name="overlayPosition">
            ${positionOption('bottom', settings.overlayPosition)}
            ${positionOption('top', settings.overlayPosition)}
            ${positionOption('compact', settings.overlayPosition)}
          </select>
        </label>
        <label class="toggle">
          <input type="checkbox" name="showRomanization" ${settings.showRomanization ? 'checked' : ''} />
          <span>Romanization</span>
        </label>
        <label class="toggle">
          <input type="checkbox" name="showGloss" ${settings.showGloss ? 'checked' : ''} />
          <span>Gloss</span>
        </label>
      </section>
    </main>
  `;
}

function overlayModeOption(value: OverlayMode, current: OverlayMode): string {
  return `<option value="${value}" ${value === current ? 'selected' : ''}>${escapeHtml(overlayModeLabel(value))}</option>`;
}

function positionOption(value: OverlayPosition, current: OverlayPosition): string {
  const label = value[0].toUpperCase() + value.slice(1);

  return `<option value="${value}" ${value === current ? 'selected' : ''}>${label}</option>`;
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

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
