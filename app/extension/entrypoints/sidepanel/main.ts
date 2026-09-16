import './style.css';
import { bindSavedGenerations } from './saved-generations';

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
import { generationConfirmationContext } from '../../utils/generation-confirmation';
import { anonymousAccountState, formatResetDate } from '../../utils/account-state';
import { escapeHtml } from '../../utils/html';
import { DEFAULT_EXTENSION_SETTINGS, type ExtensionSettings } from '../../utils/settings-model';
import { accountFeatureListHtml, accountBillingLinkHtml } from './render/account';
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
import { canApplyLyricsCorrection, lyricsCharacterCount, lyricsValidationError, lyricsCorrectionProgress, LYRICS_CORRECTION_STAGES, QUICK_FIX_CHARACTER_LIMIT } from '../../utils/lyrics-correction';
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
type RequestErrorTarget = 'global' | 'account' | 'settings' | 'correction' | 'quickfix' | 'cancel' | 'generation-start' | 'generation-cancel' | 'generation-selection';
type AccountFeedbackKind = 'info' | 'success' | 'error';
type GenerationCancelFeedback = { kind: 'success' | 'error'; message: string };

const {
  backTranscriptButton,
  progressSummary,
  progressSummaryLabel,
  viewProgressButton,
  readyToolbar,
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
  correctionTerminalMessage,
  dismissCorrectionStatusButton,
  correctionCancelError,
  correctionSyncError,
  lyricsEditPanel,
  toggleLyricsEditButton,
  lyricsCorrectionForm,
  lyricsCorrectionTextarea,
  lyricsCorrectionCount,
  lyricsCorrectionError,
  lyricsCorrectionStatus,
  lyricsCorrectionButton,
  lyricsConfirmation,
  confirmLyricsCorrectionButton,
  cancelLyricsConfirmationButton,
  cancelLyricsCorrectionButton,
  cancelGenerationButton,
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
  generationConfirmation,
  generationConfirmationSummary,
  confirmGenerationButton,
  cancelGenerationConfirmationButton,
  generateNote,
  clearStateButton,
  resetTimingButton,
  sourceLanguageSearchInput,
  targetLanguageSearchInput,
  sourceLanguageSelected,
  targetLanguageSelected,
  sourceLanguageList,
  targetLanguageList,
  aiProviderSelect,
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
  accountModel,
  accountLoginForm,
  accountEmailInput,
  accountPasswordInput,
  accountLoginButton,
  logoutButton,
  accountFeedback,
  featureList,
  accountBillingLink,
  settingsLanguageSummary,
  shortcutHelpList,
} = getPanelDom();

let currentSettings: ExtensionSettings | null = null;
let latestState: PanelState | null = null;
let sourceLanguageQuery = '';
let targetLanguageQuery = '';
let accountRequestBusy = false;
let generationRequestBusy = false;
let confirmedGenerationContext: string | null = null;
let settingsRequestsInFlight = 0;
let lyricsCorrectionRequestBusy = false;
let quickFixRequestBusy = false;
let lyricsCancellationRequestBusy = false;
let generationCancellationRequestBusy = false;
let generationCancelFeedback: GenerationCancelFeedback | null = null;
let hasAppliedDefaultView = false;
let panelWindowId: number | undefined;
let tabChangeTimer: ReturnType<typeof setTimeout> | undefined;
let stateSeq = 0;
let latestAppliedSeq = 0;
let panelRequestState: PanelRequestOrder = panelRequestOrder();
let accountActionVersion = 0;
let accountActionsInFlight = 0;
let lastProgressAnnouncement: string | null = null;

/* Watch opens on the transcript; whole-track tasks each occupy one screen. */
let languagesExpanded = false;
let watchScreen: 'transcript' | 'replace' | 'generate' | 'progress' = 'transcript';
let lastWatchVideoId: string | null = null;
let lyricsReplaceConfirm = false;
const dismissedCorrectionAttempts = new Set<string>();
let quickFixSelection: { cueId: string; tokenIndex: number; text: string } | null = null;
let quickFixError: { message: string; code?: string; reason?: string } | null = null;
let quickFixNotice: string | null = null;

collapseButton.addEventListener('click', () => {
  window.close();
});
generateButton.addEventListener('click', openGenerationConfirmation);
confirmGenerationButton.addEventListener('click', () => void generateSubtitles());
cancelGenerationConfirmationButton.addEventListener('click', dismissGenerationConfirmation);
generationConfirmation.addEventListener('cancel', (event) => {
  event.preventDefault();
  dismissGenerationConfirmation();
});
lyricsCorrectionForm.addEventListener('submit', (event) => void submitLyricsCorrection(event));
lyricsCorrectionTextarea.addEventListener('input', () => {
  /* Editing the paste after Continue drops back out of the confirmation step. */
  lyricsReplaceConfirm = false;
  renderLyricsEditState();
});
function openWatchScreen(screen: typeof watchScreen): void {
  if (quickFixRequestBusy || lyricsCorrectionRequestBusy) return;
  watchScreen = screen;
  if (latestState) showPanelState(latestState);
  if (screen === 'transcript') transcriptSearch.focus({ preventScroll: true });
  else backTranscriptButton.focus({ preventScroll: true });
}
toggleLyricsEditButton.addEventListener('click', () => openWatchScreen('replace'));
backTranscriptButton.addEventListener('click', () => openWatchScreen('transcript'));
viewProgressButton.addEventListener('click', () => openWatchScreen('progress'));
dismissCorrectionStatusButton.addEventListener('click', () => {
  if (latestState?.lyricsCorrection) dismissedCorrectionAttempts.add(latestState.lyricsCorrection.attemptId);
  correctionTerminalStatus.hidden = true;
  correctionTerminalMessage.textContent = '';
});
confirmLyricsCorrectionButton.addEventListener('click', () => void applyConfirmedLyricsCorrection());
cancelLyricsConfirmationButton.addEventListener('click', () => {
  lyricsReplaceConfirm = false;
  renderLyricsEditState();
  lyricsCorrectionTextarea.focus();
});
cancelLyricsCorrectionButton.addEventListener('click', () => void cancelLyricsCorrection());
cancelGenerationButton.addEventListener('click', () => void cancelGeneration(cancelGenerationButton));
jobsList.addEventListener('click', (event) => {
  const target = event.target instanceof Element ? event.target : null;
  const button = target?.closest<HTMLButtonElement>('[data-action="cancel-generation"]');

  if (button) void cancelGeneration(button);
});
clearStateButton.addEventListener('click', () => void clearLocalState());
openAccountButton.addEventListener('click', () => {
  showTab(tabButtons, panels, 'account');
  accountEmailInput.focus();
});
toggleLanguagesButton.addEventListener('click', () => {
  setLanguagesExpanded(!languagesExpanded);
});
toggleSetupButton.addEventListener('click', () => openWatchScreen('generate'));
accountLoginForm.addEventListener('submit', (event) => void loginFromAccountForm(event));
logoutButton.addEventListener('click', () => void logoutAccount());
accountEmailInput.addEventListener('input', clearAccountFeedback);
accountPasswordInput.addEventListener('input', clearAccountFeedback);
sourceLanguageSearchInput.addEventListener('input', handleSourceLanguageSearch);
targetLanguageSearchInput.addEventListener('input', handleTargetLanguageSearch);
sourceLanguageList.addEventListener('click', handleSourceLanguageClick);
targetLanguageList.addEventListener('click', handleTargetLanguageClick);
overlayPositionSelect.addEventListener('change', handleOverlayPositionChange);
captionFontSizeSelect.addEventListener('change', handleCaptionFontSizeChange);
captionDensitySelect.addEventListener('change', handleCaptionDensityChange);
captionContrastThemeSelect.addEventListener('change', handleCaptionContrastThemeChange);
const savedGenerations = bindSavedGenerations(
  document.querySelector<HTMLSelectElement>('[data-generation-select]')!,
  document.querySelector<HTMLElement>('[data-generation-status]')!,
  document.querySelector<HTMLButtonElement>('[data-generation-refresh]')!,
  request => sendPanelRequest(request, 'generation-selection', 'mutation'),
  () => panelWindowId,
  showPanelState,
);

aiProviderSelect.addEventListener('change', () => {
  const aiProvider = aiProviderSelect.value;
  if (aiProvider === 'openai' || aiProvider === 'cerebras') void updateSettings({ aiProvider });
});
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
const LYRICS_POLL_INTERVAL_MS = 2_000;
let lastFullBackendRefresh = 0;
const IDLE_POLL_INTERVAL_MS = 30_000;

setupTabs(tabButtons, panels);
accountBillingLink.innerHTML = accountBillingLinkHtml();
let cueSnapshotRequest = 0;
const transcriptView = bindTranscriptView({
  transcriptSearch,
  transcriptList,
  transcriptStatus,
  onSeekToCue: (cueId, mode) => {
    const state = latestState;
    const track = state?.subtitleState.type === 'ready' ? state.subtitleState.track : null;
    if (!state || !track || state.activeTabId === undefined) return;
    void browser.runtime.sendMessage({
      type: 'panel.seekToCue',
      tabId: state.activeTabId,
      youtubeVideoId: track.youtubeVideoId,
      trackId: track.trackId,
      cueId,
      mode,
      ...(typeof panelWindowId === 'number' ? { windowId: panelWindowId } : {}),
    }).catch(() => {});
  },
  onQuickFixSelect: selectQuickFixToken,
  onQuickFixSave: (cueId, tokenIndex, value) => void submitQuickFix(cueId, tokenIndex, value),
  onQuickFixCancel: () => {
    clearQuickFixSelection();
    renderLyricsEditState();
    transcriptSearch.focus({ preventScroll: true });
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
  if (!isRuntimeMessage(message)) return false;
  if (message.type === 'background.activeCueChanged') {
    if (panelWindowId !== undefined && message.windowId !== panelWindowId) return false;
    if (latestState?.activeTabId !== message.tabId
      || latestState.subtitleState.type !== 'ready'
      || latestState.subtitleState.track.youtubeVideoId !== message.youtubeVideoId
      || latestState.subtitleState.track.trackId !== message.trackId) return false;
    cueSnapshotRequest += 1;
    transcriptView.setActiveCue(message.cueId);
  } else if (message.type === 'background.focusTranscript') {
    if (panelWindowId !== undefined && message.windowId !== panelWindowId) return false;
    showTab(tabButtons, panels, 'watch');
    transcriptView.focus();
  }
  return false;
});

function scheduleNextBackendPoll(): void {
  if (backendPollTimer) clearTimeout(backendPollTimer);
  const hasInFlightJob = latestState?.subtitleState.type === 'loading'
    || latestState?.lyricsCorrection?.status === 'queued'
    || latestState?.lyricsCorrection?.status === 'running';
  const correcting = isActiveLyricsCorrection(latestState?.lyricsCorrection);
  const interval = correcting ? LYRICS_POLL_INTERVAL_MS : hasInFlightJob ? ACTIVE_POLL_INTERVAL_MS : IDLE_POLL_INTERVAL_MS;
  backendPollTimer = setTimeout(() => {
    if (document.visibilityState === 'visible') {
      void refreshBackendState(correcting && Date.now() - lastFullBackendRefresh < ACTIVE_POLL_INTERVAL_MS);
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
  dismissGenerationConfirmation();
  if (tabChangeTimer) clearTimeout(tabChangeTimer);
  tabChangeTimer = setTimeout(() => void onActiveTabChanged(), 60);
}

async function onActiveTabChanged(): Promise<void> {
  await sendPanelRequest({ type: 'panel.getState', syncBackend: false });
  void refreshBackendState();
}

async function refreshBackendState(lyricsOnly = false): Promise<void> {
  if (backendRefreshInFlight) {
    return;
  }

  backendRefreshInFlight = true;

  try {
    if (!lyricsOnly) lastFullBackendRefresh = Date.now();
    await sendPanelRequest({ type: 'panel.getState', syncBackend: !lyricsOnly, syncLyricsCorrection: lyricsOnly });
  } finally {
    backendRefreshInFlight = false;
  }
}

function currentGenerationContext(): string | null {
  const state = latestState;
  if (!state?.pageStatus?.supported || state.accountState.status !== 'authenticated' || state.activeTabId === undefined) return null;
  return generationConfirmationContext(state.activeTabId, state.pageStatus.videoId, state.accountState.id);
}

function generationUnavailable(): boolean {
  return generationRequestBusy || generationCancellationRequestBusy || accountRequestBusy || settingsRequestsInFlight > 0
    || lyricsCorrectionRequestBusy || quickFixRequestBusy || lyricsCancellationRequestBusy
    || latestState?.subtitleState.type === 'loading' || isActiveLyricsCorrection(latestState?.lyricsCorrection)
    || currentGenerationContext() === null;
}

function openGenerationConfirmation(): void {
  if (generationUnavailable() || generationConfirmation.open || !latestState) return;
  confirmedGenerationContext = currentGenerationContext();
  const { settings, pageVideoDurationSeconds: duration } = latestState;
  const minutes = typeof duration === 'number'
    ? `Estimated usage: ${Math.max(1, Math.ceil(duration / 60))} plan minutes for the full video`
    : 'The full video duration counts toward your plan minutes';
  generationConfirmationSummary.textContent = `${nowPlayingTitleLabel(latestState)} · ${languageLabel(settings.sourceLanguage)} → ${languageLabel(settings.targetLanguage)} · ${settings.aiProvider === 'cerebras' ? 'Transcriber Spark' : 'Transcriber'} · ${minutes}.`;
  generationConfirmation.showModal();
}

function dismissGenerationConfirmation(): void {
  confirmedGenerationContext = null;
  if (generationConfirmation.open) generationConfirmation.close();
}

async function generateSubtitles(): Promise<void> {
  const confirmationContext = confirmedGenerationContext;
  if (!generationConfirmation.open || !confirmationContext) return;
  dismissGenerationConfirmation();
  if (generationUnavailable() || confirmationContext !== currentGenerationContext()) return;

  generationCancelFeedback = null;
  generationRequestBusy = true;
  generateButton.disabled = true;
  generateButton.textContent = 'Starting...';

  try {
    const applied = await sendPanelRequest({ type: 'panel.generateSubtitles', confirmationContext }, 'generation-start', 'mutation');
    if (applied) {
      openWatchScreen('transcript');
    } else {
      await sendPanelRequest({ type: 'panel.getState', syncBackend: false }, 'settings');
    }
  } finally {
    generationRequestBusy = false;
    if (latestState) showPanelState(latestState);
  }
}

async function cancelGeneration(button: HTMLButtonElement): Promise<void> {
  const state = latestState;
  const jobId = button.dataset.jobId;
  const youtubeVideoId = button.dataset.youtubeVideoId;

  if (generationCancellationRequestBusy || !state || !jobId || !youtubeVideoId) return;

  const job = state.jobHistory.find((candidate) => candidate.jobId === jobId && candidate.youtubeVideoId === youtubeVideoId);
  const watchMatchesJob = state.activeTabId !== undefined
    && state.subtitleState.type === 'loading'
    && state.subtitleState.jobId === jobId
    && state.subtitleState.youtubeVideoId === youtubeVideoId;
  if ((!job && !watchMatchesJob) || (job && job.status !== 'queued' && job.status !== 'running')) return;

  const tabId = watchMatchesJob
    ? state.activeTabId
    : button.dataset.tabId ? Number(button.dataset.tabId) : undefined;

  generationCancellationRequestBusy = true;
  generationCancelFeedback = null;
  if (latestState) showPanelState(latestState);

  try {
    const applied = await sendPanelRequest({
      type: 'panel.cancelSubtitleJob',
      jobId,
      youtubeVideoId,
      ...(typeof tabId === 'number' && Number.isInteger(tabId) ? { tabId } : {}),
    }, 'generation-cancel', 'mutation');

    if (applied) {
      generationCancelFeedback = { kind: 'success', message: 'Generation cancelled.' };
      if (latestState) showPanelState(latestState);
    }
  } finally {
    generationCancellationRequestBusy = false;
    if (latestState) showPanelState(latestState);
  }
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
    lyricsReplaceConfirm = false;
    if (applied) {
      watchScreen = 'transcript';
      if (latestState) showPanelState(latestState);
    }
  } finally {
    lyricsCorrectionRequestBusy = false;
    renderLyricsEditState();
  }
}

function renderLyricsCorrectionInput(): void {
  const count = lyricsCharacterCount(lyricsCorrectionTextarea.value);
  const error = count > 0 ? lyricsValidationError(lyricsCorrectionTextarea.value) : null;
  lyricsCorrectionCount.textContent = `${count.toLocaleString()} / 25,000 characters`;
  lyricsCorrectionError.textContent = error ?? '';
  lyricsCorrectionError.hidden = error === null;
  lyricsCorrectionTextarea.setAttribute('aria-invalid', String(error !== null));
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
    if (applied) {
      watchScreen = 'replace';
      if (latestState) showPanelState(latestState);
      dismissCorrectionStatusButton.focus({ preventScroll: true });
    }
  } finally {
    lyricsCancellationRequestBusy = false;
    renderLyricsEditState();
  }
}

function selectQuickFixToken(cueId: string, tokenIndex: number): void {
  if (quickFixRequestBusy || !latestState || latestState.subtitleState.type !== 'ready') return;

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
    transcriptSearch.focus({ preventScroll: true });
  } else if (quickFixError?.code === 'lyrics_correction_in_progress' && quickFixError.reason === 'stale_track') {
    clearQuickFixSelection();
    quickFixNotice = 'The subtitles changed — the latest version is loaded.';
    void refreshBackendState();
    transcriptSearch.focus({ preventScroll: true });
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
  const editOpen = watchScreen === 'replace' && ready;
  const quickActive = ready && !activeCorrection;

  /* Drop a selection whose cue no longer exists (e.g. the track rotated). */
  if (quickFixSelection) {
    const track = latestState?.subtitleState.type === 'ready' ? latestState.subtitleState.track : null;
    if (!quickActive || !track?.cues.some((cue) => cue.cueId === quickFixSelection!.cueId)) {
      clearQuickFixSelection();
    }
  }

  lyricsEditPanel.hidden = !editOpen;
  backTranscriptButton.disabled = quickFixRequestBusy || lyricsCorrectionRequestBusy;
  toggleSetupButton.disabled = activeCorrection || lyricsCorrectionRequestBusy || quickFixRequestBusy || lyricsCancellationRequestBusy;
  toggleLyricsEditButton.disabled = !ready || activeCorrection || lyricsCorrectionRequestBusy || quickFixRequestBusy;

  /* Replace all: Continue reveals the inline warning and swaps the footer
     actions; the pasted text stays visible while confirming. */
  lyricsConfirmation.hidden = !lyricsReplaceConfirm;
  lyricsConfirmation.textContent = 'This replaces the entire transcript and has no undo. Your current subtitles stay active until the replacement succeeds.';
  lyricsCorrectionButton.hidden = lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.hidden = !lyricsReplaceConfirm;
  cancelLyricsConfirmationButton.hidden = !lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.textContent = 'Replace entire track';
  cancelLyricsConfirmationButton.textContent = 'Cancel';
  lyricsCorrectionTextarea.disabled = lyricsCorrectionRequestBusy;
  confirmLyricsCorrectionButton.disabled = lyricsCorrectionRequestBusy || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection);

  quickFixStatus.textContent = quickFixNotice ?? '';
  transcriptView.setQuickFixMode(quickActive);
  transcriptView.setQuickFixEditing(quickActive ? quickFixSelection : null);
  renderLyricsCorrectionInput();
  cancelLyricsCorrectionButton.hidden = !activeCorrection;
  cancelLyricsCorrectionButton.disabled = lyricsCancellationRequestBusy;
}

async function updateSettings(patch: Partial<ExtensionSettings>): Promise<void> {
  dismissGenerationConfirmation();
  settingsRequestsInFlight += 1;
  generateButton.disabled = true;
  try {
    await sendPanelRequest({ type: 'panel.updateSettings', patch }, 'settings', 'mutation');
  } finally {
    settingsRequestsInFlight -= 1;
    generateButton.disabled = generationUnavailable();
  }
}

async function clearLocalState(): Promise<void> {
  dismissGenerationConfirmation();
  await sendPanelRequest({ type: 'panel.clearLocalState' }, 'global', 'mutation');
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
        ...(typeof panelWindowId === 'number' ? { windowId: panelWindowId } : {}),
      },
      'account',
      'mutation',
      true,
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
    const signedOut = await sendPanelRequest({ type: 'panel.logout' }, 'account', 'mutation', true);

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
  accountAction = false,
): Promise<boolean> {
  const requestWithWindow = typeof panelWindowId === 'number'
    ? { ...request, windowId: panelWindowId }
    : request;
  const seq = ++stateSeq;
  const isMutation = kind === 'mutation';
  const ordering = beginPanelRequest(panelRequestState, kind);
  panelRequestState = ordering.state;
  const startedDuringAccountAction = accountActionsInFlight > 0;
  const requestAccountId = latestState?.accountState.status === 'authenticated' ? latestState.accountState.id : null;
  const actionVersion = accountAction ? ++accountActionVersion : accountActionVersion;
  if (accountAction) accountActionsInFlight += 1;
  const canApply = (): boolean => accountAction
    ? actionVersion === accountActionVersion
    : !startedDuringAccountAction && accountActionsInFlight === 0
      && (latestState?.accountState.status === 'authenticated' ? latestState.accountState.id : null) === requestAccountId
      && canApplyPanelResponse(panelRequestState, kind, ordering.version, ordering.startedDuringMutation);

  try {
    let response = (await browser.runtime.sendMessage(requestWithWindow)) as PanelResponse | undefined;

    if (!response && request.type !== 'panel.generateSubtitles') {
      await new Promise((resolve) => setTimeout(resolve, 150));
      response = (await browser.runtime.sendMessage(requestWithWindow)) as PanelResponse | undefined;
    }

    if (!response) {
      if (!canApply()) return false;
      if (seq < latestAppliedSeq) {
        return false;
      }
      latestAppliedSeq = seq;
      showRequestError('The extension background did not respond. Reload AI Language Subtitles in chrome://extensions, then refresh YouTube and reopen the panel.', errorTarget);

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
    if (accountAction) accountActionsInFlight = Math.max(0, accountActionsInFlight - 1);
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

function setLanguagesExpanded(expanded: boolean): void {
  languagesExpanded = expanded;
  languageExpand.hidden = !expanded;
  toggleLanguagesButton.textContent = expanded ? 'Done' : 'Change';
  toggleLanguagesButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

function showPanelState(state: PanelState): void {
  const previousAccountId = latestState?.accountState.status === 'authenticated' ? latestState.accountState.id : null;
  const nextAccountId = state.accountState.status === 'authenticated' ? state.accountState.id : null;
  const previousStateType = latestState?.subtitleState.type;
  const previousCorrectionStatus = latestState?.lyricsCorrection?.status;
  const previousTrackId = latestState?.subtitleState.type === 'ready' ? latestState.subtitleState.track.trackId : null;
  const previousVideoId = latestState?.pageStatus?.supported ? latestState.pageStatus.videoId : null;
  const nextVideoId = state.pageStatus?.supported ? state.pageStatus.videoId : null;
  const resetVideoLanguage = !latestState || previousVideoId !== nextVideoId || latestState.activeTabId !== state.activeTabId;
  latestState = state;
  if (resetVideoLanguage) {
    sourceLanguageQuery = '';
    sourceLanguageSearchInput.value = '';
    if (state.settings.sourceLanguage !== 'auto') void updateSettings({ sourceLanguage: 'auto' });
  }
  const confirmationChanged = generationConfirmation.open
    && (generationUnavailable() || confirmedGenerationContext !== currentGenerationContext());
  if (confirmationChanged) dismissGenerationConfirmation();
  savedGenerations.render(state);

  if (previousAccountId !== nextAccountId) {
    correctionCancelError.hidden = true;
    correctionCancelError.textContent = '';
    generationCancelFeedback = null;
  }

  const pageStatus = state.pageStatus;
  const settings = state.settings;
  const supported = Boolean(pageStatus?.supported);
  const { accountState } = state;
  const authenticated = accountState.status === 'authenticated';
  const subtitleState = state.subtitleState;
  const nextTrackId = subtitleState.type === 'ready' ? subtitleState.track.trackId : null;

  currentSettings = settings;

  /* Collapse transient watch-tab state when the video changes. */
  const watchVideoId = pageStatus?.supported ? pageStatus.videoId : null;
  if (watchVideoId !== lastWatchVideoId) {
    lastWatchVideoId = watchVideoId;
    generationCancelFeedback = null;
    watchScreen = 'transcript';
    setLanguagesExpanded(false);
    lyricsCorrectionTextarea.value = '';
    clearQuickFixSelection();
    quickFixNotice = null;
    lyricsReplaceConfirm = false;
  } else if (previousCorrectionStatus !== 'completed' && state.lyricsCorrection?.status === 'completed') {
    lyricsCorrectionTextarea.value = '';
    lyricsReplaceConfirm = false;
  } else if (nextTrackId !== previousTrackId) {
    clearQuickFixSelection();
    quickFixNotice = null;
    lyricsCorrectionTextarea.value = '';
    /* A refreshed track invalidates the old replacement confirmation. */
    lyricsReplaceConfirm = false;
  }

  showStatusBanner(state);
  showWatchState(state, supported, authenticated);
  if (subtitleState.type === 'ready') {
    transcriptView.setData(subtitleState.track.youtubeVideoId, subtitleState.track.cues, settings);
    void pullActiveCue(state);
  } else if (subtitleState.type === 'loading' && subtitleState.partialTrack?.cues.length) {
    transcriptView.setPartialData(subtitleState.partialTrack.youtubeVideoId, subtitleState.partialTrack.cues, settings);
  } else {
    cueSnapshotRequest += 1;
    transcriptView.setData(null, [], settings);
  }
  renderJobHistory(state, { jobsList, jobsError, cancellationBusy: generationCancellationRequestBusy });
  renderUsage(accountState);
  renderAccount(accountState, settings);
  renderSettingsSummary(settings);

  generateButton.disabled = generationUnavailable();
  renderLyricsEditState();
  renderLyricsCorrectionState(state);
  generateButton.textContent = generateButtonLabel(accountState, subtitleState.type);
  renderGenerateNote(state, supported);
  if (confirmationChanged) {
    statusBanner.hidden = false;
    statusBanner.textContent = 'The selected video, account, or generation availability changed.';
  }

  renderLanguagePair(settings);
  renderLanguagePickers(settings);
  overlayVisibleInput.checked = settings.overlayVisible;
  aiProviderSelect.value = settings.aiProvider;
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
  correctionSyncError.hidden = !state.lyricsCorrectionSyncError;
  correctionSyncError.textContent = state.lyricsCorrectionSyncError ?? '';

  if (
    !correction
    || correction.status === 'queued'
    || correction.status === 'running'
    || dismissedCorrectionAttempts.has(correction.attemptId)
  ) {
    correctionTerminalStatus.hidden = true;
    correctionTerminalMessage.textContent = '';
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
    correctionTerminalMessage.textContent = 'Lyrics replaced — the transcript is up to date.';
    return;
  }

  correctionTerminalStatus.className = 'status-banner correction-status';
  correctionTerminalMessage.textContent = correction.status === 'cancelled'
    ? 'Replacement cancelled. Your current subtitles are unchanged.'
    : correction.message || 'Replacement failed. Your current subtitles are unchanged.';
}

function showStatusBanner(state: PanelState): void {
  if (generationCancelFeedback) {
    statusBanner.hidden = false;
    statusBanner.className = `status-banner${generationCancelFeedback.kind === 'success' ? ' success' : ''}`;
    statusBanner.textContent = generationCancelFeedback.message;

    return;
  }

  if (state.subtitleState.type === 'error') {
    statusBanner.hidden = false;
    statusBanner.className = 'status-banner';
    statusBanner.textContent = state.subtitleState.message || 'Generation failed.';

    return;
  }

  statusBanner.hidden = true;
  statusBanner.className = 'status-banner';
  statusBanner.textContent = '';
}

async function pullActiveCue(state: PanelState): Promise<void> {
  const request = ++cueSnapshotRequest;
  if (state.activeTabId === undefined || state.subtitleState.type !== 'ready') return;
  const { youtubeVideoId, trackId } = state.subtitleState.track;
  try {
    const snapshot = await browser.runtime.sendMessage({
      type: 'panel.getActiveCue', tabId: state.activeTabId, youtubeVideoId, trackId,
      ...(typeof panelWindowId === 'number' ? { windowId: panelWindowId } : {}),
    });
    if (request !== cueSnapshotRequest || latestState?.activeTabId !== state.activeTabId
      || latestState.subtitleState.type !== 'ready' || latestState.subtitleState.track.trackId !== trackId
      || !snapshot?.ok || snapshot.tabId !== state.activeTabId
      || (panelWindowId !== undefined && snapshot.windowId !== panelWindowId)
      || snapshot.youtubeVideoId !== youtubeVideoId
      || snapshot.trackId !== trackId || !(snapshot.cueId === null || typeof snapshot.cueId === 'string')) return;
    transcriptView.setActiveCue(snapshot.cueId);
  } catch {
    // A missing content script must not replace the valid panel snapshot.
  }
}

/** Toggle the Watch tab's mutually exclusive states: unsupported page, sign-in prompt, setup, progress, transcript. */
function showWatchState(state: PanelState, supported: boolean, authenticated: boolean): void {
  const subtitleState = state.subtitleState;
  const loading = subtitleState.type === 'loading';
  const partial = loading && (subtitleState.partialTrack?.cues.length ?? 0) > 0;
  const ready = subtitleState.type === 'ready';
  const correctionRunning = isActiveLyricsCorrection(state.lyricsCorrection);
  const generationInProgress = loading
    && (subtitleState.status === 'queued' || subtitleState.status === 'running');
  const canCancelGeneration = generationInProgress && subtitleState.jobId !== undefined;

  watchUnsupported.hidden = supported;
  watchSignin.hidden = !supported || authenticated;
  watchSetup.hidden = !supported || !authenticated || loading || (ready && watchScreen !== 'generate');
  if (watchScreen === 'progress' && !loading && !correctionRunning) watchScreen = 'transcript';
  progressContainer.hidden = (!loading && !correctionRunning) || (ready && watchScreen !== 'progress');
  progressSummary.hidden = !ready || !correctionRunning || watchScreen !== 'transcript';
  progressSummaryLabel.textContent = correctionRunning ? 'Updating lyrics…' : '';
  backTranscriptButton.hidden = !ready || watchScreen === 'transcript';
  backTranscriptButton.disabled = quickFixRequestBusy || lyricsCorrectionRequestBusy;
  readyToolbar.hidden = watchScreen !== 'transcript' || partial;
  transcriptList.hidden = watchScreen !== 'transcript';
  transcriptStatus.hidden = watchScreen !== 'transcript';
  quickFixStatus.hidden = watchScreen !== 'transcript';
  watchReady.hidden = !ready && !partial;
  cancelGenerationButton.hidden = !generationInProgress;
  cancelGenerationButton.disabled = generationCancellationRequestBusy || !canCancelGeneration;
  cancelGenerationButton.textContent = generationCancellationRequestBusy ? 'Cancelling…' : 'Cancel generation';
  if (canCancelGeneration) {
    cancelGenerationButton.dataset.jobId = subtitleState.jobId;
    cancelGenerationButton.dataset.youtubeVideoId = subtitleState.youtubeVideoId;
    if (state.activeTabId !== undefined) cancelGenerationButton.dataset.tabId = String(state.activeTabId);
  } else {
    delete cancelGenerationButton.dataset.jobId;
    delete cancelGenerationButton.dataset.youtubeVideoId;
    delete cancelGenerationButton.dataset.tabId;
  }

  if (loading) {
    const queued = subtitleState.status === 'queued';
    const progress = generationProgress(subtitleState);

    progressLabel.textContent = queued ? 'Queued' : 'Generating';
    progressStages.setAttribute('aria-label', 'Generation stages');
    progressStages.hidden = true;
    progressStages.innerHTML = '';
    progressPercent.setAttribute('aria-valuenow', String(progress.percent));
    progressPercent.setAttribute('aria-valuetext', `${progress.percent}% ${queued ? progress.activityLabel : progress.stageLabel}`);
    progressPercent.textContent = `${progress.percent}%`;
    announceProgress(queued ? 'Queued' : 'Generating', queued ? progress.activityLabel : progress.stageLabel);
    progressBar.style.width = `${progress.percent}%`;
    progressCopy.textContent = queued
      ? 'Waiting for a generation slot. You can close this panel — generation keeps going.'
      : 'Subtitles appear on the video as each batch finishes. You can close this panel — generation keeps going.';
  } else if (correctionRunning && state.lyricsCorrection) {
    const progress = lyricsCorrectionProgress(state.lyricsCorrection.stage);
    progressLabel.textContent = 'Replacing lyrics';
    progressStages.setAttribute('aria-label', 'Replacement stages');
    progressStages.hidden = false;
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

function renderGenerateNote(state: PanelState, supported: boolean): void {
  if (!supported) {
    generateNote.textContent = '';

    return;
  }

  const duration = videoDurationForState(state);
  generateNote.textContent = typeof duration === 'number'
    ? `Estimated usage: ${Math.max(1, Math.ceil(duration / 60))} plan minutes for the full video`
    : 'The full video duration counts toward your plan minutes.';
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
  accountModel.hidden = !authenticated;
  accountModel.textContent = authenticated ? `Next generation: ${settings.aiProvider === 'cerebras' ? 'Transcriber Spark' : 'Transcriber'}` : '';
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
  dismissGenerationConfirmation();
  const emptyAccountState = anonymousAccountState();

  latestState = null;
  currentSettings = null;
  generationCancelFeedback = null;
  statusBanner.hidden = false;
  statusBanner.className = 'status-banner';
  statusBanner.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : 'Unable to load extension state';
  nowPlayingEyebrow.textContent = 'No video';
  nowPlayingTitle.textContent = 'Open a YouTube video';
  nowPlayingMeta.textContent = '';
  watchUnsupported.hidden = false;
  watchSignin.hidden = true;
  watchSetup.hidden = true;
  progressContainer.hidden = true;
  progressSummary.hidden = true;
  backTranscriptButton.hidden = true;
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

  if (errorTarget === 'generation-selection') {
    savedGenerations.showError(message);
    return;
  }

  if (errorTarget === 'account') {
    showAccountFeedback('error', message);

    return;
  }

  if (errorTarget === 'settings') {
    statusBanner.hidden = false;
    statusBanner.textContent = message;

    return;
  }

  if (errorTarget === 'quickfix') {
    quickFixError = { message, code: errorCode, reason: details?.reason };

    return;
  }

  if (errorTarget === 'correction') {
    lyricsReplaceConfirm = false;
    lyricsCorrectionStatus.textContent = message;

    return;
  }

  if (errorTarget === 'cancel') {
    correctionCancelError.hidden = false;
    correctionCancelError.textContent = message;

    return;
  }

  if (errorTarget === 'generation-cancel' || errorTarget === 'generation-start') {
    generationCancelFeedback = { kind: 'error', message };
    statusBanner.hidden = false;
    statusBanner.className = 'status-banner';
    statusBanner.textContent = message;

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
  if (busy) dismissGenerationConfirmation();
  generateButton.disabled = generationUnavailable();

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
  aiProviderSelect.disabled = disabled;
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
  const authenticated = latestState?.accountState.status === 'authenticated';
  accountEmailInput.disabled = disabled || accountRequestBusy || authenticated;
  accountPasswordInput.disabled = disabled || accountRequestBusy || authenticated;
  accountLoginButton.disabled = disabled || accountRequestBusy || authenticated;
  logoutButton.disabled = disabled || accountRequestBusy || !authenticated;
  clearStateButton.disabled = disabled;
  resetTimingButton.disabled = disabled;
  timingOffsetRangeInput.disabled = disabled;
  timingOffsetNumberInput.disabled = disabled;
}
