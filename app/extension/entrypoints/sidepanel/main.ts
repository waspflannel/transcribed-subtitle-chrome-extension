import { t, INTERFACE_LOCALES, isInterfaceLocale, setInterfaceLocale, localizeDocument, interfaceLocale } from '../../utils/i18n';
import './style.css';
import { bindSavedGenerations } from './saved-generations';
import { bindKnownLyrics } from './known-lyrics';
import { bindInstanceSettings } from './instance-settings';
import { bindCodexAccount } from './codex-account';
import { guardCodexAccount } from '../../utils/api-response-guards';

import { browser } from 'wxt/browser';

import {
  SOURCE_LANGUAGE_OPTIONS,
  TARGET_LANGUAGE_OPTIONS,
  isSourceLanguage,
  isTargetLanguage,
  languageLabel,
} from '../../utils/languages';
import { isRuntimeMessage } from '../../utils/messages';
import type { PanelRequest, PanelState } from '../../utils/messages';
import { generationProgress } from '../../utils/panel-progress';
import { escapeHtml } from '../../utils/html';
import { aiProviderLabel, DEFAULT_EXTENSION_SETTINGS, type ExtensionSettings } from '../../utils/settings-model';
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
type RequestErrorTarget = 'global' | 'settings' | 'correction' | 'quickfix' | 'cancel' | 'generation-start' | 'generation-cancel' | 'generation-selection';
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
  aiProviderSelect,
  overlayPositionSelect,
  captionFontSizeSelect,
  captionDensitySelect,
  captionContrastThemeSelect,
  overlayVisibleInput,
  overlayAttachedToVideoInput,
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
  settingsLanguageSummary,
  shortcutHelpList,
} = getPanelDom();

const interfaceLanguageSelect = document.querySelector<HTMLSelectElement>('[data-interface-language]')!;
const aiSourceInputs = [...document.querySelectorAll<HTMLInputElement>('input[name="aiSource"]')];
const apiOptions = document.querySelector<HTMLElement>('[data-api-options]')!;
const codexOptions = document.querySelector<HTMLElement>('[data-codex-options]')!;
const codexModelSelect = document.querySelector<HTMLSelectElement>('select[name="codexModel"]')!;
const codexFastModeInput = document.querySelector<HTMLInputElement>('input[name="codexFastMode"]')!;
const codexReadiness = document.querySelector<HTMLElement>('[data-codex-readiness]')!;
const aiBilling = document.querySelector<HTMLElement>('[data-ai-billing]')!;
interfaceLanguageSelect.innerHTML = '<option value="auto" data-i18n="Browser language">Browser language</option>'
  + Object.entries(INTERFACE_LOCALES).map(([code, name]) => `<option value="${code}">${name}</option>`).join('');
interfaceLanguageSelect.addEventListener('change', () => {
  const value = interfaceLanguageSelect.value;
  if (value === 'auto' || isInterfaceLocale(value)) void updateSettings({ interfaceLocale: value });
});
setInterfaceLocale('auto');
localizeDocument(document);
let appliedInterfaceLocale = interfaceLocale();

let currentSettings: ExtensionSettings | null = null;
let latestState: PanelState | null = null;
let sourceLanguageQuery = '';
let targetLanguageQuery = '';
let generationRequestBusy = false;
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
let lastProgressAnnouncement: string | null = null;
let codexModelOptionsKey = '';

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
generateButton.addEventListener('click', () => void generateSubtitles());
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
toggleLanguagesButton.addEventListener('click', () => {
  setLanguagesExpanded(!languagesExpanded);
});
toggleSetupButton.addEventListener('click', () => openWatchScreen('generate'));
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
for (const input of aiSourceInputs) input.addEventListener('change', () => {
  if (!input.checked) return;
  const aiProvider = input.value === 'codex' ? 'codex' : aiProviderSelect.value === 'cerebras' ? 'cerebras' : 'openai';
  const codexModel = currentSettings?.codexModel || latestState?.codexAccount?.models[0]?.id || '';
  void updateSettings({ aiProvider, ...(aiProvider === 'codex' ? { codexModel } : {}) });
});
codexModelSelect.addEventListener('change', () => {
  const model = latestState?.codexAccount?.models.find(model => model.id === codexModelSelect.value);
  if (model) void updateSettings({ codexModel: model.id, ...(!model.supportsFastMode ? { codexFastMode: false } : {}) });
});
codexFastModeInput.addEventListener('change', () => void updateSettings({ codexFastMode: codexFastModeInput.checked }));
overlayVisibleInput.addEventListener('change', () => void updateSettings({ overlayVisible: overlayVisibleInput.checked }));
overlayAttachedToVideoInput.addEventListener('change', () =>
  void updateSettings({ overlayAttachedToVideo: overlayAttachedToVideoInput.checked }),
);
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
bindKnownLyrics(document);
const instanceSettings = bindInstanceSettings(document, patch => sendPanelRequest({ type: 'panel.saveInstanceSettings', patch }, 'settings', 'mutation'));
const codexAccount = bindCodexAccount(document, async type => {
  const response = await browser.runtime.sendMessage({ type });
  if (response?.ok === false) throw new Error(response.error);
  return guardCodexAccount(response);
}, account => {
  if (latestState) showPanelState({ ...latestState, codexAccount: account, codexAccountError: undefined });
}, url => browser.tabs.create({ url }));
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
  if (!state?.pageStatus?.supported || state.activeTabId === undefined) return null;
  return JSON.stringify([state.activeTabId, state.pageStatus.videoId]);
}

function generationUnavailable(): boolean {
  return generationRequestBusy || generationCancellationRequestBusy || settingsRequestsInFlight > 0
    || lyricsCorrectionRequestBusy || quickFixRequestBusy || lyricsCancellationRequestBusy
    || latestState?.subtitleState.type === 'loading' || isActiveLyricsCorrection(latestState?.lyricsCorrection)
    || currentGenerationContext() === null;
}



async function generateSubtitles(): Promise<void> {
  const state = latestState;
  if (generationUnavailable() || !state?.pageStatus?.supported || state.activeTabId === undefined) return;

  generationCancelFeedback = null;
  generationRequestBusy = true;
  generateButton.disabled = true;
  generateButton.textContent = t("Starting...");

  try {
    const applied = await sendPanelRequest({ type: 'panel.generateSubtitles', youtubeVideoId: state.pageStatus.videoId, tabId: state.activeTabId }, 'generation-start', 'mutation');
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
      generationCancelFeedback = { kind: 'success', message: t("Generation cancelled.") };
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
  lyricsCorrectionCount.textContent = t("{value1} / 25,000 characters", {value1: count.toLocaleString()});
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
    quickFixNotice = t("Saved.");
    transcriptSearch.focus({ preventScroll: true });
  } else if (quickFixError?.code === 'lyrics_correction_in_progress' && quickFixError.reason === 'stale_track') {
    clearQuickFixSelection();
    quickFixNotice = t("The subtitles changed — the latest version is loaded.");
    void refreshBackendState();
    transcriptSearch.focus({ preventScroll: true });
  } else {
    transcriptView.setQuickFixError(quickFixError?.message ?? t("Could not save. Try again."));
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
  const ready = latestState?.subtitleState.type === 'ready' && (latestState.subtitleState.track.expiresAt === null || Date.parse(latestState.subtitleState.track.expiresAt) > Date.now());
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
  lyricsConfirmation.textContent = t("This replaces the entire transcript and has no undo. Your current subtitles stay active until the replacement succeeds.");
  lyricsCorrectionButton.hidden = lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.hidden = !lyricsReplaceConfirm;
  cancelLyricsConfirmationButton.hidden = !lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.textContent = t("Replace entire track");
  cancelLyricsConfirmationButton.textContent = t("Cancel");
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

  await sendPanelRequest({ type: 'panel.clearLocalState' }, 'global', 'mutation');
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
      showRequestError(t("The extension background did not respond. Reload Transcribe in chrome://extensions, then refresh YouTube and reopen the panel."), errorTarget);

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

function setLanguagesExpanded(expanded: boolean): void {
  languagesExpanded = expanded;
  languageExpand.hidden = !expanded;
  toggleLanguagesButton.textContent = expanded ? t("Done") : t("Change");
  toggleLanguagesButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

function showPanelState(state: PanelState): void {
  setInterfaceLocale(state.settings.interfaceLocale);
  if (appliedInterfaceLocale !== interfaceLocale()) {
    localizeDocument(document);
    appliedInterfaceLocale = interfaceLocale();
    lastProgressAnnouncement = null;
    setLanguagesExpanded(languagesExpanded);
  }
  interfaceLanguageSelect.value = state.settings.interfaceLocale;
  renderShortcutHelp();

  const previousInstanceId = latestState?.backendUrl;
  const nextInstanceId = state.backendUrl;
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
  savedGenerations.render(state);
  instanceSettings.render(state);
  codexAccount.render(state);

  if (previousInstanceId !== nextInstanceId) {
    correctionCancelError.hidden = true;
    correctionCancelError.textContent = '';
    generationCancelFeedback = null;
  }

  const pageStatus = state.pageStatus;
  const settings = state.settings;
  const supported = Boolean(pageStatus?.supported);
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
  showWatchState(state, supported);
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

  renderSettingsSummary(settings);

  generateButton.disabled = generationUnavailable();
  renderLyricsEditState();
  renderLyricsCorrectionState(state);
  generateButton.textContent = generateButtonLabel(subtitleState.type);
  renderGenerateNote(state, supported);

  renderLanguagePair(settings);
  renderLanguagePickers(settings);
  overlayVisibleInput.checked = settings.overlayVisible;
  overlayAttachedToVideoInput.checked = settings.overlayAttachedToVideo;
  if (settings.aiProvider !== 'codex') aiProviderSelect.value = settings.aiProvider;
  renderAiOptions(state);
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

  nowPlayingEyebrow.textContent = supported ? t("Now playing") : t("No video");
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
  correctionSyncError.textContent = t(state.lyricsCorrectionSyncError ?? '');

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
    correctionTerminalMessage.textContent = t("Lyrics replaced — the transcript is up to date.");
    return;
  }

  correctionTerminalStatus.className = 'status-banner correction-status';
  correctionTerminalMessage.textContent = correction.status === 'cancelled'
    ? t("Replacement cancelled. Your current subtitles are unchanged.")
    : t(correction.message || '') || t("Replacement failed. Your current subtitles are unchanged.");
}

function showStatusBanner(state: PanelState): void {
  if (generationCancelFeedback) {
    statusBanner.hidden = false;
    statusBanner.className = `status-banner${generationCancelFeedback.kind === 'success' ? ' success' : ''}`;
    statusBanner.textContent = t(generationCancelFeedback.message);

    return;
  }

  if (state.subtitleState.type === 'error') {
    statusBanner.hidden = false;
    statusBanner.className = 'status-banner';
    statusBanner.textContent = t(state.subtitleState.message || "") || t("Generation failed.");

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

/** Toggle the Watch tab's mutually exclusive states: unsupported page, setup, progress, transcript. */
function showWatchState(state: PanelState, supported: boolean): void {
  const subtitleState = state.subtitleState;
  const loading = subtitleState.type === 'loading';
  const partial = loading && (subtitleState.partialTrack?.cues.length ?? 0) > 0;
  const ready = subtitleState.type === 'ready';
  const correctionRunning = isActiveLyricsCorrection(state.lyricsCorrection);
  const generationInProgress = loading
    && (subtitleState.status === 'queued' || subtitleState.status === 'running');
  const canCancelGeneration = generationInProgress && subtitleState.jobId !== undefined;

  watchUnsupported.hidden = supported;
  watchSetup.hidden = !supported || loading || (ready && watchScreen !== 'generate');
  if (watchScreen === 'progress' && !loading && !correctionRunning) watchScreen = 'transcript';
  progressContainer.hidden = (!loading && !correctionRunning) || (ready && watchScreen !== 'progress');
  progressSummary.hidden = !ready || !correctionRunning || watchScreen !== 'transcript';
  progressSummaryLabel.textContent = correctionRunning ? t("Updating lyrics…") : '';
  backTranscriptButton.hidden = !ready || watchScreen === 'transcript';
  backTranscriptButton.disabled = quickFixRequestBusy || lyricsCorrectionRequestBusy;
  readyToolbar.hidden = watchScreen !== 'transcript' || partial;
  transcriptList.hidden = watchScreen !== 'transcript';
  transcriptStatus.hidden = watchScreen !== 'transcript';
  quickFixStatus.hidden = watchScreen !== 'transcript';
  watchReady.hidden = !ready && !partial;
  cancelGenerationButton.hidden = !generationInProgress;
  cancelGenerationButton.disabled = generationCancellationRequestBusy || !canCancelGeneration;
  cancelGenerationButton.textContent = generationCancellationRequestBusy ? t("Cancelling…") : t("Cancel generation");
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

    progressLabel.textContent = queued ? t("Queued") : t("Generating");
    progressStages.setAttribute('aria-label', t("Generation stages"));
    progressStages.hidden = true;
    progressStages.innerHTML = '';
    progressPercent.setAttribute('aria-valuenow', String(progress.percent));
    progressPercent.setAttribute('aria-valuetext', `${progress.percent}% ${queued ? progress.activityLabel : progress.stageLabel}`);
    progressPercent.textContent = `${progress.percent}%`;
    announceProgress(queued ? t("Queued") : t("Generating"), queued ? progress.activityLabel : progress.stageLabel);
    progressBar.style.width = `${progress.percent}%`;
    progressCopy.textContent = queued
      ? t("Waiting for a generation slot. You can close this panel — generation keeps going.")
      : t("Subtitles appear on the video as each batch finishes. You can close this panel — generation keeps going.");
  } else if (correctionRunning && state.lyricsCorrection) {
    const progress = lyricsCorrectionProgress(state.lyricsCorrection.stage);
    progressLabel.textContent = t("Replacing lyrics");
    progressStages.setAttribute('aria-label', t("Replacement stages"));
    progressStages.hidden = false;
    progressPercent.setAttribute('aria-valuenow', String(progress.percent));
    progressPercent.setAttribute('aria-valuetext', `${progress.percent}% ${progress.label}`);
    progressPercent.textContent = `${progress.percent}%`;
    announceProgress(t("Replacing lyrics"), progress.label);
    progressBar.style.width = `${progress.percent}%`;
    progressStages.innerHTML = lyricsCorrectionStageChecklistHtml(state.lyricsCorrection.stage);
    progressCopy.textContent = t("Your current subtitles stay active while the replacement runs.");
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
      <span>${escapeHtml(t(item.label))}</span>
    </li>
  `).join('');
}

function renderGenerateNote(state: PanelState, supported: boolean): void {
  generateNote.textContent = supported && (videoDurationForState(state) ?? 0) > 1800
    ? t("Long videos can take longer and use more provider credits. Generation is still available.") : '';
}

function renderAiOptions(state: PanelState): void {
  const codex = state.settings.aiProvider === 'codex';
  for (const input of aiSourceInputs) input.checked = input.value === (codex ? 'codex' : 'api');
  apiOptions.hidden = codex;
  codexOptions.hidden = !codex;
  const models = state.codexAccount?.models ?? [];
  const optionsKey = JSON.stringify([interfaceLocale(), models]);
  if (optionsKey !== codexModelOptionsKey) {
    codexModelOptionsKey = optionsKey;
    codexModelSelect.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = t('Select a Codex model');
    codexModelSelect.append(placeholder);
    for (const model of models) {
      const option = document.createElement('option');
      option.value = model.id;
      option.textContent = model.name === model.id ? model.id : `${model.name} (${model.id})`;
      codexModelSelect.append(option);
    }
  }
  codexModelSelect.value = state.settings.codexModel;
  codexFastModeInput.checked = state.settings.codexFastMode;
  codexReadiness.textContent = !state.codexAccount?.connected ? t('Connect Codex in Settings before generating subtitles.')
    : !models.some(model => model.id === state.settings.codexModel) ? t('Select an available Codex model before generating subtitles.') : '';
  aiBilling.textContent = codex
    ? t('Text analysis uses Codex credits. Audio transcription uses your ElevenLabs API key.')
    : t('Text analysis and audio transcription use your API keys.');
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



function renderSettingsSummary(settings: ExtensionSettings): void {
  settingsLanguageSummary.textContent = `${languageLabel(settings.sourceLanguage)} → ${languageLabel(settings.targetLanguage)}`;
}

function renderShortcutHelp(): void {
  shortcutHelpList.innerHTML = shortcutHelpHtml();
}

function showError(error: unknown): void {


  latestState = null;
  currentSettings = null;
  generationCancelFeedback = null;
  statusBanner.hidden = false;
  statusBanner.className = 'status-banner';
  statusBanner.textContent =
    typeof error === 'string' ? error : error instanceof Error ? error.message : t("Unable to load extension state");
  nowPlayingEyebrow.textContent = t("No video");
  nowPlayingTitle.textContent = t("Open a YouTube video");
  nowPlayingMeta.textContent = '';
  watchUnsupported.hidden = false;
  watchSetup.hidden = true;
  progressContainer.hidden = true;
  progressSummary.hidden = true;
  backTranscriptButton.hidden = true;
  watchReady.hidden = true;
  jobsList.innerHTML = `<p class="empty-state">${escapeHtml(t("Unable to load jobs."))}</p>`;
  renderLanguagePair(null);
  renderLanguagePickers(null);

  settingsLanguageSummary.textContent = t("Unavailable");
  generateButton.disabled = true;
  generateButton.textContent = t("Generate subtitles");
  generateNote.textContent = '';
  overlayVisibleInput.checked = DEFAULT_EXTENSION_SETTINGS.overlayVisible;
  overlayAttachedToVideoInput.checked = DEFAULT_EXTENSION_SETTINGS.overlayAttachedToVideo;
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
      : t("Unable to load extension state");

  if (errorTarget === 'generation-selection') {
    savedGenerations.showError(message);
    return;
  }


  if (errorTarget === 'settings') {
    statusBanner.hidden = false;
    statusBanner.textContent = t(message);

    return;
  }

  if (errorTarget === 'quickfix') {
    quickFixError = { message, code: errorCode, reason: details?.reason };

    return;
  }

  if (errorTarget === 'correction') {
    lyricsReplaceConfirm = false;
    lyricsCorrectionStatus.textContent = t(message);

    return;
  }

  if (errorTarget === 'cancel') {
    correctionCancelError.hidden = false;
    correctionCancelError.textContent = t(message);

    return;
  }

  if (errorTarget === 'generation-cancel' || errorTarget === 'generation-start') {
    generationCancelFeedback = { kind: 'error', message };
    statusBanner.hidden = false;
    statusBanner.className = 'status-banner';
    statusBanner.textContent = t(message);

    return;
  }

  showError(error);
}




function setSettingsDisabled(disabled: boolean): void {
  sourceLanguageSearchInput.disabled = disabled;
  targetLanguageSearchInput.disabled = disabled;
  for (const button of [...sourceLanguageList.querySelectorAll('button'), ...targetLanguageList.querySelectorAll('button')]) {
    button.disabled = disabled;
  }
  overlayVisibleInput.disabled = disabled;
  overlayAttachedToVideoInput.disabled = disabled;
  aiProviderSelect.disabled = disabled;
  for (const input of aiSourceInputs) input.disabled = disabled;
  codexModelSelect.disabled = disabled || !latestState?.codexAccount?.connected;
  codexFastModeInput.disabled = disabled || !latestState?.codexAccount?.connected
    || (!latestState.settings.codexFastMode && !latestState.codexAccount.models.find(model => model.id === latestState?.settings.codexModel)?.supportsFastMode);
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





  clearStateButton.disabled = disabled;
  resetTimingButton.disabled = disabled;
  timingOffsetRangeInput.disabled = disabled;
  timingOffsetNumberInput.disabled = disabled;
}
