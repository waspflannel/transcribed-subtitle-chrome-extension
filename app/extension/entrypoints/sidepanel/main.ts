import './style.css';

import { browser } from 'wxt/browser';

import {
  SOURCE_LANGUAGE_OPTIONS,
  TARGET_LANGUAGE_OPTIONS,
  isSourceLanguage,
  isTargetLanguage,
  languageLabel,
} from '../../utils/languages';
import { isRuntimeMessage } from '../../utils/messages';
import type { AccountState, PanelRequest, PanelState } from '../../utils/messages';
import { generationProgress } from '../../utils/panel-progress';
import { anonymousAccountState, formatResetDate, stageTimeline } from '../../utils/account-state';
import { escapeHtml } from '../../utils/html';
import { DEFAULT_EXTENSION_SETTINGS, type ExtensionSettings } from '../../utils/settings-model';
import { accountFeatureListHtml } from './render/account';
import { renderJobHistory } from './render/job-history';
import { renderLanguagePicker } from './render/language-picker';
import { shortcutHelpHtml } from './render/shortcuts';
import { setupTabs, showTab } from './tabs';
import { bindTimingOffsetControl } from './timing-control';
import { bindTranscriptView } from './transcript-view';
import {
  generateButtonLabel,
  nowPlayingTitleLabel,
  videoDurationForState,
  videoDurationLabel,
} from './view-model';
import { getPanelDom } from './dom';
import { canApplyLyricsCorrection, lyricsCharacterCount, lyricsCorrectionProgress, LYRICS_CORRECTION_STAGES, QUICK_FIX_CHARACTER_LIMIT } from '../../utils/lyrics-correction';
import type { LyricsCorrectionStatus } from '../../utils/contracts';
import {
  beginPanelRequest,
  canApplyPanelResponse,
  finishPanelRequest,
  panelRequestOrder,
  type PanelRequestOrder,
} from '../../utils/panel-request-order';

type PanelErrorResponse = { ok: false; error: string; errorCode?: string; details?: { reason?: string } };
type PanelResponse = PanelState | PanelErrorResponse;
type RequestErrorTarget = 'global' | 'account' | 'correction' | 'quickfix' | 'cancel';
type AccountFeedbackKind = 'info' | 'success' | 'error';

const {
  tabButtons,
  panels,
  transcriptSearch,
  transcriptList,
  transcriptStatus,
  collapseButton,
  nowPlayingEyebrow,
  nowPlayingTitle,
  nowPlayingMeta,
  statusBanner,
  watchUnsupported,
  watchSignin,
  watchSetup,
  watchReady,
  correctionTerminalStatus,
  correctionCancelError,
  lyricsEditPanel,
  toggleLyricsEditButton,
  lyricsEditModeButtons,
  lyricsReplacePanel,
  lyricsQuickPanel,
  lyricsCorrectionForm,
  lyricsCorrectionTextarea,
  lyricsCorrectionCount,
  lyricsCorrectionStatus,
  lyricsCorrectionButton,
  lyricsConfirmation,
  confirmLyricsCorrectionButton,
  cancelLyricsConfirmationButton,
  cancelLyricsCorrectionButton,
  quickFixStatus,
  openAccountButton,
  toggleLanguagesButton,
  languageExpand,
  toggleSetupButton,
  pairSourceCode,
  pairSourceName,
  pairTargetCode,
  pairTargetName,
  generateButton,
  generateNote,
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
  showRomanizationInput,
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
  progressPercent,
  progressActivity,
  progressBar,
  progressStages,
  progressLabel,
  progressCopy,
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
let latestState: PanelState | null = null;
let sourceLanguageQuery = '';
let targetLanguageQuery = '';
let accountRequestBusy = false;
let lyricsCorrectionRequestBusy = false;
let quickFixRequestBusy = false;
let lyricsCancellationRequestBusy = false;
let hasAppliedDefaultView = false;
let panelWindowId: number | undefined;
let tabChangeTimer: ReturnType<typeof setTimeout> | undefined;
let stateSeq = 0;
let latestAppliedSeq = 0;
let panelRequestState: PanelRequestOrder = panelRequestOrder();
let lastProgressAnnouncement: string | null = null;

/* Watch-tab UI state: the language pickers and the ready-state setup card
   are collapsed by default and expand on request. */
let languagesExpanded = false;
let setupExpandedWhileReady = false;
let lastWatchVideoId: string | null = null;
let lyricsEditOpen = false;
let lyricsEditMode: 'replace' | 'quick' = 'quick';
let lyricsReplaceConfirm = false;
let quickFixSelection: { cueId: string; tokenIndex: number; text: string } | null = null;
let quickFixError: { message: string; code?: string; reason?: string } | null = null;
let quickFixNotice: string | null = null;

collapseButton.addEventListener('click', () => {
  window.close();
});
generateButton.addEventListener('click', () => void generateSubtitles());
lyricsCorrectionForm.addEventListener('submit', (event) => void submitLyricsCorrection(event));
lyricsCorrectionTextarea.addEventListener('input', () => {
  /* Editing the paste after Continue drops back out of the confirmation step. */
  lyricsReplaceConfirm = false;
  renderLyricsEditState();
});
toggleLyricsEditButton.addEventListener('click', () => {
  lyricsEditOpen = !lyricsEditOpen;
  if (!lyricsEditOpen) clearQuickFixSelection();
  renderLyricsEditState();
});
for (const button of lyricsEditModeButtons) {
  button.addEventListener('click', () => {
    lyricsEditMode = button.dataset.mode === 'replace' ? 'replace' : 'quick';
    lyricsReplaceConfirm = false;
    clearQuickFixSelection();
    quickFixNotice = null;
    renderLyricsEditState();
  });
}
confirmLyricsCorrectionButton.addEventListener('click', () => void applyConfirmedLyricsCorrection());
cancelLyricsConfirmationButton.addEventListener('click', () => {
  lyricsReplaceConfirm = false;
  renderLyricsEditState();
  lyricsCorrectionTextarea.focus();
});
cancelLyricsCorrectionButton.addEventListener('click', () => void cancelLyricsCorrection());
clearStateButton.addEventListener('click', () => void clearLocalState());
openAccountButton.addEventListener('click', () => {
  showTab(tabButtons, panels, 'account');
  accountEmailInput.focus();
});
toggleLanguagesButton.addEventListener('click', () => {
  setLanguagesExpanded(!languagesExpanded);
});
toggleSetupButton.addEventListener('click', () => {
  setupExpandedWhileReady = !setupExpandedWhileReady;
  toggleSetupButton.setAttribute('aria-expanded', setupExpandedWhileReady ? 'true' : 'false');
  if (latestState) showPanelState(latestState);
});
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
showRomanizationInput.addEventListener('change', () =>
  void updateSettings({ showRomanization: showRomanizationInput.checked }),
);
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

let backendRefreshInFlight = false;
let backendPollTimer: ReturnType<typeof setTimeout> | undefined;
const ACTIVE_POLL_INTERVAL_MS = 10_000;
const IDLE_POLL_INTERVAL_MS = 30_000;

setupTabs(tabButtons, panels);
const transcriptView = bindTranscriptView({
  transcriptSearch,
  transcriptList,
  transcriptStatus,
  onQuickFixSelect: selectQuickFixToken,
  onQuickFixSave: (cueId, tokenIndex, value) => void submitQuickFix(cueId, tokenIndex, value),
  onQuickFixCancel: () => {
    clearQuickFixSelection();
    renderLyricsEditState();
    toggleLyricsEditButton.focus();
  },
});
renderShortcutHelp();
void loadPanelState();
scheduleNextBackendPoll();

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') {
    void refreshBackendState();
    scheduleNextBackendPoll();
  }
});

void resolvePanelWindowId().then(attachTabListeners);

function connectPanel(): void {
  try {
    browser.runtime.connect({ name: 'panel' }).onDisconnect.addListener(() => {
      setTimeout(connectPanel, 1000);
    });
  } catch {
    setTimeout(connectPanel, 1000);
  }
}

connectPanel();

browser.runtime.onMessage.addListener((message) => {
  if (!isRuntimeMessage(message)) return;
  if (message.type === 'background.activeCueChanged') {
    transcriptView.setActiveCue(message.cueId);
  } else if (message.type === 'background.focusTranscript') {
    showTab(tabButtons, panels, 'watch');
    transcriptView.focus();
  }
});

function scheduleNextBackendPoll(): void {
  if (backendPollTimer) clearTimeout(backendPollTimer);
  const hasInFlightJob = latestState?.subtitleState.type === 'loading'
    || latestState?.lyricsCorrection?.status === 'queued'
    || latestState?.lyricsCorrection?.status === 'running';
  const interval = hasInFlightJob ? ACTIVE_POLL_INTERVAL_MS : IDLE_POLL_INTERVAL_MS;
  backendPollTimer = setTimeout(() => {
    if (document.visibilityState === 'visible') {
      void refreshBackendState();
    }
    scheduleNextBackendPoll();
  }, interval);
}

async function loadPanelState(): Promise<void> {
  await sendPanelRequest({ type: 'panel.getState', syncBackend: false });
  void refreshBackendState();
}

async function resolvePanelWindowId(): Promise<void> {
  try {
    const win = await browser.windows.getCurrent();
    panelWindowId = typeof win.id === 'number' ? win.id : undefined;
  } catch {
    panelWindowId = undefined;
  }
}

function attachTabListeners(): void {
  browser.tabs.onActivated.addListener((info) => {
    if (panelWindowId !== undefined && info.windowId !== panelWindowId) return;
    scheduleTabChangeRefresh();
  });

  browser.tabs.onUpdated.addListener((_tabId, changeInfo, tab) => {
    if (panelWindowId !== undefined && tab.windowId !== panelWindowId) return;
    if (!tab.active) return;
    if (!changeInfo.url) return;
    scheduleTabChangeRefresh();
  });
}

function scheduleTabChangeRefresh(): void {
  if (tabChangeTimer) clearTimeout(tabChangeTimer);
  tabChangeTimer = setTimeout(() => void onActiveTabChanged(), 60);
}

async function onActiveTabChanged(): Promise<void> {
  await sendPanelRequest({ type: 'panel.getState', syncBackend: false });
  void refreshBackendState();
}

async function refreshBackendState(): Promise<void> {
  if (backendRefreshInFlight) {
    return;
  }

  backendRefreshInFlight = true;

  try {
    await sendPanelRequest({ type: 'panel.getState', syncBackend: true });
  } finally {
    backendRefreshInFlight = false;
  }
}

async function generateSubtitles(): Promise<void> {
  if (lyricsCorrectionRequestBusy || quickFixRequestBusy || lyricsCancellationRequestBusy || isActiveLyricsCorrection(latestState?.lyricsCorrection)) return;
  await sendPanelRequest({ type: 'panel.generateSubtitles' }, 'global', 'mutation');
}

async function submitLyricsCorrection(event: SubmitEvent): Promise<void> {
  event.preventDefault();
  if (!canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection)) return;
  lyricsReplaceConfirm = true;
  renderLyricsEditState();
  confirmLyricsCorrectionButton.focus();
}

async function applyConfirmedLyricsCorrection(): Promise<void> {
  const state = latestState;
  const subtitleState = state?.subtitleState;
  const page = state?.pageStatus;

  if (lyricsCorrectionRequestBusy || !state || subtitleState?.type !== 'ready' || !page?.supported || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, state.lyricsCorrection)) return;

  lyricsCorrectionRequestBusy = true;
  renderLyricsEditState();

  try {
    const applied = await sendPanelRequest({
      type: 'panel.submitLyricsCorrection',
      jobId: subtitleState.track.jobId,
      trackId: subtitleState.track.trackId,
      youtubeVideoId: page.videoId,
      lyrics: lyricsCorrectionTextarea.value,
    }, 'correction', 'mutation');
    if (applied) {
      (isActiveLyricsCorrection(latestState?.lyricsCorrection) ? cancelLyricsCorrectionButton : toggleLyricsEditButton).focus();
    }
    lyricsReplaceConfirm = false;
  } finally {
    lyricsCorrectionRequestBusy = false;
    renderLyricsEditState();
  }
}

function renderLyricsCorrectionInput(): void {
  const count = lyricsCharacterCount(lyricsCorrectionTextarea.value);
  lyricsCorrectionCount.textContent = `${count.toLocaleString()} / 25,000 characters`;
  lyricsCorrectionButton.disabled = lyricsCorrectionRequestBusy || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection);
}

async function cancelLyricsCorrection(): Promise<void> {
  const state = latestState;
  const page = state?.pageStatus;
  const track = state?.subtitleState.type === 'ready' ? state.subtitleState.track : null;

  if (lyricsCancellationRequestBusy || !state || !page?.supported || !track || !isActiveLyricsCorrection(state.lyricsCorrection)) return;

  lyricsCancellationRequestBusy = true;
  correctionCancelError.hidden = true;
  correctionCancelError.textContent = '';
  renderLyricsEditState();
  try {
    const applied = await sendPanelRequest({
      type: 'panel.cancelLyricsCorrection',
      jobId: track.jobId,
      trackId: track.trackId,
      attemptId: state.lyricsCorrection!.attemptId,
      youtubeVideoId: page.videoId,
    }, 'cancel', 'mutation');
    if (applied) toggleLyricsEditButton.focus();
  } finally {
    lyricsCancellationRequestBusy = false;
    renderLyricsEditState();
  }
}

function selectQuickFixToken(cueId: string, tokenIndex: number): void {
  if (!latestState || latestState.subtitleState.type !== 'ready') return;

  const cue = latestState.subtitleState.track.cues.find((candidate) => candidate.cueId === cueId);
  const token = cue?.tokens.find((candidate) => candidate.index === tokenIndex);

  if (!cue || !token) return;

  quickFixSelection = { cueId, tokenIndex, text: token.text };
  quickFixError = null;
  quickFixNotice = null;
  renderLyricsEditState();
}

async function submitQuickFix(cueId: string, tokenIndex: number, text: string): Promise<void> {
  const state = latestState;
  const page = state?.pageStatus;
  const track = state?.subtitleState.type === 'ready' ? state.subtitleState.track : null;
  const selection = quickFixSelection;

  if (
    quickFixRequestBusy
    || !state
    || !page?.supported
    || !track
    || !selection
    || selection.cueId !== cueId
    || selection.tokenIndex !== tokenIndex
    || isActiveLyricsCorrection(state.lyricsCorrection)
    || text.trim() === selection.text.trim()
    || lyricsCharacterCount(text) === 0
    || lyricsCharacterCount(text) > QUICK_FIX_CHARACTER_LIMIT
  ) return;

  quickFixRequestBusy = true;
  transcriptView.setQuickFixBusy(true);
  renderLyricsEditState();

  const applied = await sendPanelRequest({
    type: 'panel.quickFixToken',
    jobId: track.jobId,
    trackId: track.trackId,
    youtubeVideoId: page.videoId,
    cueId,
    tokenIndex,
    text,
  }, 'quickfix', 'mutation');

  quickFixRequestBusy = false;
  transcriptView.setQuickFixBusy(false);

  if (applied) {
    clearQuickFixSelection();
    quickFixNotice = 'Saved.';
    toggleLyricsEditButton.focus();
  } else if (quickFixError?.code === 'lyrics_correction_in_progress' && quickFixError.reason === 'stale_track') {
    clearQuickFixSelection();
    quickFixNotice = 'The subtitles changed — the latest version is loaded.';
    void refreshBackendState();
    toggleLyricsEditButton.focus();
  } else {
    transcriptView.setQuickFixError(quickFixError?.message ?? 'Could not save. Try again.');
  }

  renderLyricsEditState();
}

function clearQuickFixSelection(): void {
  quickFixSelection = null;
  quickFixError = null;
}

function isActiveLyricsCorrection(status: LyricsCorrectionStatus | null | undefined): boolean {
  return status?.status === 'queued' || status?.status === 'running';
}

function renderLyricsEditState(): void {
  const ready = latestState?.subtitleState.type === 'ready' && Date.parse(latestState.subtitleState.track.expiresAt) > Date.now();
  const activeCorrection = isActiveLyricsCorrection(latestState?.lyricsCorrection);
  const editOpen = lyricsEditOpen && ready;
  const quickActive = editOpen && lyricsEditMode === 'quick' && !activeCorrection;

  /* Drop a selection whose cue no longer exists (e.g. the track rotated). */
  if (quickFixSelection) {
    const track = latestState?.subtitleState.type === 'ready' ? latestState.subtitleState.track : null;
    if (!quickActive || !track?.cues.some((cue) => cue.cueId === quickFixSelection!.cueId)) {
      clearQuickFixSelection();
    }
  }

  lyricsEditPanel.hidden = !editOpen;
  toggleLyricsEditButton.disabled = !ready || lyricsCorrectionRequestBusy || quickFixRequestBusy;
  toggleLyricsEditButton.setAttribute('aria-expanded', editOpen ? 'true' : 'false');
  lyricsReplacePanel.hidden = lyricsEditMode !== 'replace';
  lyricsQuickPanel.hidden = lyricsEditMode !== 'quick';

  /* Replace all: Continue reveals the inline warning and swaps the footer
     actions; the pasted text stays visible while confirming. */
  lyricsConfirmation.hidden = !lyricsReplaceConfirm;
  lyricsCorrectionButton.hidden = lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.hidden = !lyricsReplaceConfirm;
  cancelLyricsConfirmationButton.hidden = !lyricsReplaceConfirm;
  lyricsCorrectionTextarea.disabled = lyricsCorrectionRequestBusy;
  confirmLyricsCorrectionButton.disabled = lyricsCorrectionRequestBusy || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection);

  for (const button of lyricsEditModeButtons) {
    const active = button.dataset.mode === lyricsEditMode;
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', active ? 'true' : 'false');
    button.disabled = lyricsCorrectionRequestBusy || quickFixRequestBusy;
  }

  quickFixStatus.textContent = activeCorrection && editOpen && lyricsEditMode === 'quick'
    ? 'Finish or cancel the replacement before fixing a word.'
    : quickFixNotice ?? '';

  transcriptView.setQuickFixMode(quickActive);
  transcriptView.setQuickFixEditing(quickActive ? quickFixSelection : null);
  renderLyricsCorrectionInput();
  cancelLyricsCorrectionButton.hidden = !activeCorrection;
  cancelLyricsCorrectionButton.disabled = lyricsCancellationRequestBusy;
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  await sendPanelRequest({ type: 'panel.updateSettings', patch });
}

async function clearLocalState(): Promise<void> {
  await sendPanelRequest({ type: 'panel.clearLocalState' });
}

async function loginFromAccountForm(event: SubmitEvent): Promise<void> {
  event.preventDefault();

  setAccountRequestBusy(true, 'Signing in...');

  try {
    const signedIn = await sendPanelRequest(
      {
        type: 'panel.login',
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
    const signedOut = await sendPanelRequest({ type: 'panel.logout' }, 'account');

    if (signedOut) {
      showAccountFeedback('success', 'Signed out.');
    }
  } finally {
    setAccountRequestBusy(false);
  }
}

async function sendPanelRequest(
  request: PanelRequest,
  errorTarget: RequestErrorTarget = 'global',
  kind: 'normal' | 'mutation' = 'normal',
): Promise<boolean> {
  const requestWithWindow = typeof panelWindowId === 'number'
    ? { ...request, windowId: panelWindowId }
    : request;
  const seq = ++stateSeq;
  const isMutation = kind === 'mutation';
  const ordering = beginPanelRequest(panelRequestState, kind);
  panelRequestState = ordering.state;
  const canApply = (): boolean => canApplyPanelResponse(panelRequestState, kind, ordering.version, ordering.startedDuringMutation);

  try {
    let response = (await browser.runtime.sendMessage(requestWithWindow)) as PanelResponse | undefined;

    if (!response) {
      await new Promise((resolve) => setTimeout(resolve, 150));
      response = (await browser.runtime.sendMessage(requestWithWindow)) as PanelResponse | undefined;
    }

    if (!response) {
      if (!canApply()) return false;
      if (seq < latestAppliedSeq) {
        return false;
      }
      latestAppliedSeq = seq;
      showRequestError('The extension background did not respond. Try again.', errorTarget);

      return false;
    }

    if ('ok' in response) {
      if (!canApply()) return false;
      if (seq < latestAppliedSeq) {
        return false;
      }
      latestAppliedSeq = seq;
      showRequestError(response.error, errorTarget, response.errorCode, response.details);

      return false;
    }

    if (!canApply()) return false;
    if (!isMutation && seq < latestAppliedSeq) {
      return true;
    }
    latestAppliedSeq = seq;
    showPanelState(response);

    return true;
  } catch (error) {
    if (!canApply()) return false;
    if (seq < latestAppliedSeq) {
      return false;
    }
    latestAppliedSeq = seq;
    showRequestError(error, errorTarget);

    return false;
  } finally {
    if (isMutation) {
      panelRequestState = finishPanelRequest(panelRequestState);
    }
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

function setLanguagesExpanded(expanded: boolean): void {
  languagesExpanded = expanded;
  languageExpand.hidden = !expanded;
  toggleLanguagesButton.textContent = expanded ? 'Done' : 'Change';
  toggleLanguagesButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

function showPanelState(state: PanelState): void {
  const previousStateType = latestState?.subtitleState.type;
  const previousCorrectionStatus = latestState?.lyricsCorrection?.status;
  latestState = state;

  const pageStatus = state.pageStatus;
  const settings = state.settings;
  const supported = Boolean(pageStatus?.supported);
  const { accountState } = state;
  const authenticated = accountState.status === 'authenticated';
  const subtitleState = state.subtitleState;

  currentSettings = settings;

  /* Collapse transient watch-tab state when the video changes. */
  const watchVideoId = pageStatus?.supported ? pageStatus.videoId : null;
  if (watchVideoId !== lastWatchVideoId) {
    lastWatchVideoId = watchVideoId;
    setupExpandedWhileReady = false;
    setLanguagesExpanded(false);
    lyricsCorrectionTextarea.value = '';
    clearQuickFixSelection();
    quickFixNotice = null;
    lyricsReplaceConfirm = false;
    lyricsEditOpen = false;
    lyricsEditMode = 'quick';
  } else if (previousCorrectionStatus !== 'completed' && state.lyricsCorrection?.status === 'completed') {
    lyricsCorrectionTextarea.value = '';
    lyricsReplaceConfirm = false;
  }

  showStatusBanner(state);
  showWatchState(state, supported, authenticated);
  if (subtitleState.type === 'ready') {
    transcriptView.setData(subtitleState.track.youtubeVideoId, subtitleState.track.cues, settings);
  } else {
    transcriptView.setData(null, [], settings);
  }
  renderJobHistory(state, { jobsList, jobsError });
  renderUsage(accountState);
  renderAccount(accountState, settings);
  renderSettingsSummary(settings);

  generateButton.disabled = !authenticated || !supported || subtitleState.type === 'loading' || lyricsCorrectionRequestBusy || quickFixRequestBusy || lyricsCancellationRequestBusy || isActiveLyricsCorrection(state.lyricsCorrection);
  renderLyricsEditState();
  renderLyricsCorrectionState(state);
  generateButton.textContent = generateButtonLabel(accountState, subtitleState.type);
  renderGenerateNote(state, supported);

  renderLanguagePair(settings);
  renderLanguagePickers(settings);
  overlayVisibleInput.checked = settings.overlayVisible;
  overlayPositionSelect.value = settings.overlayPosition;
  captionFontSizeSelect.value = settings.captionFontSize;
  captionDensitySelect.value = settings.captionDensity;
  captionContrastThemeSelect.value = settings.captionContrastTheme;
  showRomanizationInput.checked = settings.showRomanization;
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
  nowPlayingTitle.textContent = nowPlayingTitleLabel(state);
  nowPlayingMeta.textContent = supported
    ? `${videoDurationLabel(state)} · ${languageLabel(settings.sourceLanguage)} → ${languageLabel(settings.targetLanguage)}`
    : '';

  if (!hasAppliedDefaultView) {
    hasAppliedDefaultView = true;
    showTab(tabButtons, panels, 'watch');
  }

  if (previousStateType !== state.subtitleState.type || previousCorrectionStatus !== state.lyricsCorrection?.status) {
    scheduleNextBackendPoll();
  }
}

/* Terminal correction outcomes live in one banner outside the collapsible
   Edit panel; in-form status is reserved for request errors. */
function renderLyricsCorrectionState(state: PanelState): void {
  const correction = state.lyricsCorrection;

  if (!correction || correction.status === 'queued' || correction.status === 'running') {
    correctionTerminalStatus.hidden = true;
    correctionTerminalStatus.textContent = '';
    if (!correction) {
      lyricsCorrectionStatus.textContent = '';
      correctionCancelError.hidden = true;
      correctionCancelError.textContent = '';
    }
    return;
  }

  /* A terminal outcome supersedes any in-form request error. */
  lyricsCorrectionStatus.textContent = '';
  correctionCancelError.hidden = true;
  correctionCancelError.textContent = '';

  correctionTerminalStatus.hidden = false;
  if (correction.status === 'completed') {
    correctionTerminalStatus.className = 'status-banner correction-status success';
    correctionTerminalStatus.textContent = 'Lyrics replaced — the transcript is up to date.';
    return;
  }

  correctionTerminalStatus.className = 'status-banner correction-status';
  correctionTerminalStatus.textContent = correction.status === 'cancelled'
    ? 'Replacement cancelled. Your current subtitles are unchanged.'
    : correction.errorCode === 'lyrics_incomplete'
      ? 'Replacement stopped — the pasted lyrics did not cover the whole song. Your current subtitles are unchanged.'
      : correction.errorCode === 'lyrics_do_not_match'
        ? 'Replacement stopped — these lyrics do not match this song. Your current subtitles are unchanged.'
        : 'Replacement failed. Your current subtitles are unchanged.';
}

function showStatusBanner(state: PanelState): void {
  if (state.subtitleState.type === 'error') {
    statusBanner.hidden = false;
    statusBanner.textContent = state.subtitleState.message || 'Generation failed.';

    return;
  }

  statusBanner.hidden = true;
  statusBanner.textContent = '';
}

/** Toggle the Watch tab's mutually exclusive states: unsupported page, sign-in prompt, setup, progress, transcript. */
function showWatchState(state: PanelState, supported: boolean, authenticated: boolean): void {
  const subtitleState = state.subtitleState;
  const loading = subtitleState.type === 'loading';
  const ready = subtitleState.type === 'ready';
  const correctionRunning = isActiveLyricsCorrection(state.lyricsCorrection);

  watchUnsupported.hidden = supported;
  watchSignin.hidden = !supported || authenticated;
  watchSetup.hidden = !supported || !authenticated || loading || (ready && !setupExpandedWhileReady);
  progressContainer.hidden = !loading && !correctionRunning;
  watchReady.hidden = !ready;
  toggleSetupButton.disabled = correctionRunning || lyricsCorrectionRequestBusy || quickFixRequestBusy || lyricsCancellationRequestBusy;
  toggleSetupButton.setAttribute('aria-expanded', setupExpandedWhileReady ? 'true' : 'false');

  if (loading) {
    const progress = generationProgress(subtitleState);

    progressLabel.textContent = 'Generating';
    progressStages.setAttribute('aria-label', 'Generation stages');
    progressPercent.setAttribute('aria-valuenow', String(progress.percent));
    progressPercent.setAttribute('aria-valuetext', `${progress.percent}% ${progress.activityLabel}`);
    progressPercent.textContent = `${progress.percent}%`;
    announceProgress('Generating', progress.activityLabel);
    progressBar.style.width = `${progress.percent}%`;
    progressStages.innerHTML = stageChecklistHtml(subtitleState.stage);
    progressCopy.textContent = 'Subtitles appear on the video as each batch finishes. You can close this panel — generation keeps going.';
  } else if (correctionRunning && state.lyricsCorrection) {
    const progress = lyricsCorrectionProgress(state.lyricsCorrection.stage);
    progressLabel.textContent = 'Replacing lyrics';
    progressStages.setAttribute('aria-label', 'Replacement stages');
    progressPercent.setAttribute('aria-valuenow', String(progress.percent));
    progressPercent.setAttribute('aria-valuetext', `${progress.percent}% ${progress.label}`);
    progressPercent.textContent = `${progress.percent}%`;
    announceProgress('Replacing lyrics', progress.label);
    progressBar.style.width = `${progress.percent}%`;
    progressStages.innerHTML = lyricsCorrectionStageChecklistHtml(state.lyricsCorrection.stage);
    progressCopy.textContent = 'Your current subtitles stay active while the replacement runs.';
  }
}

function announceProgress(operation: string, stage: string): void {
  const announcement = `${operation}: ${stage}`;

  if (announcement === lastProgressAnnouncement) return;

  lastProgressAnnouncement = announcement;
  progressActivity.textContent = stage;
}

function lyricsCorrectionStageChecklistHtml(stage: LyricsCorrectionStatus['stage']): string {
  const stages = LYRICS_CORRECTION_STAGES;
  const currentIndex = stages.findIndex((item) => item.key === stage);

  return stages.map((item, index) => `
    <li class="stage ${index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'pending'}"${index === currentIndex ? ' aria-current="step"' : ''}>
      <span class="stage-dot" aria-hidden="true"></span>
      <span>${escapeHtml(item.label)}</span>
    </li>
  `).join('');
}

function stageChecklistHtml(stage: PanelState['jobHistory'][number]['stage']): string {
  return stageTimeline({ stage, status: 'running' })
    .map(
      (item) => `
        <li class="stage ${item.state}"${item.state === 'current' ? ' aria-current="step"' : ''}>
          <span class="stage-dot" aria-hidden="true"></span>
          <span>${escapeHtml(item.label)}</span>
        </li>
      `,
    )
    .join('');
}

function renderGenerateNote(state: PanelState, supported: boolean): void {
  if (!supported) {
    generateNote.textContent = '';

    return;
  }

  const duration = videoDurationForState(state);
  generateNote.textContent = typeof duration === 'number'
    ? `≈ ${Math.max(1, Math.ceil(duration / 60))} min of video · counts toward your plan minutes`
    : 'Generation time counts toward your plan minutes.';
}

function renderLanguagePair(settings: ExtensionSettings | null): void {
  pairSourceCode.textContent = settings?.sourceLanguage ?? '';
  pairSourceName.textContent = settings ? languageLabel(settings.sourceLanguage) : '—';
  pairTargetCode.textContent = settings?.targetLanguage ?? '';
  pairTargetName.textContent = settings ? languageLabel(settings.targetLanguage) : '—';
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
  if (accountState.status !== 'authenticated') {
    usageSummary.textContent = 'Sign in to see usage';
    usageRemaining.textContent = 'Usage unavailable';
    usageBar.style.width = '0%';
    usagePlan.textContent = 'Not signed in';
    usagePending.textContent = 'Sign in required';
    usageReset.textContent = 'Unavailable';

    return;
  }

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

  accountStatus.textContent = authenticated ? accountState.email : 'Account';
  accountPlan.textContent = authenticated ? accountState.planName : 'Available after sign-in';
  accountSpeed.textContent = authenticated ? accountState.tierSpeedLabel : 'Available after sign-in';
  accountLoginForm.hidden = authenticated;
  accountEmailInput.disabled = accountRequestBusy || authenticated;
  accountPasswordInput.disabled = accountRequestBusy || authenticated;
  accountLoginButton.disabled = accountRequestBusy || authenticated;
  accountLoginButton.textContent = accountRequestBusy ? 'Signing in...' : 'Sign in';
  logoutButton.disabled = accountRequestBusy || !authenticated;
  logoutButton.hidden = !authenticated;
  featureList.innerHTML = accountFeatureListHtml(accountState, settings);
}

function renderSettingsSummary(settings: ExtensionSettings): void {
  settingsLanguageSummary.textContent = `${languageLabel(settings.sourceLanguage)} to ${languageLabel(settings.targetLanguage)}`;
}

function renderShortcutHelp(): void {
  shortcutHelpList.innerHTML = shortcutHelpHtml();
}

function showError(error: unknown): void {
  const emptyAccountState = anonymousAccountState();

  latestState = null;
  currentSettings = null;
  statusBanner.hidden = false;
  statusBanner.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  nowPlayingEyebrow.textContent = 'No video';
  nowPlayingTitle.textContent = 'Open a YouTube video';
  nowPlayingMeta.textContent = '';
  watchUnsupported.hidden = false;
  watchSignin.hidden = true;
  watchSetup.hidden = true;
  progressContainer.hidden = true;
  watchReady.hidden = true;
  jobsList.innerHTML = '<p class="empty-state">Unable to load jobs.</p>';
  renderLanguagePair(null);
  renderLanguagePickers(null);
  renderUsage(emptyAccountState);
  renderAccount(emptyAccountState, DEFAULT_EXTENSION_SETTINGS);
  settingsLanguageSummary.textContent = 'Unavailable';
  generateButton.disabled = true;
  generateButton.textContent = 'Generate subtitles';
  generateNote.textContent = '';
  overlayVisibleInput.checked = DEFAULT_EXTENSION_SETTINGS.overlayVisible;
  overlayPositionSelect.value = DEFAULT_EXTENSION_SETTINGS.overlayPosition;
  captionFontSizeSelect.value = DEFAULT_EXTENSION_SETTINGS.captionFontSize;
  captionDensitySelect.value = DEFAULT_EXTENSION_SETTINGS.captionDensity;
  captionContrastThemeSelect.value = DEFAULT_EXTENSION_SETTINGS.captionContrastTheme;
  keyboardShortcutsEnabledInput.checked = DEFAULT_EXTENSION_SETTINGS.keyboardShortcutsEnabled;
  timingControl.showTimingOffset(0);
  setSettingsDisabled(true);
}

function showRequestError(error: unknown, errorTarget: RequestErrorTarget, errorCode?: string, details?: { reason?: string }): void {
  const message = typeof error === 'string'
    ? error
    : error instanceof Error
      ? error.message
      : 'Unable to load extension state';

  if (errorTarget === 'account') {
    showAccountFeedback('error', message);

    return;
  }

  if (errorTarget === 'quickfix') {
    quickFixError = { message, code: errorCode, reason: details?.reason };

    return;
  }

  if (errorTarget === 'correction') {
    lyricsCorrectionStatus.textContent = message;

    return;
  }

  if (errorTarget === 'cancel') {
    correctionCancelError.hidden = false;
    correctionCancelError.textContent = message;

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
  showRomanizationInput.disabled = disabled;
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
