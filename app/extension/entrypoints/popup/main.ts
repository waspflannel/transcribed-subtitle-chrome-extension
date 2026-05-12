import './style.css';

import { browser } from 'wxt/browser';

import { escapeHtml } from '../../utils/html';
import {
  SOURCE_LANGUAGE_OPTIONS,
  TARGET_LANGUAGE_OPTIONS,
  isSourceLanguage,
  isTargetLanguage,
  languageLabel,
  languageSearchText,
  type LanguageOption,
} from '../../utils/languages';
import type { PopupState } from '../../utils/messages';
import { formatHistoryTimestamp, generationProgress } from '../../utils/popup-progress';
import { normalizeSubtitleTimingOffsetSeconds, type ExtensionSettings } from '../../utils/settings-model';

type PopupRequest =
  | {
      type: 'popup.getState';
      syncBackend?: boolean;
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
const BACKEND_REFRESH_INTERVAL_MS = 10000;

const installIdText = document.querySelector<HTMLParagraphElement>('[data-install-id]')!;
const statusText = document.querySelector<HTMLParagraphElement>('[data-status]')!;
const videoText = document.querySelector<HTMLElement>('[data-video-label]')!;
const trackText = document.querySelector<HTMLElement>('[data-track-label]')!;
const refreshButton = document.querySelector<HTMLButtonElement>('[data-action="refresh"]')!;
const generateButton = document.querySelector<HTMLButtonElement>('[data-action="generate"]')!;
const clearStateButton = document.querySelector<HTMLButtonElement>('[data-action="clear-state"]')!;
const resetTimingButton = document.querySelector<HTMLButtonElement>('[data-action="reset-timing"]')!;
const sourceLanguageSearchInput = document.querySelector<HTMLInputElement>('input[name="sourceLanguageSearch"]')!;
const targetLanguageSearchInput = document.querySelector<HTMLInputElement>('input[name="targetLanguageSearch"]')!;
const sourceLanguageSelected = document.querySelector<HTMLElement>('[data-source-language-selected]')!;
const targetLanguageSelected = document.querySelector<HTMLElement>('[data-target-language-selected]')!;
const sourceLanguageList = document.querySelector<HTMLElement>('[data-source-language-list]')!;
const targetLanguageList = document.querySelector<HTMLElement>('[data-target-language-list]')!;
const overlayPositionSelect = document.querySelector<HTMLSelectElement>('select[name="overlayPosition"]')!;
const overlayVisibleInput = document.querySelector<HTMLInputElement>('input[name="overlayVisible"]')!;
const showRomanizationInput = document.querySelector<HTMLInputElement>('input[name="showRomanization"]')!;
const showGlossInput = document.querySelector<HTMLInputElement>('input[name="showGloss"]')!;
const fullTrackEnrichmentInput = document.querySelector<HTMLInputElement>('input[name="fullTrackEnrichment"]')!;
const timingOffsetRangeInput = document.querySelector<HTMLInputElement>('input[name="subtitleTimingOffsetSeconds"]')!;
const timingOffsetNumberInput = document.querySelector<HTMLInputElement>('input[name="subtitleTimingOffsetNumber"]')!;
const timingOffsetOutput = document.querySelector<HTMLOutputElement>('[data-timing-offset]')!;
const progressContainer = document.querySelector<HTMLElement>('[data-progress]')!;
const progressLabel = document.querySelector<HTMLElement>('[data-progress-label]')!;
const progressPercent = document.querySelector<HTMLElement>('[data-progress-percent]')!;
const progressBar = document.querySelector<HTMLElement>('[data-progress-bar]')!;
const jobsList = document.querySelector<HTMLElement>('[data-jobs-list]')!;
const jobsError = document.querySelector<HTMLElement>('[data-jobs-error]')!;
const tabButtons = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-tab]'));
const panels = Array.from(document.querySelectorAll<HTMLElement>('[data-panel]'));

let currentSettings: ExtensionSettings | null = null;
let sourceLanguageQuery = '';
let targetLanguageQuery = '';

refreshButton.addEventListener('click', () => void loadPopupState());
generateButton.addEventListener('click', () => void generateSubtitles());
clearStateButton.addEventListener('click', () => void clearLocalState());
resetTimingButton.addEventListener('click', () => void updateTimingOffset(0));
jobsList.addEventListener('click', handleJobsListClick);
sourceLanguageSearchInput.addEventListener('input', handleSourceLanguageSearch);
targetLanguageSearchInput.addEventListener('input', handleTargetLanguageSearch);
sourceLanguageList.addEventListener('click', handleSourceLanguageClick);
targetLanguageList.addEventListener('click', handleTargetLanguageClick);
overlayPositionSelect.addEventListener('change', handleOverlayPositionChange);
overlayVisibleInput.addEventListener('change', () => void updateSettings({ overlayVisible: overlayVisibleInput.checked }));
showRomanizationInput.addEventListener('change', () =>
  void updateSettings({ showRomanization: showRomanizationInput.checked }),
);
showGlossInput.addEventListener('change', () => void updateSettings({ showGloss: showGlossInput.checked }));
fullTrackEnrichmentInput.addEventListener('change', () =>
  void updateSettings({ fullTrackEnrichment: fullTrackEnrichmentInput.checked }),
);
timingOffsetRangeInput.addEventListener('input', () => void updateTimingOffset(Number(timingOffsetRangeInput.value)));
timingOffsetNumberInput.addEventListener('change', () =>
  void updateTimingOffset(Number(timingOffsetNumberInput.value)),
);

for (const button of tabButtons) {
  button.addEventListener('click', () => showTab(button.dataset.tab ?? 'generate'));
}

void loadPopupState();
setInterval(() => void refreshBackendState(), BACKEND_REFRESH_INTERVAL_MS);

let backendRefreshInFlight = false;

async function loadPopupState(): Promise<void> {
  await sendPopupRequest({ type: 'popup.getState', syncBackend: false });
  void refreshBackendState();
}

async function refreshBackendState(): Promise<void> {
  if (backendRefreshInFlight) {
    return;
  }

  backendRefreshInFlight = true;

  try {
    await sendPopupRequest({ type: 'popup.getState', syncBackend: true });
  } finally {
    backendRefreshInFlight = false;
  }
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

function handleSourceLanguageSearch(): void {
  sourceLanguageQuery = sourceLanguageSearchInput.value;
  renderLanguagePickers(currentSettings);
}

function handleTargetLanguageSearch(): void {
  targetLanguageQuery = targetLanguageSearchInput.value;
  renderLanguagePickers(currentSettings);
}

function handleSourceLanguageClick(event: MouseEvent): void {
  const code = languageButtonCode(event);

  if (isSourceLanguage(code)) {
    sourceLanguageQuery = '';
    sourceLanguageSearchInput.value = '';
    void updateSettings({ sourceLanguage: code });
  }
}

function handleTargetLanguageClick(event: MouseEvent): void {
  const code = languageButtonCode(event);

  if (isTargetLanguage(code)) {
    targetLanguageQuery = '';
    targetLanguageSearchInput.value = '';
    void updateSettings({ targetLanguage: code });
  }
}

function languageButtonCode(event: MouseEvent): string | null {
  const target = event.target instanceof Element ? event.target : null;
  const button = target?.closest<HTMLButtonElement>('[data-language-code]');

  return button?.dataset.languageCode ?? null;
}

function handleJobsListClick(event: MouseEvent): void {
  const target = event.target instanceof Element ? event.target : null;
  const button = target?.closest<HTMLButtonElement>('[data-action="view-video"]');
  const videoUrl = button?.dataset.videoUrl;

  if (!videoUrl) {
    return;
  }

  void browser.tabs.create({ url: videoUrl });
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
  showTrackState(state);
  renderJobHistory(state);
  currentSettings = settings;

  generateButton.disabled = !supported || subtitleState.type === 'loading';
  generateButton.textContent = subtitleState.type === 'loading' ? 'Generating...' : 'Generate subtitles';

  renderLanguagePickers(settings);
  overlayVisibleInput.checked = settings.overlayVisible;
  overlayPositionSelect.value = settings.overlayPosition;
  showRomanizationInput.checked = settings.showRomanization;
  showGlossInput.checked = settings.showGloss;
  fullTrackEnrichmentInput.checked = settings.fullTrackEnrichment;
  showTimingOffset(settings.subtitleTimingOffsetSeconds);
  setSettingsDisabled(false);
}

function showTrackState(state: PopupState): void {
  const subtitleState = state.subtitleState;

  if (subtitleState.type === 'ready') {
    progressContainer.hidden = true;
    trackText.textContent = `Ready ${shortInstallId(subtitleState.track.trackId)}`;

    return;
  }

  if (subtitleState.type === 'loading') {
    const progress = generationProgress(subtitleState);

    trackText.textContent = subtitleState.message;
    progressContainer.hidden = false;
    progressLabel.textContent = progress.stageLabel;
    progressPercent.textContent = `${progress.percent}%`;
    progressBar.style.width = `${progress.percent}%`;

    return;
  }

  progressContainer.hidden = true;
  trackText.textContent = subtitleState.type === 'error' ? subtitleState.message : 'No track';
}

function renderLanguagePickers(settings: ExtensionSettings | null): void {
  renderLanguagePicker({
    options: SOURCE_LANGUAGE_OPTIONS,
    query: sourceLanguageQuery,
    selectedCode: settings?.sourceLanguage,
    selectedContainer: sourceLanguageSelected,
    listContainer: sourceLanguageList,
    disabled: sourceLanguageSearchInput.disabled,
  });
  renderLanguagePicker({
    options: TARGET_LANGUAGE_OPTIONS,
    query: targetLanguageQuery,
    selectedCode: settings?.targetLanguage,
    selectedContainer: targetLanguageSelected,
    listContainer: targetLanguageList,
    disabled: targetLanguageSearchInput.disabled,
  });
}

function renderLanguagePicker(options: {
  options: readonly LanguageOption[];
  query: string;
  selectedCode: string | undefined;
  selectedContainer: HTMLElement;
  listContainer: HTMLElement;
  disabled: boolean;
}): void {
  const selectedLanguage = options.options.find((language) => language.code === options.selectedCode);
  const normalizedQuery = options.query.trim().toLowerCase();
  const visibleLanguages =
    normalizedQuery === ''
      ? options.options
      : options.options.filter((language) => languageSearchText(language).includes(normalizedQuery));

  options.selectedContainer.innerHTML = selectedLanguage
    ? selectedLanguageSummary(selectedLanguage)
    : '<span class="muted">No language selected</span>';
  options.listContainer.innerHTML =
    visibleLanguages.length === 0
      ? '<p class="muted">No languages match that search.</p>'
      : visibleLanguages
          .map((language) => languageOptionButton(language, language.code === options.selectedCode, options.disabled))
          .join('');
}

function selectedLanguageSummary(language: LanguageOption): string {
  return `
    <span>${escapeHtml(language.label)}</span>
    <span class="language-code">${escapeHtml(language.code)}</span>
    ${languageBadge(language)}
  `;
}

function languageOptionButton(language: LanguageOption, selected: boolean, disabled: boolean): string {
  return `
    <button
      type="button"
      class="language-option${selected ? ' selected' : ''}"
      data-language-code="${escapeHtml(language.code)}"
      role="option"
      aria-selected="${selected ? 'true' : 'false'}"
      ${disabled ? 'disabled' : ''}
    >
      <span class="language-option-main">
        <span>${escapeHtml(language.label)}</span>
        <span class="language-code">${escapeHtml(language.code)}</span>
      </span>
      ${languageBadge(language)}
    </button>
  `;
}

function languageBadge(language: Pick<LanguageOption, 'tier'>): string {
  const labels: Record<LanguageOption['tier'], string> = {
    auto: 'Auto',
    excellent: 'Excellent',
    high: 'High Accuracy',
    good: 'Good',
    moderate: 'Moderate',
  };

  return `<span class="language-badge ${language.tier}">${labels[language.tier]}</span>`;
}

function renderJobHistory(state: PopupState): void {
  if (state.jobHistoryError) {
    jobsError.hidden = false;
    jobsError.textContent = state.jobHistoryError;
  } else {
    jobsError.hidden = true;
    jobsError.textContent = '';
  }

  if (state.jobHistory.length === 0) {
    jobsList.innerHTML = '<p class="muted">No backend jobs yet.</p>';

    return;
  }

  jobsList.innerHTML = state.jobHistory
    .map((job) => {
      const progress = generationProgress(job);
      const meta = [
        languageRouteLabel(job),
        job.detectedSourceLanguage ? `Detected ${languageLabel(job.detectedSourceLanguage)}` : null,
        formatHistoryTimestamp(job.completedAt ?? job.lastUpdatedAt ?? job.startedAt),
        job.status === 'running' ? `${progress.percent}%` : null,
      ]
        .filter((value): value is string => typeof value === 'string')
        .map((value) => `<span>${escapeHtml(value)}</span>`)
        .join('');

      return `
        <article class="job-item">
          <header>
            <span class="job-title">${escapeHtml(job.youtubeVideoId)}</span>
            <span class="job-badge ${job.status}">${escapeHtml(job.status)}</span>
          </header>
          <div class="job-meta">${meta}</div>
          <p class="muted">${escapeHtml(job.status === 'running' ? progress.stageLabel : job.message ?? 'Track ready')}</p>
          <div class="job-actions">
            <button class="job-action-button" type="button" data-action="view-video" data-video-url="${escapeHtml(
              job.youtubeUrl,
            )}">View video</button>
          </div>
        </article>
      `;
    })
    .join('');
}

function languageRouteLabel(job: PopupState['jobHistory'][number]): string {
  return `${languageLabel(job.sourceLanguage)} to ${languageLabel(job.targetLanguage)}`;
}

function showTab(tabName: string): void {
  for (const button of tabButtons) {
    button.classList.toggle('active', button.dataset.tab === tabName);
  }

  for (const panel of panels) {
    panel.classList.toggle('active', panel.dataset.panel === tabName);
  }
}

function showError(error: unknown): void {
  currentSettings = null;
  installIdText.hidden = true;
  statusText.className = 'status error';
  statusText.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  videoText.textContent = 'No supported video';
  trackText.textContent = 'No track';
  jobsList.innerHTML = '<p class="muted">Unable to load jobs.</p>';
  progressContainer.hidden = true;
  renderLanguagePickers(null);
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
  sourceLanguageSearchInput.disabled = disabled;
  targetLanguageSearchInput.disabled = disabled;
  for (const button of [...sourceLanguageList.querySelectorAll('button'), ...targetLanguageList.querySelectorAll('button')]) {
    button.disabled = disabled;
  }
  overlayVisibleInput.disabled = disabled;
  overlayPositionSelect.disabled = disabled;
  showRomanizationInput.disabled = disabled;
  showGlossInput.disabled = disabled;
  fullTrackEnrichmentInput.disabled = disabled;
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
