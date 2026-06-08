import './style.css';

import { browser } from 'wxt/browser';

import {
  SOURCE_LANGUAGE_OPTIONS,
  TARGET_LANGUAGE_OPTIONS,
  isSourceLanguage,
  isTargetLanguage,
  languageLabel,
} from '../../utils/languages';
import type { AccountState, PopupState } from '../../utils/messages';
import { selectDefaultView } from '../../utils/panel/view-state';
import { generationProgress } from '../../utils/popup-progress';
import { accountStateFromJobHistory, formatResetDate } from '../../utils/popup-saas-state';
import { DEFAULT_EXTENSION_SETTINGS, type ExtensionSettings } from '../../utils/settings-model';
import { accountFeatureListHtml } from '../popup/render/account';
import { renderJobHistory } from '../popup/render/job-history';
import { renderLanguagePicker } from '../popup/render/language-picker';
import { shortcutHelpHtml } from '../popup/render/shortcuts';
import { setupTabs, showTab } from '../popup/tabs';
import { bindTimingOffsetControl } from '../popup/timing-control';
import {
  generateButtonLabel,
  statusClass,
  statusLabel,
  shortDisplayId,
  videoDurationLabel,
} from '../popup/view-model';
import { getPanelDom } from './dom';

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
      type: 'popup.login';
      email: string;
      password: string;
    }
  | {
      type: 'popup.logout';
    }
  | {
      type: 'popup.clearLocalState';
    };

type PopupErrorResponse = { ok: false; error: string };
type PopupResponse = PopupState | PopupErrorResponse;
type RequestErrorTarget = 'global' | 'account';
type AccountFeedbackKind = 'info' | 'success' | 'error';

const BACKEND_REFRESH_INTERVAL_MS = 10000;

const {
  railButtons,
  panels,
  collapseButton,
  nowPlayingEyebrow,
  nowPlayingTitle,
  nowPlayingMeta,
  statusText,
  generateButton,
  clearStateButton,
  resetTimingButton,
  sourceLanguageSearchInput,
  targetLanguageSearchInput,
  sourceLanguageSelected,
  targetLanguageSelected,
  sourceLanguageList,
  targetLanguageList,
  overlayPositionSelect,
  captionFontSizeSelect,
  captionDensitySelect,
  captionContrastThemeSelect,
  overlayVisibleInput,
  showRomanizationInputs,
  showTranslationInput,
  showGlossInput,
  blurSourceWordsInput,
  blurRomanizationInput,
  blurTranslationInput,
  pauseOnWordHoverInput,
  keyboardShortcutsEnabledInput,
  fullTrackEnrichmentInput,
  timingOffsetRangeInput,
  timingOffsetNumberInput,
  timingOffsetOutput,
  progressContainer,
  progressLabel,
  progressPercent,
  progressBar,
  jobsList,
  jobsError,
  usageSummary,
  usageRemaining,
  usageBar,
  usagePlan,
  usagePending,
  usageReset,
  accountStatus,
  accountPlan,
  accountSpeed,
  accountLoginForm,
  accountEmailInput,
  accountPasswordInput,
  accountLoginButton,
  logoutButton,
  accountFeedback,
  featureList,
  settingsLanguageSummary,
  shortcutHelpList,
} = getPanelDom();

let currentSettings: ExtensionSettings | null = null;
let latestState: PopupState | null = null;
let sourceLanguageQuery = '';
let targetLanguageQuery = '';
let accountRequestBusy = false;
let hasAppliedDefaultView = false;

collapseButton.addEventListener('click', () => {
  window.close();
});
generateButton.addEventListener('click', () => void generateSubtitles());
clearStateButton.addEventListener('click', () => void clearLocalState());
accountLoginForm.addEventListener('submit', (event) => void loginFromAccountForm(event));
logoutButton.addEventListener('click', () => void logoutAccount());
accountEmailInput.addEventListener('input', clearAccountFeedback);
accountPasswordInput.addEventListener('input', clearAccountFeedback);
jobsList.addEventListener('click', handleJobsListClick);
sourceLanguageSearchInput.addEventListener('input', handleSourceLanguageSearch);
targetLanguageSearchInput.addEventListener('input', handleTargetLanguageSearch);
sourceLanguageList.addEventListener('click', handleSourceLanguageClick);
targetLanguageList.addEventListener('click', handleTargetLanguageClick);
overlayPositionSelect.addEventListener('change', handleOverlayPositionChange);
captionFontSizeSelect.addEventListener('change', handleCaptionFontSizeChange);
captionDensitySelect.addEventListener('change', handleCaptionDensityChange);
captionContrastThemeSelect.addEventListener('change', handleCaptionContrastThemeChange);
overlayVisibleInput.addEventListener('change', () => void updateSettings({ overlayVisible: overlayVisibleInput.checked }));
for (const input of showRomanizationInputs) {
  input.addEventListener('change', () => void updateSettings({ showRomanization: input.checked }));
}
showTranslationInput.addEventListener('change', () =>
  void updateSettings({ showTranslation: showTranslationInput.checked }),
);
showGlossInput.addEventListener('change', () => void updateSettings({ showGloss: showGlossInput.checked }));
blurSourceWordsInput.addEventListener('change', () =>
  void updateSettings({ blurSourceWords: blurSourceWordsInput.checked }),
);
blurRomanizationInput.addEventListener('change', () =>
  void updateSettings({ blurRomanization: blurRomanizationInput.checked }),
);
blurTranslationInput.addEventListener('change', () =>
  void updateSettings({ blurTranslation: blurTranslationInput.checked }),
);
pauseOnWordHoverInput.addEventListener('change', () =>
  void updateSettings({ pauseOnWordHover: pauseOnWordHoverInput.checked }),
);
keyboardShortcutsEnabledInput.addEventListener('change', () =>
  void updateSettings({ keyboardShortcutsEnabled: keyboardShortcutsEnabledInput.checked }),
);
fullTrackEnrichmentInput.addEventListener('change', () =>
  void updateSettings({ fullTrackEnrichment: fullTrackEnrichmentInput.checked }),
);
const timingControl = bindTimingOffsetControl({
  rangeInput: timingOffsetRangeInput,
  numberInput: timingOffsetNumberInput,
  output: timingOffsetOutput,
  resetButton: resetTimingButton,
  onCommit: (subtitleTimingOffsetSeconds) => updateSettings({ subtitleTimingOffsetSeconds }),
});

setupTabs(railButtons, panels);
renderShortcutHelp();
void loadPanelState();
setInterval(() => void refreshBackendState(), BACKEND_REFRESH_INTERVAL_MS);

let backendRefreshInFlight = false;

async function loadPanelState(): Promise<void> {
  await sendPanelRequest({ type: 'popup.getState', syncBackend: false });
  void refreshBackendState();
}

async function refreshBackendState(): Promise<void> {
  if (backendRefreshInFlight) {
    return;
  }

  backendRefreshInFlight = true;

  try {
    await sendPanelRequest({ type: 'popup.getState', syncBackend: true });
  } finally {
    backendRefreshInFlight = false;
  }
}

async function generateSubtitles(): Promise<void> {
  await sendPanelRequest({ type: 'popup.generateSubtitles' });
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  await sendPanelRequest({ type: 'popup.updateSettings', patch });
}

async function clearLocalState(): Promise<void> {
  await sendPanelRequest({ type: 'popup.clearLocalState' });
}

async function loginFromAccountForm(event: SubmitEvent): Promise<void> {
  event.preventDefault();

  setAccountRequestBusy(true, 'Signing in...');

  try {
    const signedIn = await sendPanelRequest(
      {
        type: 'popup.login',
        email: accountEmailInput.value,
        password: accountPasswordInput.value,
      },
      'account',
    );

    accountPasswordInput.value = '';

    if (signedIn) {
      showAccountFeedback('success', 'Signed in.');
    }
  } finally {
    setAccountRequestBusy(false);
  }
}

async function logoutAccount(): Promise<void> {
  setAccountRequestBusy(true, 'Signing out...');

  try {
    const signedOut = await sendPanelRequest({ type: 'popup.logout' }, 'account');

    if (signedOut) {
      showAccountFeedback('success', 'Signed out.');
    }
  } finally {
    setAccountRequestBusy(false);
  }
}

async function sendPanelRequest(request: PopupRequest, errorTarget: RequestErrorTarget = 'global'): Promise<boolean> {
  try {
    const response = (await browser.runtime.sendMessage(request)) as PopupResponse;

    if ('ok' in response) {
      showRequestError(response.error, errorTarget);

      return false;
    }

    showPanelState(response);

    return true;
  } catch (error) {
    showRequestError(error, errorTarget);

    return false;
  }
}

function handleOverlayPositionChange(): void {
  const { value } = overlayPositionSelect;

  if (value === 'bottom' || value === 'top' || value === 'compact') {
    void updateSettings({ overlayPosition: value });
  }
}

function handleCaptionFontSizeChange(): void {
  const { value } = captionFontSizeSelect;

  if (value === 'small' || value === 'medium' || value === 'large') {
    void updateSettings({ captionFontSize: value });
  }
}

function handleCaptionDensityChange(): void {
  const { value } = captionDensitySelect;

  if (value === 'compact' || value === 'comfortable') {
    void updateSettings({ captionDensity: value });
  }
}

function handleCaptionContrastThemeChange(): void {
  const { value } = captionContrastThemeSelect;

  if (value === 'default' || value === 'high') {
    void updateSettings({ captionContrastTheme: value });
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

function showPanelState(state: PopupState): void {
  latestState = state;

  const pageStatus = state.pageStatus;
  const subtitleState = state.subtitleState;
  const settings = state.settings;
  const supported = Boolean(pageStatus?.supported);
  const { accountState } = state;

  currentSettings = settings;
  statusText.className = `status ${statusClass(subtitleState.type, supported)}`;
  statusText.textContent = statusLabel(subtitleState.type, supported);

  showTrackState(state);
  renderJobHistory(state, { jobsList, jobsError });
  renderUsage(accountState);
  renderAccount(accountState, settings);
  renderSettingsSummary(settings);

  generateButton.disabled = accountState.status !== 'authenticated' || !supported || subtitleState.type === 'loading';
  generateButton.textContent = generateButtonLabel(accountState, subtitleState.type);

  renderLanguagePickers(settings);
  overlayVisibleInput.checked = settings.overlayVisible;
  overlayPositionSelect.value = settings.overlayPosition;
  captionFontSizeSelect.value = settings.captionFontSize;
  captionDensitySelect.value = settings.captionDensity;
  captionContrastThemeSelect.value = settings.captionContrastTheme;
  setChecked(showRomanizationInputs, settings.showRomanization);
  showTranslationInput.checked = settings.showTranslation;
  showGlossInput.checked = settings.showGloss;
  blurSourceWordsInput.checked = settings.blurSourceWords;
  blurRomanizationInput.checked = settings.blurRomanization;
  blurTranslationInput.checked = settings.blurTranslation;
  pauseOnWordHoverInput.checked = settings.pauseOnWordHover;
  keyboardShortcutsEnabledInput.checked = settings.keyboardShortcutsEnabled;
  fullTrackEnrichmentInput.checked = settings.fullTrackEnrichment;
  timingControl.showTimingOffset(settings.subtitleTimingOffsetSeconds);
  setSettingsDisabled(false);

  nowPlayingEyebrow.textContent = supported ? 'Now playing' : 'No video';
  nowPlayingTitle.textContent = supported && pageStatus?.supported ? pageStatus.videoId : 'Open a YouTube video';
  nowPlayingMeta.textContent = supported
    ? `${videoDurationLabel(state)} · ${languageLabel(settings.sourceLanguage)} → ${languageLabel(settings.targetLanguage)}`
    : '';

  if (!hasAppliedDefaultView) {
    hasAppliedDefaultView = true;
    showTab(railButtons, panels, selectDefaultView(state));
  }
}

function showTrackState(state: PopupState): void {
  const subtitleState = state.subtitleState;

  if (subtitleState.type === 'ready') {
    progressContainer.hidden = true;

    return;
  }

  if (subtitleState.type === 'loading') {
    const progress = generationProgress(subtitleState);

    progressContainer.hidden = false;
    progressLabel.textContent = progress.stageLabel;
    progressPercent.textContent = `${progress.percent}%`;
    progressBar.style.width = `${progress.percent}%`;

    return;
  }

  progressContainer.hidden = true;
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
  const authenticated = accountState.status === 'authenticated';

  accountStatus.textContent = authenticated ? accountState.email ?? 'Signed in' : 'Not signed in';
  accountPlan.textContent = accountState.planName;
  accountSpeed.textContent = accountState.tierSpeedLabel;
  accountLoginForm.hidden = authenticated;
  accountEmailInput.disabled = accountRequestBusy || authenticated;
  accountPasswordInput.disabled = accountRequestBusy || authenticated;
  accountLoginButton.disabled = accountRequestBusy || authenticated;
  accountLoginButton.textContent = accountRequestBusy ? 'Signing in...' : 'Sign in';
  logoutButton.disabled = accountRequestBusy || !authenticated;
  featureList.innerHTML = accountFeatureListHtml(accountState, settings);
}

function renderSettingsSummary(settings: ExtensionSettings): void {
  settingsLanguageSummary.textContent = `${languageLabel(settings.sourceLanguage)} to ${languageLabel(settings.targetLanguage)}`;
}

function renderShortcutHelp(): void {
  shortcutHelpList.innerHTML = shortcutHelpHtml();
}

function showError(error: unknown): void {
  const emptyAccountState = accountStateFromJobHistory([]);

  latestState = null;
  currentSettings = null;
  statusText.className = 'status error';
  statusText.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  nowPlayingEyebrow.textContent = 'No video';
  nowPlayingTitle.textContent = 'Open a YouTube video';
  nowPlayingMeta.textContent = '';
  jobsList.innerHTML = '<p class="muted empty-state">Unable to load jobs.</p>';
  progressContainer.hidden = true;
  renderLanguagePickers(null);
  renderUsage(emptyAccountState);
  renderAccount(emptyAccountState, DEFAULT_EXTENSION_SETTINGS);
  settingsLanguageSummary.textContent = 'Unavailable';
  generateButton.disabled = true;
  generateButton.textContent = 'Generate subtitles';
  overlayVisibleInput.checked = DEFAULT_EXTENSION_SETTINGS.overlayVisible;
  overlayPositionSelect.value = DEFAULT_EXTENSION_SETTINGS.overlayPosition;
  captionFontSizeSelect.value = DEFAULT_EXTENSION_SETTINGS.captionFontSize;
  captionDensitySelect.value = DEFAULT_EXTENSION_SETTINGS.captionDensity;
  captionContrastThemeSelect.value = DEFAULT_EXTENSION_SETTINGS.captionContrastTheme;
  keyboardShortcutsEnabledInput.checked = DEFAULT_EXTENSION_SETTINGS.keyboardShortcutsEnabled;
  timingControl.showTimingOffset(0);
  setSettingsDisabled(true);
}

function showRequestError(error: unknown, errorTarget: RequestErrorTarget): void {
  const message = typeof error === 'string'
    ? error
    : error instanceof Error
      ? error.message
      : 'Unable to load extension state';

  if (errorTarget === 'account') {
    showAccountFeedback('error', message);

    return;
  }

  showError(error);
}

function showAccountFeedback(kind: AccountFeedbackKind, message: string): void {
  accountFeedback.hidden = false;
  accountFeedback.className = `account-feedback ${kind}`;
  accountFeedback.textContent = message;
}

function clearAccountFeedback(): void {
  accountFeedback.hidden = true;
  accountFeedback.className = 'account-feedback';
  accountFeedback.textContent = '';
}

function setAccountRequestBusy(busy: boolean, message?: string): void {
  accountRequestBusy = busy;

  if (message) {
    showAccountFeedback('info', message);
  }

  const authenticated = latestState?.accountState.status === 'authenticated';

  accountEmailInput.disabled = busy || authenticated;
  accountPasswordInput.disabled = busy || authenticated;
  accountLoginButton.disabled = busy || authenticated;
  accountLoginButton.textContent = busy ? 'Signing in...' : 'Sign in';
  logoutButton.disabled = busy || !authenticated;
}

function setSettingsDisabled(disabled: boolean): void {
  sourceLanguageSearchInput.disabled = disabled;
  targetLanguageSearchInput.disabled = disabled;
  for (const button of [...sourceLanguageList.querySelectorAll('button'), ...targetLanguageList.querySelectorAll('button')]) {
    button.disabled = disabled;
  }
  overlayVisibleInput.disabled = disabled;
  overlayPositionSelect.disabled = disabled;
  captionFontSizeSelect.disabled = disabled;
  captionDensitySelect.disabled = disabled;
  captionContrastThemeSelect.disabled = disabled;
  setDisabled(showRomanizationInputs, disabled);
  showTranslationInput.disabled = disabled;
  showGlossInput.disabled = disabled;
  blurSourceWordsInput.disabled = disabled;
  blurRomanizationInput.disabled = disabled;
  blurTranslationInput.disabled = disabled;
  pauseOnWordHoverInput.disabled = disabled;
  keyboardShortcutsEnabledInput.disabled = disabled;
  fullTrackEnrichmentInput.disabled = disabled;
  accountEmailInput.disabled = disabled;
  accountPasswordInput.disabled = disabled;
  accountLoginButton.disabled = disabled;
  logoutButton.disabled = disabled || latestState?.accountState.status !== 'authenticated';
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
