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
import type { AccountState, PopupState } from '../../utils/messages';
import { formatHistoryTimestamp, generationProgress } from '../../utils/popup-progress';
import {
  accountStateFromJobHistory,
  formatDurationSeconds,
  formatJobTiming,
  formatResetDate,
  publicJobTelemetry,
  stageTimeline,
} from '../../utils/popup-saas-state';
import {
  DEFAULT_EXTENSION_SETTINGS,
  normalizeSubtitleTimingOffsetSeconds,
  type ExtensionSettings,
} from '../../utils/settings-model';

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

const statusText = document.querySelector<HTMLParagraphElement>('[data-status]')!;
const planPill = document.querySelector<HTMLElement>('[data-plan-pill]')!;
const videoText = document.querySelector<HTMLElement>('[data-video-label]')!;
const videoDurationText = document.querySelector<HTMLElement>('[data-video-duration]')!;
const trackText = document.querySelector<HTMLElement>('[data-track-label]')!;
const jobText = document.querySelector<HTMLElement>('[data-job-label]')!;
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
const showRomanizationInputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[name="showRomanization"]'));
const showTranslationInput = document.querySelector<HTMLInputElement>('input[name="showTranslation"]')!;
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
const usageSummary = document.querySelector<HTMLElement>('[data-usage-summary]')!;
const usageRemaining = document.querySelector<HTMLElement>('[data-usage-remaining]')!;
const usageBar = document.querySelector<HTMLElement>('[data-usage-bar]')!;
const usagePlan = document.querySelector<HTMLElement>('[data-usage-plan]')!;
const usagePending = document.querySelector<HTMLElement>('[data-usage-pending]')!;
const usageReset = document.querySelector<HTMLElement>('[data-usage-reset]')!;
const accountStatus = document.querySelector<HTMLElement>('[data-account-status]')!;
const accountPlan = document.querySelector<HTMLElement>('[data-account-plan]')!;
const accountSpeed = document.querySelector<HTMLElement>('[data-account-speed]')!;
const featureList = document.querySelector<HTMLElement>('[data-feature-list]')!;
const settingsLanguageSummary = document.querySelector<HTMLElement>('[data-settings-language-summary]')!;
const tabButtons = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-tab]'));
const panels = Array.from(document.querySelectorAll<HTMLElement>('[data-panel]'));

let currentSettings: ExtensionSettings | null = null;
let latestState: PopupState | null = null;
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
for (const input of showRomanizationInputs) {
  input.addEventListener('change', () => void updateSettings({ showRomanization: input.checked }));
}
showTranslationInput.addEventListener('change', () =>
  void updateSettings({ showTranslation: showTranslationInput.checked }),
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
  const button = target?.closest<HTMLButtonElement>('[data-action]');
  const action = button?.dataset.action;

  if (action !== 'view-video' && action !== 'retry-job') {
    return;
  }

  const videoUrl = button?.dataset.videoUrl;
  const videoId = button?.dataset.videoId;

  if (!videoUrl) {
    return;
  }

  if (action === 'retry-job' && latestState?.pageStatus?.supported && latestState.pageStatus.videoId === videoId) {
    void generateSubtitles();

    return;
  }

  void browser.tabs.create({ url: videoUrl });
}

function showPopupState(state: PopupState): void {
  latestState = state;

  const pageStatus = state.pageStatus;
  const subtitleState = state.subtitleState;
  const settings = state.settings;
  const supported = Boolean(pageStatus?.supported);
  const { accountState } = state;
  const videoDurationSeconds = videoDurationForState(state);

  currentSettings = settings;
  planPill.textContent = accountState.planName;
  statusText.className = `status ${statusClass(subtitleState.type, supported)}`;
  statusText.textContent = statusLabel(subtitleState.type, supported);
  videoText.textContent = pageStatus?.supported ? pageStatus.videoId : 'No supported video';
  videoDurationText.textContent = pageStatus?.supported ? formatDurationSeconds(videoDurationSeconds) : 'No supported video';
  jobText.textContent = jobIdForState(subtitleState) ?? 'No job';

  showTrackState(state);
  renderJobHistory(state);
  renderUsage(accountState);
  renderAccount(accountState, settings);
  renderSettingsSummary(settings);

  generateButton.disabled = !supported || subtitleState.type === 'loading';
  generateButton.textContent = subtitleState.type === 'loading' ? 'Generating...' : 'Generate subtitles';

  renderLanguagePickers(settings);
  overlayVisibleInput.checked = settings.overlayVisible;
  overlayPositionSelect.value = settings.overlayPosition;
  setChecked(showRomanizationInputs, settings.showRomanization);
  showTranslationInput.checked = settings.showTranslation;
  showGlossInput.checked = settings.showGloss;
  fullTrackEnrichmentInput.checked = settings.fullTrackEnrichment;
  showTimingOffset(settings.subtitleTimingOffsetSeconds);
  setSettingsDisabled(false);
}

function showTrackState(state: PopupState): void {
  const subtitleState = state.subtitleState;

  if (subtitleState.type === 'ready') {
    progressContainer.hidden = true;
    trackText.textContent = `Ready ${shortDisplayId(subtitleState.track.trackId)}`;

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
      ? '<p class="muted empty-state">No languages match that search.</p>'
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
    jobsList.innerHTML = '<p class="muted empty-state">No backend jobs yet.</p>';

    return;
  }

  jobsList.innerHTML = state.jobHistory.map((job) => jobHistoryItemHtml(job, state)).join('');
}

function jobHistoryItemHtml(job: PopupState['jobHistory'][number], state: PopupState): string {
  const telemetry = publicJobTelemetry(job);
  const progress = generationProgress(job);
  const meta = [
    languageRouteLabel(job),
    job.detectedSourceLanguage ? `Detected ${languageLabel(job.detectedSourceLanguage)}` : null,
    formatDurationSeconds(telemetry.videoDurationSeconds),
    formatJobTiming(job),
    formatHistoryTimestamp(job.completedAt ?? job.lastUpdatedAt ?? job.startedAt),
  ]
    .filter((value): value is string => typeof value === 'string' && value !== '')
    .map((value) => `<span>${escapeHtml(value)}</span>`)
    .join('');
  const controls = jobControls(job)
    .map((value) => `<span>${escapeHtml(value)}</span>`)
    .join('');
  const message = telemetry.errorMessage ?? (job.status === 'completed' ? 'Track ready' : progress.stageLabel);

  return `
    <article class="job-item">
      <header>
        <div>
          <span class="job-title">${escapeHtml(job.youtubeVideoId)}</span>
          <p class="job-id">Job ${escapeHtml(telemetry.publicJobId)}</p>
        </div>
        <span class="job-badge ${job.status}">${escapeHtml(job.status)}</span>
      </header>
      <div class="job-meta">${meta}</div>
      <div class="job-controls">${controls}</div>
      ${stageTimelineHtml(job)}
      <p class="job-message ${job.status === 'failed' ? 'error-copy' : ''}">${escapeHtml(message)}</p>
      <div class="job-actions">
        ${
          job.status === 'failed'
            ? `<button class="job-action-button" type="button" data-action="retry-job" data-video-id="${escapeHtml(
                job.youtubeVideoId,
              )}" data-video-url="${escapeHtml(job.youtubeUrl)}">Retry</button>`
            : ''
        }
        <button class="job-action-button" type="button" data-action="view-video" data-video-url="${escapeHtml(
          job.youtubeUrl,
        )}">Open video</button>
      </div>
    </article>
  `;
}

function stageTimelineHtml(job: PopupState['jobHistory'][number]): string {
  return `
    <ol class="stage-timeline" aria-label="Generation stage timeline">
      ${stageTimeline(job)
        .map((item) => `<li class="${item.state}" title="${escapeHtml(item.label)}"><span>${escapeHtml(item.label)}</span></li>`)
        .join('')}
    </ol>
  `;
}

function jobControls(job: PopupState['jobHistory'][number]): string[] {
  const values = [
    job.includeTranslation ? 'Translated cues' : 'Transcript cues',
    job.includeRomanization ? 'Romanization when available' : 'Romanization off',
    job.enrichmentMode === 'full' ? 'Full word cards' : 'On-click word cards',
  ];

  return values;
}

function renderUsage(accountState: AccountState): void {
  const totalCommitted = accountState.monthlyMinutesUsed + accountState.monthlyMinutesPending;
  const percent = accountState.monthlyMinuteLimit === 0
    ? 0
    : Math.min(100, Math.round((totalCommitted / accountState.monthlyMinuteLimit) * 100));

  usageSummary.textContent = `${accountState.monthlyMinutesUsed} of ${accountState.monthlyMinuteLimit} min used`;
  usageRemaining.textContent = `${accountState.monthlyMinutesRemaining} min left`;
  usageBar.style.width = `${percent}%`;
  usagePlan.textContent = `${accountState.planName} (${accountState.tierName})`;
  usagePending.textContent = `${accountState.monthlyMinutesPending} min pending`;
  usageReset.textContent = formatResetDate(accountState.resetAt);
}

function renderAccount(accountState: AccountState, settings: ExtensionSettings): void {
  accountStatus.textContent = accountState.status === 'anonymous' ? 'Not signed in' : accountState.status;
  accountPlan.textContent = accountState.planName;
  accountSpeed.textContent = accountState.tierSpeedLabel;
  featureList.innerHTML = [
    ['Subtitle generation', 'Included'],
    ['Cue translation', settings.showTranslation ? 'On for next job' : 'Available'],
    ['Romanization', settings.showRomanization ? 'On for next job' : 'Available'],
    ['Full word cards', settings.fullTrackEnrichment ? 'On for next job' : 'Available'],
    ['Priority speed', accountState.upgradeAvailable ? 'Upgrade preview' : 'Included'],
  ]
    .map(
      ([label, value]) => `
        <div class="feature-row">
          <span>${escapeHtml(label)}</span>
          <strong>${escapeHtml(value)}</strong>
        </div>
      `,
    )
    .join('');
}

function renderSettingsSummary(settings: ExtensionSettings): void {
  settingsLanguageSummary.textContent = `${languageLabel(settings.sourceLanguage)} to ${languageLabel(settings.targetLanguage)}`;
}

function languageRouteLabel(job: PopupState['jobHistory'][number]): string {
  return `${languageLabel(job.sourceLanguage)} to ${languageLabel(job.targetLanguage)}`;
}

function showTab(tabName: string): void {
  for (const button of tabButtons) {
    const active = button.dataset.tab === tabName;

    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  }

  for (const panel of panels) {
    panel.classList.toggle('active', panel.dataset.panel === tabName);
  }
}

function showError(error: unknown): void {
  const emptyAccountState = accountStateFromJobHistory([]);

  latestState = null;
  currentSettings = null;
  planPill.textContent = 'Offline';
  statusText.className = 'status error';
  statusText.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  videoText.textContent = 'No supported video';
  videoDurationText.textContent = 'Duration pending';
  trackText.textContent = 'No track';
  jobText.textContent = 'No job';
  jobsList.innerHTML = '<p class="muted empty-state">Unable to load jobs.</p>';
  progressContainer.hidden = true;
  renderLanguagePickers(null);
  renderUsage(emptyAccountState);
  renderAccount(emptyAccountState, DEFAULT_EXTENSION_SETTINGS);
  settingsLanguageSummary.textContent = 'Unavailable';
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

function jobIdForState(subtitleState: PopupState['subtitleState']): string | null {
  switch (subtitleState.type) {
    case 'loading':
    case 'error':
      return subtitleState.jobId ? shortDisplayId(subtitleState.jobId) : null;

    case 'ready':
      return shortDisplayId(subtitleState.track.jobId);

    case 'no-track':
      return null;
  }
}

function videoDurationForState(state: PopupState): number | undefined {
  if (typeof state.pageVideoDurationSeconds === 'number') {
    return state.pageVideoDurationSeconds;
  }

  const pageVideoId = state.pageStatus?.supported ? state.pageStatus.videoId : null;
  const matchingJob = pageVideoId
    ? state.jobHistory.find((job) => job.youtubeVideoId === pageVideoId && typeof job.videoDurationSeconds === 'number')
    : undefined;

  return matchingJob?.videoDurationSeconds;
}

function setSettingsDisabled(disabled: boolean): void {
  sourceLanguageSearchInput.disabled = disabled;
  targetLanguageSearchInput.disabled = disabled;
  for (const button of [...sourceLanguageList.querySelectorAll('button'), ...targetLanguageList.querySelectorAll('button')]) {
    button.disabled = disabled;
  }
  overlayVisibleInput.disabled = disabled;
  overlayPositionSelect.disabled = disabled;
  setDisabled(showRomanizationInputs, disabled);
  showTranslationInput.disabled = disabled;
  showGlossInput.disabled = disabled;
  fullTrackEnrichmentInput.disabled = disabled;
  clearStateButton.disabled = disabled;
  resetTimingButton.disabled = disabled;
  timingOffsetRangeInput.disabled = disabled;
  timingOffsetNumberInput.disabled = disabled;
}

function setChecked(inputs: readonly HTMLInputElement[], checked: boolean): void {
  for (const input of inputs) {
    input.checked = checked;
  }
}

function setDisabled(inputs: readonly HTMLInputElement[], disabled: boolean): void {
  for (const input of inputs) {
    input.disabled = disabled;
  }
}

function showTimingOffset(value: number): void {
  const normalized = normalizeSubtitleTimingOffsetSeconds(value);
  const label = `${normalized >= 0 ? '+' : ''}${normalized.toFixed(1)}s`;

  timingOffsetRangeInput.value = String(normalized);
  timingOffsetNumberInput.value = normalized.toFixed(1);
  timingOffsetOutput.value = label;
  timingOffsetOutput.textContent = label;
}

function shortDisplayId(id: string): string {
  return id.length > 13 ? `${id.slice(0, 8)}...${id.slice(-4)}` : id;
}
