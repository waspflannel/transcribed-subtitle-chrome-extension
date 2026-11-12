import { t, INTERFACE_LOCALES, isInterfaceLocale, setInterfaceLocale, localizeDocument, interfaceLocale } from '../../utils/i18n';
import './style.css';
import { bindSavedGenerations } from './saved-generations';
import { bindKnownLyrics } from './known-lyrics';
import { bindInstanceSettings } from './instance-settings';
import { bindCodexAccount } from './codex-account';
import { bindClaudeAccount } from './claude-account';
import { bindLyricsAiOptions, correctionAiLabel } from './lyrics-ai-options';
import { hydrateIcons } from '../../utils/panel/icons';
import { bindSheets } from './sheets';
import { bindToast } from './toast';
import { closeMenu, openMenu } from './menus';
import { modelText, pairText } from './saved-generations';
import { guardClaudeAccount, guardCodexAccount } from '../../utils/api-response-guards';

import { browser } from 'wxt/browser';

import {
  SOURCE_LANGUAGE_OPTIONS,
  TARGET_LANGUAGE_OPTIONS,
  isSourceLanguage,
  isTargetLanguage,
  languageLabel,
  languageTag,
} from '../../utils/languages';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, UNDO_DELETE_MS } from '../../utils/messages';
import type { PanelRequest, PanelState, PendingJobDelete } from '../../utils/messages';
import { generationProgress } from '../../utils/panel-progress';
import { escapeHtml } from '../../utils/html';
import { aiSelectionLabel, CLAUDE_API_MODELS, CLAUDE_FAST_MODELS, CLAUDE_MODELS, DEFAULT_EXTENSION_SETTINGS, isClaudeApiModel, isClaudeModel, type ExtensionSettings } from '../../utils/settings-model';
import { isDeletableJob, renderJobHistory } from './render/job-history';
import { renderLanguagePicker } from './render/language-picker';
import { shortcutHelpHtml } from './render/shortcuts';
import { onTabShown, setupTabs, showTab } from './tabs';
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
import type { LyricsCorrectionStatus, SubtitleJobHistoryItem } from '../../utils/contracts';
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
  toggleSetupButton,
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
  screenHead,
  screenTitle,
  languageSummary,
  aiSummary,
  aiSummarySettings,
  trackButton,
  trackPair,
  trackModel,
  trackMenu,
  toolsButton,
  toolsMenu,
  deleteCurrentButton,
  deleteCurrentLabel,
  searchBox,
  searchToggle,
  timingButton,
  timingMenu,
  timingOffsetLarge,
  captionContrastInput,
  settingSegments,
  lyricsConfirmationBox,
  lyricsAiReadinessInline,
  toast: toastElement,
  confirmDialog,
  tabInk,
  scroll,
  historySelectButton,
  historySelectAllButton,
  historyDeleteButton,
  historyDeleteLabel,
  historyBulk,
  historyCount,
  history: historyView,
  sheetLanguages,
  sheetAi,
  sheetLyricsAi,
} = getPanelDom();

const interfaceLanguageSelect = document.querySelector<HTMLSelectElement>('[data-interface-language]')!;
const aiSourceInputs = [...document.querySelectorAll<HTMLInputElement>('input[name="aiSource"]')];
const apiOptions = document.querySelector<HTMLElement>('[data-api-options]')!;
const codexOptions = document.querySelector<HTMLElement>('[data-codex-options]')!;
const codexModelSelect = document.querySelector<HTMLSelectElement>('select[name="codexModel"]')!;
const codexFastModeInput = document.querySelector<HTMLInputElement>('input[name="codexFastMode"]')!;
const codexReadiness = document.querySelector<HTMLElement>('[data-codex-readiness]')!;
const claudeOptions = document.querySelector<HTMLElement>('[data-claude-options]')!;
const claudeModelSelect = document.querySelector<HTMLSelectElement>('select[name="claudeModel"]')!;
const claudeApiOptions = document.querySelector<HTMLElement>('[data-claude-api-options]')!;
const claudeApiModelSelect = document.querySelector<HTMLSelectElement>('select[name="claudeApiModel"]')!;
const claudeApiFastModeInput = document.querySelector<HTMLInputElement>('input[name="claudeApiFastMode"]')!;
for (const [select, models] of [[claudeModelSelect, CLAUDE_MODELS], [claudeApiModelSelect, CLAUDE_API_MODELS]] as const) {
  for (const [value, label] of Object.entries(models)) select.append(Object.assign(document.createElement('option'), { value, textContent: label }));
}
const aiBilling = document.querySelector<HTMLElement>('[data-ai-billing]')!;
interfaceLanguageSelect.innerHTML = '<option value="auto" data-i18n="Browser language">Browser language</option>'
  + Object.entries(INTERFACE_LOCALES).map(([code, name]) => `<option value="${code}">${name}</option>`).join('');
interfaceLanguageSelect.addEventListener('change', () => {
  const value = interfaceLanguageSelect.value;
  if (value === 'auto' || isInterfaceLocale(value)) void updateSettings({ interfaceLocale: value });
});
setInterfaceLocale('auto');
localizeDocument(document);
hydrateIcons(document);
let appliedInterfaceLocale = interfaceLocale();
const sheets = bindSheets(document, scroll);
const toast = bindToast(toastElement);
const sheetsByName: Record<string, HTMLElement> = { languages: sheetLanguages, ai: sheetAi, 'lyrics-ai': sheetLyricsAi };
/* Jobs waiting out their undo window. The panel hides them right away. */
const pendingDeletes = new Set<string>();
let settling: Promise<unknown> = Promise.resolve();
let historySelecting = false;
const historySelected = new Set<string>();

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
let watchScreen: 'transcript' | 'replace' | 'generate' | 'progress' = 'transcript';
let lastWatchVideoId: string | null = null;
let lyricsReplaceConfirm = false;
const dismissedCorrectionAttempts = new Set<string>();
let quickFixSelection: { cueId: string; tokenIndex: number; text: string } | null = null;
let quickFixError: { message: string; code?: string; reason?: string } | null = null;

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
  closeMenu(toolsMenu);
  sheets.closeAll();
  showTab(tabButtons, panels, 'watch');
  watchScreen = screen;
  if (latestState) showPanelState(latestState);
  scroll.scrollTop = 0;
  if (screen === 'transcript') trackButton.focus({ preventScroll: true });
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

  if (button) {
    void cancelGeneration(button);
    return;
  }
  const remove = target?.closest<HTMLElement>('[data-job-delete]');
  const removeJob = remove && latestState?.jobHistory.find(job => job.jobId === remove.dataset.jobDelete);
  if (removeJob) {
    void leaveRows([removeJob.jobId]).then(() => deleteJobs([removeJob], t('Deleted from history'),
      latestState?.videoTitles?.[removeJob.youtubeVideoId] ?? removeJob.youtubeVideoId));
    return;
  }
  // In select mode the whole card toggles; links and buttons keep their own job.
  const item = historySelecting ? target?.closest<HTMLElement>('.job-item') : null;
  const job = item && latestState?.jobHistory.find(candidate => candidate.jobId === item.dataset.jobId);
  if (!item || !job || !isDeletableJob(job) || target?.closest('a, button')) return;
  const checkbox = target?.closest<HTMLInputElement>('input[data-job-select]');
  const selected = checkbox ? checkbox.checked : !historySelected.has(job.jobId);
  if (selected) historySelected.add(job.jobId);
  else historySelected.delete(job.jobId);
  renderHistory();
  jobsList.querySelector<HTMLElement>(`[data-job-select="${CSS.escape(job.jobId)}"]`)?.focus({ preventScroll: true });
});
clearStateButton.addEventListener('click', () => {
  confirmAction(t('Clear local state'), t('Removes this browser’s settings, remembered tracks and Known Lyrics. Saved tracks on your backend stay.'), t('Clear'), () => void clearLocalState());
});
toggleSetupButton.addEventListener('click', () => openWatchScreen('generate'));
document.addEventListener('click', (event) => {
  const opener = (event.target as Element)?.closest?.<HTMLElement>('[data-action="open-sheet"]');
  const sheet = opener ? sheetsByName[opener.dataset.sheet ?? ''] : undefined;
  if (opener && sheet && !(opener as HTMLButtonElement).disabled) sheets.open(sheet, opener);
});
trackButton.addEventListener('click', () => openMenu(trackMenu, trackButton));
toolsButton.addEventListener('click', () => openMenu(toolsMenu, toolsButton));
toolsMenu.addEventListener('click', (event) => {
  if ((event.target as Element)?.closest('button')) closeMenu(toolsMenu);
});
deleteCurrentButton.addEventListener('click', () => {
  const track = latestState?.subtitleState.type === 'ready' ? latestState.subtitleState.track : null;
  if (!track) return;
  const job = savedGenerations.jobs().find(item => item.jobId === track.jobId)
    ?? latestState?.jobHistory.find(item => item.jobId === track.jobId);
  void deleteJobs([job ?? { jobId: track.jobId, youtubeVideoId: track.youtubeVideoId, trackId: track.trackId }],
    t('Track deleted'), job ? `${pairText(job)} · ${modelText(job)}` : undefined);
});
timingButton.addEventListener('click', () => openMenu(timingMenu, timingButton));
searchToggle.addEventListener('click', () => setSearchOpen(searchBox.hidden));
transcriptSearch.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    event.preventDefault();
    setSearchOpen(false);
  }
});
for (const segment of settingSegments) {
  segment.addEventListener('click', (event) => {
    const button = (event.target as Element)?.closest<HTMLButtonElement>('button[data-value]');
    const value = button?.dataset.value;
    if (!button || button.disabled || !value) return;
    for (const option of segment.querySelectorAll('button')) option.setAttribute('aria-pressed', String(option === button));
    const setting = segment.dataset.setting;
    if (setting === 'overlayPosition' && (value === 'bottom' || value === 'top' || value === 'compact')) void updateSettings({ overlayPosition: value });
    if (setting === 'captionFontSize' && (value === 'small' || value === 'medium' || value === 'large')) void updateSettings({ captionFontSize: value });
    if (setting === 'captionDensity' && (value === 'compact' || value === 'comfortable')) void updateSettings({ captionDensity: value });
  });
}
captionContrastInput.addEventListener('change', () => void updateSettings({ captionContrastTheme: captionContrastInput.checked ? 'high' : 'default' }));
historySelectButton.addEventListener('click', () => {
  historySelecting = !historySelecting;
  historySelected.clear();
  renderHistory();
});
historySelectAllButton.addEventListener('click', () => {
  const deletable = (latestState?.jobHistory ?? []).filter(isDeletableJob);
  if (deletable.every(job => historySelected.has(job.jobId))) historySelected.clear();
  else for (const job of deletable) historySelected.add(job.jobId);
  renderHistory();
});
historyDeleteButton.addEventListener('click', () => {
  const jobs = (latestState?.jobHistory ?? []).filter(job => historySelected.has(job.jobId));
  if (!jobs.length) return;
  historySelecting = false;
  historySelected.clear();
  void leaveRows(jobs.map(job => job.jobId)).then(() => deleteJobs(jobs,
    jobs.length === 1 ? t('Deleted from history') : t('{count} items deleted', { count: jobs.length })));
});
onTabShown((tab, previous) => {
  const order = tabButtons.map(button => button.dataset.tab);
  scroll.dataset.dir = previous && previous !== tab ? (order.indexOf(tab) > order.indexOf(previous) ? 'forward' : 'back') : '';
  sheets.closeAll();
  placeTabInk();
  if (tab !== 'history' && historySelecting) {
    historySelecting = false;
    historySelected.clear();
    renderHistory();
  }
});
if (typeof ResizeObserver === 'function') new ResizeObserver(placeTabInk).observe(tabButtons[0]!.parentElement!);
sourceLanguageSearchInput.addEventListener('input', handleSourceLanguageSearch);
targetLanguageSearchInput.addEventListener('input', handleTargetLanguageSearch);
sourceLanguageList.addEventListener('click', handleSourceLanguageClick);
targetLanguageList.addEventListener('click', handleTargetLanguageClick);
const lyricsAiOptions = bindLyricsAiOptions(document.querySelector<HTMLElement>('[data-lyrics-ai-options]')!, () => {
  lyricsReplaceConfirm = false;
  renderLyricsEditState();
});
const savedGenerations = bindSavedGenerations({
  button: trackButton,
  pairLabel: trackPair,
  modelLabel: trackModel,
  menu: trackMenu,
  status: document.querySelector<HTMLElement>('[data-generation-status]')!,
  selectGeneration: request => sendPanelRequest(request, 'generation-selection', 'mutation'),
  windowId: () => panelWindowId,
  applyPanelState: showPanelState,
  onJobsLoaded: jobs => lyricsAiOptions.setSavedJobs(jobs),
  onDelete: job => void deleteJobs([job], t('Track deleted'), `${pairText(job)} · ${modelText(job)}`),
  onGenerateNew: () => openWatchScreen('generate'),
  onSwitching: busy => transcriptList.setAttribute('aria-busy', String(busy)),
  hiddenJobIds: () => pendingDeletes,
});

aiProviderSelect.addEventListener('change', () => {
  const aiProvider = aiProviderSelect.value;
  if (aiProvider === 'openai' || aiProvider === 'cerebras' || aiProvider === 'anthropic') void updateSettings({ aiProvider });
});
for (const input of aiSourceInputs) input.addEventListener('change', () => {
  if (!input.checked) return;
  const aiProvider = input.value === 'codex' ? 'codex' : input.value === 'claude' ? 'claude'
    : aiProviderSelect.value === 'cerebras' || aiProviderSelect.value === 'anthropic' ? aiProviderSelect.value : 'openai';
  const codexModel = currentSettings?.codexModel || latestState?.codexAccount?.models[0]?.id || '';
  void updateSettings({ aiProvider, ...(aiProvider === 'codex' ? { codexModel } : {}) });
});
codexModelSelect.addEventListener('change', () => {
  const model = latestState?.codexAccount?.models.find(model => model.id === codexModelSelect.value);
  if (model) void updateSettings({ codexModel: model.id, ...(!model.supportsFastMode ? { codexFastMode: false } : {}) });
});
codexFastModeInput.addEventListener('change', () => void updateSettings({ codexFastMode: codexFastModeInput.checked }));
claudeModelSelect.addEventListener('change', () => {
  const claudeModel = claudeModelSelect.value;
  if (isClaudeModel(claudeModel)) void updateSettings({ claudeModel });
});
claudeApiModelSelect.addEventListener('change', () => {
  const claudeApiModel = claudeApiModelSelect.value;
  if (isClaudeApiModel(claudeApiModel)) void updateSettings({ claudeApiModel, ...(!CLAUDE_FAST_MODELS.includes(claudeApiModel) ? { claudeApiFastMode: false } : {}) });
});
claudeApiFastModeInput.addEventListener('change', () => void updateSettings({ claudeApiFastMode: claudeApiFastModeInput.checked }));
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
  extraOutput: timingOffsetLarge,
  resetButton: resetTimingButton,
  onCommit: (subtitleTimingOffsetSeconds) => updateSettings({ subtitleTimingOffsetSeconds }),
});

let backendRefreshInFlight = false;
let backendPollTimer: ReturnType<typeof setTimeout> | undefined;
const ACTIVE_POLL_INTERVAL_MS = 30_000;
const LYRICS_POLL_INTERVAL_MS = 2_000;
let lastFullBackendRefresh = 0;
const IDLE_POLL_INTERVAL_MS = 30_000;

setupTabs(tabButtons, panels);
bindKnownLyrics(document, {
  open: (panel, invoker) => {
    closeMenu(toolsMenu);
    sheets.open(panel, invoker);
  },
  onUse: (lyrics) => {
    sheets.closeAll();
    if (watchScreen !== 'replace') openWatchScreen('replace');
    lyricsCorrectionTextarea.value = lyrics;
    lyricsCorrectionTextarea.dispatchEvent(new Event('input'));
    lyricsCorrectionTextarea.focus({ preventScroll: true });
  },
  onDeleted: (title, restore) => toast.show({ message: t('Lyrics deleted.'), detail: title, undo: { ms: UNDO_DELETE_MS, run: restore } }),
});
const instanceSettings = bindInstanceSettings(document, patch => sendPanelRequest({ type: 'panel.saveInstanceSettings', patch }, 'settings', 'mutation'));
const codexAccount = bindCodexAccount(document, async type => {
  const response = await browser.runtime.sendMessage({ type });
  if (response?.ok === false) throw new Error(response.error);
  return guardCodexAccount(response);
}, account => {
  if (latestState) showPanelState({ ...latestState, codexAccount: account, codexAccountError: undefined });
}, url => browser.tabs.create({ url }));
const claudeAccount = bindClaudeAccount(document, async action => {
  const response = await browser.runtime.sendMessage(action);
  if (response?.ok === false) throw new Error(response.error);
  return guardClaudeAccount(response);
}, account => {
  if (latestState) showPanelState({ ...latestState, claudeAccount: account, claudeAccountError: undefined });
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
    transcriptView.focus();
  },
  onNotice: (message, kind) => toast.show({ message, kind }),
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
    const port = browser.runtime.connect({ name: 'panel' });
    port.onMessage.addListener(message => {
      if (message.type === 'background.subtitleStateChanged' && latestState && latestState.activeTabId === message.tabId) {
        showPanelState({ ...latestState, subtitleState: message.subtitleState });
        if (message.subtitleState.type !== 'loading') void refreshBackendState();
      }
    });
    port.onDisconnect.addListener(() => {
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
    sheets.closeAll();
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
  if (!lyricsAiOptions.ready() || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection)) return;
  lyricsReplaceConfirm = true;
  renderLyricsEditState();
  confirmLyricsCorrectionButton.focus();
}

async function applyConfirmedLyricsCorrection(): Promise<void> {
  const state = latestState;
  const subtitleState = state?.subtitleState;
  const page = state?.pageStatus;

  if (lyricsCorrectionRequestBusy || !state || subtitleState?.type !== 'ready' || !page?.supported || !lyricsAiOptions.ready() || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, state.lyricsCorrection)) return;

  lyricsCorrectionRequestBusy = true;
  renderLyricsEditState();
  try {
    const applied = await sendPanelRequest({
      type: 'panel.submitLyricsCorrection',
      jobId: subtitleState.track.jobId,
      trackId: subtitleState.track.trackId,
      youtubeVideoId: page.videoId,
      lyrics: lyricsCorrectionTextarea.value,
      ...lyricsAiOptions.payload(),
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
  lyricsCorrectionButton.disabled = lyricsCorrectionRequestBusy || !lyricsAiOptions.ready() || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection);
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
    toast.show({ message: t("Saved."), detail: t("Updates this line’s translation, pronunciation, and word cards.") });
    transcriptView.focus();
  } else if (quickFixError?.code === 'lyrics_correction_in_progress' && quickFixError.reason === 'stale_track') {
    clearQuickFixSelection();
    toast.show({ message: t("The subtitles changed — the latest version is loaded.") });
    void refreshBackendState();
    transcriptView.focus();
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
  if (latestState) lyricsAiOptions.render(latestState, lyricsCorrectionRequestBusy || activeCorrection);

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
  lyricsConfirmationBox.hidden = !lyricsReplaceConfirm;
  const readiness = document.querySelector<HTMLElement>('[data-lyrics-ai-readiness]');
  lyricsAiReadinessInline.textContent = readiness?.hidden ? '' : readiness?.textContent ?? '';
  lyricsAiReadinessInline.hidden = !lyricsAiReadinessInline.textContent;
  lyricsConfirmation.textContent = t("This replaces the entire transcript and has no undo. Your current subtitles stay active until the replacement succeeds.");
  lyricsCorrectionButton.hidden = lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.hidden = !lyricsReplaceConfirm;
  cancelLyricsConfirmationButton.hidden = !lyricsReplaceConfirm;
  confirmLyricsCorrectionButton.textContent = t("Replace entire track");
  cancelLyricsConfirmationButton.textContent = t("Cancel");
  lyricsCorrectionTextarea.disabled = lyricsCorrectionRequestBusy;
  confirmLyricsCorrectionButton.disabled = lyricsCorrectionRequestBusy || !lyricsAiOptions.ready() || !canApplyLyricsCorrection(lyricsCorrectionTextarea.value, latestState?.lyricsCorrection);

  transcriptView.setQuickFixMode(quickActive);
  deleteCurrentButton.disabled = !ready || activeCorrection || lyricsCorrectionRequestBusy || quickFixRequestBusy;
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
    let response: PanelResponse | undefined = await browser.runtime.sendMessage(requestWithWindow);

    if (!response && request.type === 'panel.getState') {
      await new Promise((resolve) => setTimeout(resolve, 150));
      response = await browser.runtime.sendMessage(requestWithWindow);
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

function showPanelState(incoming: PanelState): void {
  const state = withoutPendingDeletes(incoming);
  setInterfaceLocale(state.settings.interfaceLocale);
  if (appliedInterfaceLocale !== interfaceLocale()) {
    localizeDocument(document);
    appliedInterfaceLocale = interfaceLocale();
    lastProgressAnnouncement = null;
    placeTabInk();
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
  }
  savedGenerations.render(state);
  instanceSettings.render(state);
  codexAccount.render(state);
  claudeAccount.render(state);

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
    sheets.closeAll();
    setSearchOpen(false);
    lyricsCorrectionTextarea.value = '';
    clearQuickFixSelection();
    lyricsReplaceConfirm = false;
  } else if (previousCorrectionStatus !== 'completed' && state.lyricsCorrection?.status === 'completed') {
    lyricsCorrectionTextarea.value = '';
    lyricsReplaceConfirm = false;
  } else if (nextTrackId !== previousTrackId) {
    clearQuickFixSelection();
    lyricsCorrectionTextarea.value = '';
    /* A refreshed track invalidates the old replacement confirmation. */
    lyricsReplaceConfirm = false;
  }

  showStatusBanner(state);
  showWatchState(state, supported);
  if (subtitleState.type === 'ready') {
    const { track } = subtitleState;
    transcriptView.setData(track.youtubeVideoId, track.cues, settings, {
      source: languageTag(track.detectedSourceLanguage ?? track.sourceLanguage),
      target: languageTag(track.targetLanguage),
    });
    void pullActiveCue(state);
  } else if (subtitleState.type === 'loading' && subtitleState.partialTrack?.cues.length) {
    const { partialTrack } = subtitleState;
    transcriptView.setPartialData(partialTrack.youtubeVideoId, partialTrack.cues, settings, {
      source: languageTag(partialTrack.sourceLanguage),
      target: languageTag(partialTrack.targetLanguage),
    });
  } else {
    cueSnapshotRequest += 1;
    transcriptView.setData(null, [], settings);
  }
  renderHistory();

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
  if (settings.aiProvider === 'openai' || settings.aiProvider === 'cerebras' || settings.aiProvider === 'anthropic') aiProviderSelect.value = settings.aiProvider;
  renderAiOptions(state);
  renderSegments(settings);
  captionContrastInput.checked = settings.captionContrastTheme === 'high';
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

  nowPlayingTitle.textContent = nowPlayingTitleLabel(state);
  nowPlayingMeta.textContent = supported ? videoDurationLabel(state) : '';
  hydrateIcons(document);

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
    correctionTerminalStatus.className = 'dock-card correction-status success';
    correctionTerminalMessage.textContent = t("Lyrics replaced — the transcript is up to date.");
    return;
  }

  correctionTerminalStatus.className = 'dock-card correction-status error';
  correctionTerminalMessage.textContent = correction.status === 'cancelled'
    ? t("Replacement cancelled. Your current subtitles are unchanged.")
    : t(correction.message || '') || t("Replacement failed. Your current subtitles are unchanged.");
}

function showStatusBanner(state: PanelState): void {
  if (generationCancelFeedback?.kind === 'success') {
    toast.show({ message: t(generationCancelFeedback.message), detail: t('Work already sent to providers may still be billed.') });
    generationCancelFeedback = null;
  }

  if (generationCancelFeedback) {
    setStatusBanner(t(generationCancelFeedback.message));

    return;
  }

  if (state.subtitleState.type === 'error') {
    setStatusBanner(t(state.subtitleState.message || "") || t("Generation failed."));

    return;
  }

  setStatusBanner(null);
}

function setStatusBanner(message: string | null): void {
  statusBanner.hidden = message === null;
  statusBanner.className = 'dock-card error';
  if (statusBanner.textContent !== (message ?? '')) statusBanner.textContent = message ?? '';
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
  screenHead.hidden = !ready || watchScreen === 'transcript';
  const screenName = watchScreen === 'generate' ? "Generate again" : watchScreen === 'replace' ? "Lyric correction" : "Replacing lyrics";
  if (screenTitle.dataset.i18n !== screenName || screenTitle.textContent !== t(screenName)) {
    screenTitle.dataset.i18n = screenName;
    screenTitle.textContent = t(screenName);
  }
  progressContainer.hidden = (!loading && !correctionRunning) || (ready && watchScreen !== 'progress');
  progressSummary.hidden = !ready || !correctionRunning || watchScreen !== 'transcript';
  progressSummaryLabel.textContent = correctionRunning ? t("Updating lyrics…") : '';
  backTranscriptButton.hidden = !ready || watchScreen === 'transcript';
  backTranscriptButton.disabled = quickFixRequestBusy || lyricsCorrectionRequestBusy;
  readyToolbar.hidden = watchScreen !== 'transcript' || partial;
  transcriptList.hidden = watchScreen !== 'transcript';
  transcriptStatus.hidden = watchScreen !== 'transcript';
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
    progressCopy.textContent = [correctionAiLabel(state.lyricsCorrection), t("Your current subtitles stay active while the replacement runs.")].filter(Boolean).join(' · ');
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
  const source = codex ? 'codex' : state.settings.aiProvider === 'claude' ? 'claude' : 'api';
  for (const input of aiSourceInputs) input.checked = input.value === source;
  apiOptions.hidden = source !== 'api';
  codexOptions.hidden = !codex;
  claudeOptions.hidden = source !== 'claude';
  claudeModelSelect.value = state.settings.claudeModel;
  claudeApiOptions.hidden = state.settings.aiProvider !== 'anthropic';
  claudeApiModelSelect.value = state.settings.claudeApiModel;
  claudeApiFastModeInput.checked = state.settings.claudeApiFastMode && CLAUDE_FAST_MODELS.includes(state.settings.claudeApiModel);
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
  const summary = generationAiLabel(state);
  aiSummary.textContent = summary;
  aiSummarySettings.textContent = summary;
  aiBilling.textContent = codex
    ? t('Text analysis uses Codex credits. Audio transcription uses your ElevenLabs API key.')
    : source === 'claude' ? t('Text analysis uses your Claude subscription. Audio transcription uses your ElevenLabs API key.')
    : state.settings.aiProvider === 'anthropic' ? t('Text analysis uses your Claude API key. Audio transcription uses your ElevenLabs API key.')
    : t('Text analysis and audio transcription use your API keys.');
}

function renderLanguagePair(settings: ExtensionSettings | null): void {
  languageSummary.textContent = settings ? `${languageLabel(settings.sourceLanguage)} → ${languageLabel(settings.targetLanguage)}` : '—';
}

/** "OpenAI API · gpt-6-luna" for the setting that the next generation will use. */
function generationAiLabel(state: PanelState): string {
  const settings = state.settings;
  const fast = (on: boolean): string => on ? ` · ${t('Fast mode')}` : '';
  switch (settings.aiProvider) {
    case 'codex':
      return settings.codexModel ? `${aiSelectionLabel('codex', settings.codexModel)}${fast(settings.codexFastMode)}` : t('Codex credits');
    case 'claude':
      return aiSelectionLabel('claude', CLAUDE_MODELS[settings.claudeModel] ?? settings.claudeModel);
    case 'anthropic':
      return `${aiSelectionLabel('anthropic', CLAUDE_API_MODELS[settings.claudeApiModel] ?? settings.claudeApiModel)}${fast(settings.claudeApiFastMode && CLAUDE_FAST_MODELS.includes(settings.claudeApiModel))}`;
    default: {
      const model = state.instanceSettings?.providers[settings.aiProvider]?.model;
      return model ? aiSelectionLabel(settings.aiProvider, model) : aiSelectionLabel(settings.aiProvider, '').replace(/ · $/, '');
    }
  }
}

function renderSegments(settings: ExtensionSettings): void {
  const values: Record<string, string> = {
    overlayPosition: settings.overlayPosition, captionFontSize: settings.captionFontSize, captionDensity: settings.captionDensity,
  };
  for (const segment of settingSegments) {
    for (const button of segment.querySelectorAll<HTMLButtonElement>('button[data-value]')) {
      button.setAttribute('aria-pressed', String(button.dataset.value === values[segment.dataset.setting ?? '']));
    }
  }
}

function setSearchOpen(open: boolean): void {
  searchBox.hidden = !open;
  readyToolbar.classList.toggle('searching', open);
  searchToggle.setAttribute('aria-expanded', String(open));
  searchToggle.dataset.icon = open ? 'close' : 'search';
  searchToggle.replaceChildren();
  hydrateIcons(searchToggle.parentElement!);
  if (open) transcriptSearch.focus({ preventScroll: true });
  else if (transcriptSearch.value) {
    transcriptSearch.value = '';
    transcriptSearch.dispatchEvent(new Event('input'));
  }
}

function placeTabInk(): void {
  const active = tabButtons.find(button => button.getAttribute('aria-selected') === 'true');
  if (!active) return;
  tabInk.style.width = `${active.offsetWidth}px`;
  tabInk.style.transform = `translateX(${active.offsetLeft}px)`;
}

function confirmAction(title: string, copy: string, okLabel: string, run: () => void): void {
  const titleElement = confirmDialog.querySelector<HTMLElement>('[data-confirm-title]')!;
  const copyElement = confirmDialog.querySelector<HTMLElement>('[data-confirm-copy]')!;
  const ok = confirmDialog.querySelector<HTMLButtonElement>('[data-confirm-ok]')!;
  titleElement.textContent = title;
  copyElement.textContent = copy;
  ok.textContent = okLabel;
  confirmDialog.onclick = (event) => {
    const button = (event.target as Element)?.closest<HTMLElement>('[data-confirm]');
    if (!button) return;
    confirmDialog.close();
    if (button.dataset.confirm === 'ok') run();
  };
  if (typeof confirmDialog.showModal === 'function') confirmDialog.showModal();
  else run();
}

/** Hide jobs that are waiting out their undo window. A hidden current track reads as "no track". */
function withoutPendingDeletes(state: PanelState): PanelState {
  if (pendingDeletes.size === 0) return state;
  const hideTrack = state.subtitleState.type === 'ready' && pendingDeletes.has(state.subtitleState.track.jobId);
  return {
    ...state,
    jobHistory: state.jobHistory.filter(job => !pendingDeletes.has(job.jobId)),
    subtitleState: hideTrack ? DEFAULT_SUBTITLE_STATE : state.subtitleState,
  };
}

type DeletableJob = Pick<SubtitleJobHistoryItem, 'jobId' | 'youtubeVideoId'> & { trackId?: string };

/*
 * Delete now, undo for UNDO_DELETE_MS. The background holds the real DELETE until the
 * countdown ends, so closing the panel still deletes. A track that is showing switches
 * to the next saved track first; with none left, Watch returns to setup.
 */
async function deleteJobs(jobs: DeletableJob[], message: string, detail?: string): Promise<void> {
  const state = latestState;
  if (!state || jobs.length === 0) return;
  toast.dismiss(); // Settle any earlier undo window before starting a new one.
  await settling;
  const track = state.subtitleState.type === 'ready' ? state.subtitleState.track : null;
  const deletingCurrent = track !== null && jobs.some(job => job.jobId === track.jobId);
  for (const job of jobs) pendingDeletes.add(job.jobId);
  let switched: { from: string; to: string } | null = null;
  if (deletingCurrent && track && state.activeTabId !== undefined) {
    const next = savedGenerations.jobs().find(job => !pendingDeletes.has(job.jobId));
    if (next && await sendPanelRequest({ type: 'panel.selectGeneration', jobId: next.jobId, currentJobId: track.jobId,
      trackId: track.trackId, youtubeVideoId: track.youtubeVideoId, tabId: state.activeTabId }, 'settings', 'mutation')) {
      switched = { from: track.jobId, to: next.jobId };
    }
  }
  if (latestState) showPanelState(latestState);
  const payload: PendingJobDelete[] = jobs.map(job => {
    const showing = deletingCurrent && !switched && track?.jobId === job.jobId && state.activeTabId !== undefined;
    return {
      jobId: job.jobId,
      youtubeVideoId: job.youtubeVideoId,
      ...(showing ? { trackId: track!.trackId, tabId: state.activeTabId } : job.trackId ? { trackId: job.trackId } : {}),
    };
  });
  if (!await sendPanelRequest({ type: 'panel.deleteJobs', jobs: payload }, 'settings', 'mutation')) {
    for (const job of jobs) pendingDeletes.delete(job.jobId);
    if (latestState) showPanelState(latestState);
    return;
  }
  const jobIds = jobs.map(job => job.jobId);
  toast.show({
    message,
    detail,
    undo: {
      ms: UNDO_DELETE_MS,
      run: async () => {
        for (const jobId of jobIds) pendingDeletes.delete(jobId);
        await sendPanelRequest({ type: 'panel.undoDeleteJobs', jobIds }, 'settings', 'mutation');
        const now = latestState?.subtitleState.type === 'ready' ? latestState.subtitleState.track : null;
        if (switched && now?.jobId === switched.to && latestState?.activeTabId !== undefined) {
          await sendPanelRequest({ type: 'panel.selectGeneration', jobId: switched.from, currentJobId: now.jobId,
            trackId: now.trackId, youtubeVideoId: now.youtubeVideoId, tabId: latestState.activeTabId }, 'settings', 'mutation');
        }
        await sendPanelRequest({ type: 'panel.getState', syncBackend: true }, 'settings');
        void savedGenerations.refresh();
      },
    },
    onSettle: () => {
      settling = browser.runtime.sendMessage({ type: 'panel.flushDeletes', ...(typeof panelWindowId === 'number' ? { windowId: panelWindowId } : {}) }).catch(() => {});
    },
  });
  // ponytail: ids stay hidden for this panel session; a failed background delete shows again on reopen.
}

function renderHistory(): void {
  const state = latestState;
  historyView.classList.toggle('selecting', historySelecting);
  historySelectButton.textContent = historySelecting ? t('Done') : t('Select');
  historySelectButton.setAttribute('aria-pressed', String(historySelecting));
  historySelectButton.hidden = !state?.jobHistory.some(isDeletableJob);
  historyBulk.hidden = !historySelecting;
  historyCount.textContent = t('{count} selected', { count: historySelected.size });
  historyDeleteLabel.textContent = historySelected.size ? t('Delete {count}', { count: historySelected.size }) : t('Delete');
  historyDeleteButton.disabled = historySelected.size === 0;
  if (!state) return;
  renderJobHistory(state, { jobsList, jobsError, cancellationBusy: generationCancellationRequestBusy },
    { selecting: historySelecting, selected: historySelected, hidden: pendingDeletes });
}

/** Collapse rows before their data goes away, so the list closes smoothly. */
function leaveRows(jobIds: readonly string[]): Promise<void> {
  for (const jobId of jobIds) jobsList.querySelector(`[data-job-row="${CSS.escape(jobId)}"]`)?.classList.add('leaving');
  return new Promise(resolve => setTimeout(resolve, 260));
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
  if (shortcutHelpList.dataset.locale === interfaceLocale()) return;
  shortcutHelpList.dataset.locale = interfaceLocale();
  shortcutHelpList.innerHTML = shortcutHelpHtml();
}

function showError(error: unknown): void {


  latestState = null;
  currentSettings = null;
  generationCancelFeedback = null;
  setStatusBanner(typeof error === 'string' ? error : error instanceof Error ? error.message : t("Unable to load extension state"));
  nowPlayingTitle.textContent = t("Open a YouTube video");
  nowPlayingMeta.textContent = '';
  watchUnsupported.hidden = false;
  watchSetup.hidden = true;
  progressContainer.hidden = true;
  progressSummary.hidden = true;
  backTranscriptButton.hidden = true;
  screenHead.hidden = true;
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
  renderSegments(DEFAULT_EXTENSION_SETTINGS);
  captionContrastInput.checked = DEFAULT_EXTENSION_SETTINGS.captionContrastTheme === 'high';
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
    toast.show({ message: t(message), kind: 'error' });

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
    setStatusBanner(t(message));

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
  claudeModelSelect.disabled = disabled;
  claudeApiModelSelect.disabled = disabled;
  claudeApiFastModeInput.disabled = disabled || !CLAUDE_FAST_MODELS.includes(latestState?.settings.claudeApiModel ?? '');
  codexFastModeInput.disabled = disabled || !latestState?.codexAccount?.connected
    || (!latestState.settings.codexFastMode && !latestState.codexAccount.models.find(model => model.id === latestState?.settings.codexModel)?.supportsFastMode);
  for (const button of settingSegments.flatMap(segment => [...segment.querySelectorAll('button')])) button.disabled = disabled;
  captionContrastInput.disabled = disabled;
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
