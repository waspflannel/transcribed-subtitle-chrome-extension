import { browser, type Browser } from 'wxt/browser';

import { activeTabQuery } from '../utils/active-tab';
import { PanelPortRegistry } from '../utils/panel-port-registry';

import {
  clearExtensionSession,
  getStoredExtensionSession,
  storeExtensionSession,
  updateStoredAccount,
  type StoredExtensionSession,
} from '../utils/account-session';
import { SubtitleApiClient, publicSubtitleErrorMessage, SubtitleApiError } from '../utils/api';
import { clearRememberedTracks, getRememberedTrack, rememberActiveTrack } from '../utils/active-tracks';
import type { JobResponse, LearningTokenResponse, SubtitleJobHistoryItem, TrackResponse } from '../utils/contracts';
import {
  loadingMessageForStage,
  publicSubtitleJobFailureMessage,
  stateWithBackendProgress,
} from '../utils/backend-subtitle-state';
import {
  DEFAULT_SUBTITLE_STATE,
  isBackgroundRequest,
  isRuntimeMessage,
  type BackgroundRequest,
  type ContentRequest,
  type PageSnapshot,
  type PanelState,
  type PartialSubtitleTrack,
  type SubtitleState,
} from '../utils/messages';
import { anonymousAccountState, accountStateFromSummary } from '../utils/account-state';
import {
  clearLocalExtensionState,
  getExtensionSettings,
  getOrCreateInstallId,
  updateExtensionSettings,
} from '../utils/settings';
import type { ExtensionSettings } from '../utils/settings-model';
import { trackWithLearningToken } from '../utils/track-tokens';
import { parseYoutubePage, type YoutubePageInfo } from '../utils/youtube';

const subtitleApi = new SubtitleApiClient();
const tabSubtitleStates = new Map<number, SubtitleState>();
const tabOperations = new Map<number, symbol>();
const panelPorts = new PanelPortRegistry();
let cachedPanelJobHistory: SubtitleJobHistoryItem[] = [];
let cachedPanelJobHistoryError: string | undefined;
const JOB_POLL_INTERVAL_MS = 2000;
type SupportedYoutubePageInfo = Extract<YoutubePageInfo, { supported: true }>;
type PageSnapshotResponse = { ok: true; videoDurationSeconds?: number };

export default defineBackground(() => {
  // Chrome: clicking the toolbar action opens the side panel. No-op where the API is
  // absent (e.g. Firefox, which uses its native sidebar button).
  const actionSidePanel = (browser as unknown as {
    sidePanel?: { setPanelBehavior(options: { openPanelOnActionClick: boolean }): Promise<void> };
  }).sidePanel;
  void actionSidePanel?.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});

  browser.runtime.onConnect.addListener((port) => {
    if (port.name === 'panel') {
      panelPorts.add(port);
      port.onDisconnect.addListener(() => panelPorts.remove(port));
    }
  });

  browser.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!isRuntimeMessage(message) || !isBackgroundRequest(message)) {
      return false;
    }

    handleRuntimeMessage(message, sender)
      .then((response) => sendResponse(response))
      .catch((error: unknown) => {
        sendResponse({
          ok: false,
          error: error instanceof Error ? error.message : 'Unknown extension message error',
        });
      });

    return true;
  });

  browser.tabs.onRemoved.addListener((tabId) => {
    tabOperations.delete(tabId);
    tabSubtitleStates.delete(tabId);
  });
});

async function handleRuntimeMessage(message: BackgroundRequest, sender: Browser.runtime.MessageSender): Promise<unknown> {
  switch (message.type) {
    case 'content.getState':
      return getContentState(sender);

    case 'content.updateSettings':
      return updateSettingsFromContent(message.patch, sender);

    case 'content.enrichLearningToken':
      return enrichLearningTokenFromContent(message, sender);

    case 'panel.getState':
      return getPanelState({ syncBackend: message.syncBackend ?? true, windowId: message.windowId });

    case 'panel.getActiveCue': {
      const tab = await browser.tabs.get(message.tabId);
      const page = parseYoutubePage(tab.url ?? '');
      if (!page.supported || page.videoId !== message.youtubeVideoId) return { ok: false };
      const snapshot = await browser.tabs.sendMessage(message.tabId, {
        type: 'background.getActiveCue', youtubeVideoId: message.youtubeVideoId, trackId: message.trackId,
      });
      return { ...snapshot, tabId: message.tabId };
    }

    case 'panel.updateSettings':
      return updateSettingsFromPanel(message.patch, message.windowId);

    case 'panel.generateSubtitles':
      return generateSubtitlesFromPanel(message.windowId);

    case 'panel.login':
      return loginFromPanel(message.email, message.password);

    case 'panel.logout':
      return logoutFromPanel();

    case 'panel.clearLocalState':
      return clearLocalStateFromPanel(message.windowId);

    case 'content.activeCueChanged':
      if (panelPorts.hasOpenPanel()) {
        void browser.runtime.sendMessage({
          type: 'background.activeCueChanged',
          cueId: message.cueId,
          youtubeVideoId: message.youtubeVideoId,
        }).catch(() => {});
      }
      return { ok: true };

    case 'content.focusPanelTranscript':
      void browser.runtime.sendMessage({ type: 'background.focusTranscript' }).catch(() => {});
      return { ok: true };

    case 'panel.seekToCue': {
      const tabId = await tabIdForVideo(message.youtubeVideoId, message.windowId);
      if (tabId !== null) {
        await sendTabMessage(tabId, { type: 'background.seekToCue', cueId: message.cueId, mode: message.mode });
      }
      return { ok: true };
    }

    default:
      return assertNever(message);
  }
}

async function getContentState(sender: Browser.runtime.MessageSender): Promise<{
  installId: string;
  settings: ExtensionSettings;
  subtitleState: SubtitleState;
}> {
  const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;
  const pageStatus = parseYoutubePage(sender.tab?.url ?? '');
  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();
  let subtitleState = tabId === null ? DEFAULT_SUBTITLE_STATE : await getSubtitleStateForPage(tabId, pageStatus);

  if (tabId !== null && subtitleState.type === 'no-track' && pageStatus.supported) {
    subtitleState = await recoverSubtitleStateFromBackend(tabId, pageStatus, subtitleState, installId);
  }

  return { installId, settings, subtitleState };
}

/**
 * The content pull path only sees the per-tab map and the small remembered-
 * track cache, so a track generated in an earlier session (or evicted from
 * that cache) looked missing until the user pressed generate again. Recover
 * ready and in-flight jobs from backend history the way the panel does.
 * Old failed jobs are left alone: the overlay should not surface a stale
 * failure just because the user opened the video again.
 */
async function recoverSubtitleStateFromBackend(
  tabId: number,
  pageStatus: YoutubePageInfo,
  localState: SubtitleState,
  installId: string,
): Promise<SubtitleState> {
  const session = await getStoredExtensionSession();

  if (!session) {
    return localState;
  }

  const history = await getPanelJobHistory(installId, session, true);

  if (history.sessionInvalid) {
    return localState;
  }

  const resolved = await stateWithBackendProgress(localState, pageStatus, history.jobs, (job) =>
    resolveCompletedSubtitleJob(installId, session.plainTextToken, job),
  );

  if (resolved.type === 'ready') {
    await storeReadySubtitleState(tabId, resolved);

    return resolved;
  }

  if (resolved.type === 'loading') {
    tabSubtitleStates.set(tabId, resolved);

    return resolved;
  }

  return localState;
}

async function updateSettingsFromPanel(patch: Partial<ExtensionSettings>, windowId?: number): Promise<PanelState> {
  const settings = await updateExtensionSettings(patch);
  const activeTab = await getActiveTab(windowId);
  const activeTabId = activeTab?.id ?? null;

  if (activeTabId !== null) {
    await sendTabMessage(activeTabId, {
      type: 'background.settingsChanged',
      settings,
    });
  }

  return getPanelState({ syncBackend: false });
}

async function updateSettingsFromContent(
  patch: Partial<ExtensionSettings>,
  sender: Browser.runtime.MessageSender,
): Promise<{ ok: true; settings: ExtensionSettings }> {
  const settings = await updateExtensionSettings(patch);
  const senderTabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;

  if (senderTabId !== null) {
    await sendTabMessage(senderTabId, {
      type: 'background.settingsChanged',
      settings,
    });
  }

  return { ok: true, settings };
}

async function generateSubtitlesFromPanel(windowId?: number): Promise<PanelState> {
  const activeTab = await getActiveTab(windowId);
  const activeTabId = activeTab?.id ?? null;

  if (activeTabId === null) {
    throw new Error('Open a supported YouTube video or Short before generating subtitles.');
  }

  const pageStatus = parseYoutubePage(activeTab?.url ?? '');

  if (!pageStatus.supported) {
    const subtitleState: SubtitleState = {
      type: 'error',
      message: 'Open a supported YouTube video or Short before generating subtitles.',
    };

    await publishSubtitleState(activeTabId, subtitleState);

    return getPanelState({ syncBackend: true });
  }

  const currentState = await getSubtitleStateForPage(activeTabId, pageStatus);

  if (currentState.type !== 'loading') {
    const operation = Symbol('generation');
    tabOperations.set(activeTabId, operation);
    const settings = await getExtensionSettings();
    const pageSnapshot = await getPageSnapshotFromTab(activeTabId);
    const now = new Date().toISOString();

    await publishSubtitleState(activeTabId, {
      type: 'loading',
      youtubeVideoId: pageStatus.videoId,
      youtubeUrl: pageStatus.url,
      message: 'Preparing request...',
      stage: 'preparing',
      progressPercent: 5,
      startedAt: now,
      lastUpdatedAt: now,
    });

    void generateSubtitlesForTab(activeTabId, pageStatus, settings, pageSnapshot, operation);
  }

  return getPanelState({ syncBackend: false });
}

async function generateSubtitlesForTab(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  settings: ExtensionSettings,
  pageSnapshot: PageSnapshot,
  operation: symbol,
): Promise<void> {
  try {
    const session = await getStoredExtensionSession();

    if (!session) {
      throw new SubtitleApiError('unauthenticated', 'Sign in before generating subtitles.', 401);
    }

    console.info('extension.subtitle_generation_started', {
      youtubeVideoId: pageStatus.videoId,
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      enrichmentMode: settings.fullTrackEnrichment ? 'full' : 'on_demand',
      includeRomanization: settings.showRomanization,
      includeTranslation: settings.showTranslation,
    });

    const installId = await getOrCreateInstallId();
    const initialJob = await subtitleApi.createSubtitleJob(installId, session.plainTextToken, {
      youtubeVideoId: pageStatus.videoId,
      youtubeUrl: pageStatus.url,
      ...(isCreatePayloadVideoDurationSeconds(pageSnapshot.videoDurationSeconds)
        ? { videoDurationSeconds: pageSnapshot.videoDurationSeconds }
        : {}),
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      enrichmentMode: settings.fullTrackEnrichment ? 'full' : 'on_demand',
      includeRomanization: settings.showRomanization,
      includeTranslation: settings.showTranslation,
    });
    if (tabOperations.get(tabId) !== operation) return;
    const current = tabSubtitleStates.get(tabId);
    if (current?.type !== 'loading') return;
    tabSubtitleStates.set(tabId, { ...current, jobId: initialJob.jobId });
    const job = await waitForCompletedSubtitleJob(tabId, pageStatus, installId, session.plainTextToken, initialJob, operation);

    if (job === null) {
      return;
    }

    if (job.status === 'failed') {
      console.warn('extension.subtitle_generation_failed', {
        youtubeVideoId: pageStatus.videoId,
        jobId: job.jobId,
        backendMessage: job.message,
      });

      if (tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
        await publishSubtitleState(tabId, {
          type: 'error',
          jobId: job.jobId,
          youtubeVideoId: pageStatus.videoId,
          message: publicSubtitleJobFailureMessage(job),
        });
      }

      return;
    }

    if (!job.track) {
      throw new Error('Completed subtitle job did not include a track.');
    }

    if (tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'ready',
        track: job.track,
      });
    }

    console.info('extension.subtitle_generation_completed', {
      youtubeVideoId: pageStatus.videoId,
      jobId: job.jobId,
      trackId: job.track.trackId,
    });
  } catch (error) {
    await clearSessionIfInvalid(error);

    console.warn('extension.subtitle_generation_failed', {
      youtubeVideoId: pageStatus.videoId,
      errorCode: error instanceof SubtitleApiError ? error.code : 'extension_error',
      status: error instanceof SubtitleApiError ? error.status : undefined,
    });

    if (tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'error',
        youtubeVideoId: pageStatus.videoId,
        message: publicSubtitleErrorMessage(error),
      });
    }
  }
}

// Stages at which the backend can already serve partial cues: draft cues
// exist once transcription lands, right before the tokenizing stage starts.
const PARTIAL_TRACK_STAGES = new Set<JobResponse['stage']>([
  'tokenizing',
  'romanizing',
  'translating',
  'enriching',
  'finalizing',
]);

async function waitForCompletedSubtitleJob(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  installId: string,
  authToken: string,
  initialJob: JobResponse,
  operation: symbol,
): Promise<JobResponse | null> {
  let job = initialJob;
  let partialTrack: PartialSubtitleTrack | undefined;

  while (tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
    if (job.status === 'completed' || job.status === 'failed') {
      return job;
    }

    if (PARTIAL_TRACK_STAGES.has(job.stage)) {
      partialTrack = (await fetchPartialTrack(installId, authToken, job)) ?? partialTrack;
    }

    if (tabOperations.get(tabId) !== operation || !isCurrentLoadingState(tabId, pageStatus.videoId)) return null;

    await publishSubtitleState(tabId, {
      type: 'loading',
      jobId: job.jobId,
      youtubeVideoId: pageStatus.videoId,
      youtubeUrl: pageStatus.url,
      message: loadingMessageForStage(job.stage),
      stage: job.stage,
      progressPercent: job.progressPercent,
      startedAt: job.createdAt,
      lastUpdatedAt: job.updatedAt,
      ...(partialTrack ? { partialTrack } : {}),
    });

    await delay(JOB_POLL_INTERVAL_MS);
    if (tabOperations.get(tabId) !== operation || !isCurrentLoadingState(tabId, pageStatus.videoId)) return null;
    job = await subtitleApi.getSubtitleJob(installId, authToken, job.jobId);
    if (job.jobId !== initialJob.jobId || job.youtubeVideoId !== pageStatus.videoId) {
      throw new Error('Subtitle status did not match the requested job.');
    }
  }

  return null;
}

async function fetchPartialTrack(
  installId: string,
  authToken: string,
  job: JobResponse,
): Promise<PartialSubtitleTrack | undefined> {
  try {
    const response = await subtitleApi.getSubtitleJobPartialTrack(installId, authToken, job.jobId);

    return {
      jobId: response.jobId,
      youtubeVideoId: response.youtubeVideoId,
      // The overlay needs the effective language for srclang/lang; the job
      // poll already carries it, so the partial contract does not.
      sourceLanguage: job.detectedSourceLanguage ?? job.sourceLanguage,
      revision: response.revision,
      cues: response.cues,
    };
  } catch {
    // 404 until transcription lands; any fetch error just means no partial
    // update this poll. The last fetched partial track stays bound.
    return undefined;
  }
}

function delay(milliseconds: number): Promise<void> {
  return new Promise((resolve) => {
    globalThis.setTimeout(resolve, milliseconds);
  });
}

async function enrichLearningTokenFromContent(
  message: Extract<BackgroundRequest, { type: 'content.enrichLearningToken' }>,
  sender: Browser.runtime.MessageSender,
): Promise<unknown> {
  const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;

  if (tabId === null) {
    throw new Error('Learning token enrichment requires an active content tab.');
  }

  const currentState = await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId);
  const session = await getStoredExtensionSession();

  if (!session) {
    throw new SubtitleApiError('unauthenticated', 'Sign in before enriching learning tokens.', 401);
  }

  if (!currentState) {
    throw new Error('No generated subtitle track is active for this tab.');
  }

  const installId = await getOrCreateInstallId();
  let response: LearningTokenResponse;

  try {
    response = await subtitleApi.enrichLearningToken(installId, session.plainTextToken, {
      trackId: message.trackId,
      cueId: message.cueId,
      tokenIndex: message.tokenIndex,
    });
  } catch (error) {
    await clearSessionIfInvalid(error);

    throw error;
  }

  const latestState = tabSubtitleStates.get(tabId);
  if (latestState?.type !== 'ready' || latestState.track.trackId !== message.trackId
    || latestState.track.youtubeVideoId !== message.youtubeVideoId
    || response.cueId !== message.cueId || response.token.index !== message.tokenIndex) {
    throw new Error('The active track changed before the word card arrived.');
  }
  const track = trackWithLearningToken(latestState.track, response.cueId, response.token);

  await storeReadySubtitleState(tabId, {
    type: 'ready',
    track,
  });

  return {
    ok: true,
    track,
    cueId: response.cueId,
    token: response.token,
  };
}

async function storeReadySubtitleState(tabId: number, subtitleState: Extract<SubtitleState, { type: 'ready' }>): Promise<void> {
  tabSubtitleStates.set(tabId, subtitleState);
  await rememberActiveTrack(subtitleState.track);
}

async function readySubtitleStateForEnrichment(
  tabId: number,
  youtubeVideoId: string,
  trackId: string,
): Promise<Extract<SubtitleState, { type: 'ready' }> | null> {
  const currentState = tabSubtitleStates.get(tabId);

  if (currentState?.type === 'ready' && currentState.track.trackId === trackId) {
    return currentState;
  }

  if (currentState && currentState.type !== 'no-track' && isSubtitleStateForVideo(currentState, youtubeVideoId)) {
    return null;
  }

  const rememberedTrack = await getRememberedTrack(youtubeVideoId);

  if (rememberedTrack?.trackId === trackId) {
    const restoredState: Extract<SubtitleState, { type: 'ready' }> = { type: 'ready', track: rememberedTrack };
    tabSubtitleStates.set(tabId, restoredState);

    return restoredState;
  }

  return null;
}

async function clearLocalStateFromPanel(windowId?: number): Promise<PanelState> {
  await clearLocalExtensionState();
  await clearRememberedTracks();
  tabSubtitleStates.clear();
  tabOperations.clear();

  const activeTab = await getActiveTab(windowId);
  const activeTabId = activeTab?.id ?? null;
  const settings = await getExtensionSettings();

  console.info('extension.local_state_cleared', {
    activeTabId,
  });

  if (activeTabId !== null) {
    await sendTabMessage(activeTabId, {
      type: 'background.settingsChanged',
      settings,
    });

    await publishSubtitleState(activeTabId, DEFAULT_SUBTITLE_STATE);
  }

  return getPanelState({ syncBackend: true });
}

async function loginFromPanel(email: string, password: string): Promise<PanelState> {
  const installId = await getOrCreateInstallId();
  const response = await subtitleApi.loginExtension(installId, { email, password });

  await storeExtensionSession(response);

  console.info('extension.account_login_completed');

  return getPanelState({ syncBackend: true });
}

async function logoutFromPanel(): Promise<PanelState> {
  const installId = await getOrCreateInstallId();
  const session = await getStoredExtensionSession();

  if (session) {
    try {
      await subtitleApi.logoutExtension(installId, session.plainTextToken);
    } catch (error) {
      console.warn('extension.account_logout_revoke_failed', {
        errorCode: error instanceof SubtitleApiError ? error.code : 'extension_error',
      });
    }
  }

  await clearExtensionSession();

  console.info('extension.account_logout_completed');

  return getPanelState({ syncBackend: true });
}

async function getPanelState(options: { syncBackend: boolean; windowId?: number }): Promise<PanelState> {
  const activeTab = await getActiveTab(options.windowId);
  const activeTabId = activeTab?.id ?? null;
  const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;
  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();
  let effectiveSession = await getStoredExtensionSession();
  const pageSnapshot = activeTabId === null || pageStatus?.supported !== true
    ? {}
    : await getPageSnapshotFromTab(activeTabId);

  if (effectiveSession && options.syncBackend) {
    effectiveSession = await syncExtensionAccount(installId, effectiveSession);
  }

  const history = await getPanelJobHistory(installId, effectiveSession, options.syncBackend);

  if (history.sessionInvalid) {
    effectiveSession = null;
    cachedPanelJobHistory = [];
    cachedPanelJobHistoryError = undefined;
  }

  const localState =
    activeTabId === null || pageStatus === undefined
      ? DEFAULT_SUBTITLE_STATE
      : await getSubtitleStateForPage(activeTabId, pageStatus);

  const resolvedState = await stateWithBackendProgress(localState, pageStatus, history.jobs, (job) =>
    effectiveSession ? resolveCompletedSubtitleJob(installId, effectiveSession.plainTextToken, job) : Promise.resolve(null),
  );
  const currentState = activeTabId === null ? undefined : tabSubtitleStates.get(activeTabId);
  const subtitleState = currentState && currentState !== localState ? currentState : resolvedState;

  if (activeTabId !== null && subtitleState.type === 'ready' && localState.type !== 'ready') {
    await publishSubtitleState(activeTabId, subtitleState);
  }

  return {
    installId,
    settings,
    activeTabId: activeTabId ?? undefined,
    pageStatus,
    pageTitle: activeTab?.title,
    pageVideoDurationSeconds: pageSnapshot.videoDurationSeconds,
    accountState: effectiveSession ? accountStateFromSummary(effectiveSession.account) : anonymousAccountState(),
    subtitleState,
    jobHistory: history.jobs,
    jobHistoryError: history.error,
  };
}

async function getPanelJobHistory(
  installId: string,
  session: StoredExtensionSession | null,
  syncBackend: boolean,
): Promise<{ jobs: SubtitleJobHistoryItem[]; error?: string; sessionInvalid: boolean }> {
  if (!session) {
    cachedPanelJobHistory = [];
    cachedPanelJobHistoryError = undefined;

    return { jobs: [], error: undefined, sessionInvalid: false };
  }

  if (!syncBackend) {
    return {
      jobs: cachedPanelJobHistory,
      error: cachedPanelJobHistoryError,
      sessionInvalid: false,
    };
  }

  const history = await listBackendJobHistory(installId, session.plainTextToken);

  cachedPanelJobHistory = history.jobs;
  cachedPanelJobHistoryError = history.error;

  return history;
}

async function getPageSnapshotFromTab(tabId: number): Promise<PageSnapshot> {
  try {
    const response = (await browser.tabs.sendMessage(tabId, {
      type: 'background.getPageSnapshot',
    })) as PageSnapshotResponse | undefined;

    if (response?.ok !== true || !isPositiveVideoDurationSeconds(response.videoDurationSeconds)) {
      return {};
    }

    return {
      videoDurationSeconds: response.videoDurationSeconds,
    };
  } catch {
    return {};
  }
}

async function listBackendJobHistory(
  installId: string,
  authToken: string,
): Promise<{ jobs: SubtitleJobHistoryItem[]; error?: string; sessionInvalid: boolean }> {
  try {
    const response = await subtitleApi.listSubtitleJobs(installId, authToken);

    return { jobs: response.jobs, sessionInvalid: false };
  } catch (error) {
    if (await clearSessionIfInvalid(error)) {
      return {
        jobs: [],
        error: error instanceof Error ? error.message : 'Extension session expired.',
        sessionInvalid: true,
      };
    }

    return {
      jobs: [],
      error: error instanceof Error ? error.message : 'Unable to load backend job history.',
      sessionInvalid: false,
    };
  }
}

async function resolveCompletedSubtitleJob(
  installId: string,
  authToken: string,
  historyJob: SubtitleJobHistoryItem,
): Promise<JobResponse | null> {
  try {
    const job = await subtitleApi.getSubtitleJob(installId, authToken, historyJob.jobId);

    return job.status === 'completed' && job.track ? job : null;
  } catch (error) {
    console.warn('extension.completed_subtitle_recovery_failed', {
      youtubeVideoId: historyJob.youtubeVideoId,
      jobId: historyJob.jobId,
      trackId: historyJob.trackId,
      error: error instanceof Error ? error.message : 'Unknown completed subtitle recovery error',
    });

    return null;
  }
}

async function syncExtensionAccount(
  installId: string,
  session: StoredExtensionSession,
): Promise<StoredExtensionSession | null> {
  try {
    const response = await subtitleApi.getExtensionAccount(installId, session.plainTextToken);

    return updateStoredAccount(response.account);
  } catch (error) {
    if (await clearSessionIfInvalid(error)) {
      return null;
    }

    return session;
  }
}

async function getSubtitleStateForPage(tabId: number, pageStatus: YoutubePageInfo): Promise<SubtitleState> {
  const subtitleState = tabSubtitleStates.get(tabId) ?? DEFAULT_SUBTITLE_STATE;

  if (!pageStatus.supported) {
    return DEFAULT_SUBTITLE_STATE;
  }

  if (subtitleState.type !== 'no-track' && isSubtitleStateForVideo(subtitleState, pageStatus.videoId)) {
    return subtitleState;
  }

  const rememberedTrack = await getRememberedTrack(pageStatus.videoId);

  if (!rememberedTrack) {
    return DEFAULT_SUBTITLE_STATE;
  }

  const restoredState: SubtitleState = { type: 'ready', track: rememberedTrack };
  tabSubtitleStates.set(tabId, restoredState);

  return restoredState;
}

async function tabIdForVideo(youtubeVideoId: string, windowId?: number): Promise<number | null> {
  for (const [tabId, subtitleState] of tabSubtitleStates) {
    if (subtitleState.type === 'ready' && subtitleState.track.youtubeVideoId === youtubeVideoId) {
      return tabId;
    }

    if (subtitleState.type === 'loading' && subtitleState.youtubeVideoId === youtubeVideoId) {
      return tabId;
    }
  }

  const activeTab = await getActiveTab(windowId);
  const activeTabId = activeTab?.id ?? null;
  const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;

  return activeTabId !== null && pageStatus?.supported === true && pageStatus.videoId === youtubeVideoId
    ? activeTabId
    : null;
}

function isSubtitleStateForVideo(subtitleState: SubtitleState, youtubeVideoId: string): boolean {
  switch (subtitleState.type) {
    case 'loading':
      return subtitleState.youtubeVideoId === youtubeVideoId;

    case 'ready':
      return subtitleState.track.youtubeVideoId === youtubeVideoId;

    case 'error':
      return subtitleState.youtubeVideoId === youtubeVideoId;

    case 'no-track':
      return true;
  }
}

function isCurrentLoadingState(tabId: number, youtubeVideoId: string): boolean {
  const currentState = tabSubtitleStates.get(tabId);

  return currentState?.type === 'loading' && currentState.youtubeVideoId === youtubeVideoId;
}

async function publishSubtitleState(tabId: number, subtitleState: SubtitleState): Promise<void> {
  tabSubtitleStates.set(tabId, subtitleState);

  if (subtitleState.type === 'ready') {
    await rememberActiveTrack(subtitleState.track);
  }

  await sendTabMessage(tabId, {
    type: 'background.subtitleStateChanged',
    subtitleState,
  });
}

async function getActiveTab(windowId?: number): Promise<Browser.tabs.Tab | undefined> {
  const [activeTab] = await browser.tabs.query(activeTabQuery(windowId));

  return activeTab;
}

async function sendTabMessage(tabId: number, message: ContentRequest): Promise<void> {
  try {
    await browser.tabs.sendMessage(tabId, message);
  } catch {
    // Unsupported pages do not have this content script; panel state still updates locally.
  }
}

function isPositiveVideoDurationSeconds(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

function isCreatePayloadVideoDurationSeconds(value: unknown): value is number {
  return isPositiveVideoDurationSeconds(value) && value <= 3600;
}

function isSessionInvalidError(error: unknown): boolean {
  return error instanceof SubtitleApiError
    && (error.code === 'unauthenticated' || error.code === 'expired' || error.code === 'email_not_verified');
}

async function clearSessionIfInvalid(error: unknown): Promise<boolean> {
  if (!isSessionInvalidError(error)) {
    return false;
  }

  await clearExtensionSession();

  return true;
}

function assertNever(value: never): never {
  throw new Error(`Unhandled background request: ${JSON.stringify(value)}`);
}
