import { browser, type Browser } from 'wxt/browser';

import { getInstanceContext, type InstanceContext } from '../utils/instance-context';
import { SubtitleApiClient, publicSubtitleErrorMessage, SubtitleApiError, DEFAULT_BACKEND_API_BASE_URL } from '../utils/api';
import { clearRememberedTracks, clearTabOperation, clearTabOperationIfMatches, clearTabOperations, forgetRememberedTrack, getRememberedTrack, getTabOperation, rememberActiveTrack, setTabOperation, updateTabOperationIfMatches, type StoredTabOperation } from '../utils/active-tracks';
import type { InstanceSettings, JobResponse, LearningTokenResponse, LyricsCorrectionStatus, SubtitleJobHistoryItem, TrackResponse } from '../utils/contracts';
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
import {
  clearLocalExtensionState,
  getExtensionSettings,
  getOrCreateInstallId,
  isSubtitleRecoveryBlocked,
  setSubtitleRecoveryBlocked,
  updateExtensionSettings,
  waitForExtensionSettingsWrites,
} from '../utils/settings';
import type { ExtensionSettings } from '../utils/settings-model';
import { trackWithLearningToken } from '../utils/track-tokens';
import { parseYoutubePage, type YoutubePageInfo } from '../utils/youtube';
import {
  lyricsCorrectionTabState,
  nextLyricsCorrectionSync,
  syncLyricsCorrectionStatus,
  type LyricsCorrectionTabState,
} from '../utils/lyrics-correction';

const subtitleApi = new SubtitleApiClient();
const tabSubtitleStates = new Map<number, SubtitleState>();
const tabSubtitleStateOwners = new Map<number, string>();
const tabSubtitleStateSessions = new Map<number, string>();
const tabOperations = new Map<number, symbol>();
const tabGenerationCancellationInFlight = new Map<number, { jobId: string; operation: symbol }>();
const tabLyricsCorrectionStates = new Map<number, LyricsCorrectionTabState>();
const tabLyricsCorrectionStateSessions = new Map<number, string>();
const tabGenerationInFlight = new Set<number>();
const tabCorrectionMutationInFlight = new Map<number, symbol>();
const panelPorts = new Set<Browser.runtime.Port>();
let cachedPanelJobHistory: SubtitleJobHistoryItem[] = [];
let cachedPanelJobHistoryError: string | undefined;
let cachedPanelJobHistoryInstanceId: string | undefined;
let cachedPanelJobHistorySessionId: string | undefined;
let localStateResetVersion = 0;
let instanceMutationVersion = 0;
let cachedInstanceSettings: InstanceSettings | undefined;
let instanceSettingsError: string | undefined;
let audioPrefetch: { key: string; at: number } | undefined;
const JOB_POLL_INTERVAL_MS = 5000;
const ACTIVE_JOB_POLL_INTERVAL_MS = 1000;
type SupportedYoutubePageInfo = Extract<YoutubePageInfo, { supported: true }>;
type PageSnapshotResponse = { ok: true; videoDurationSeconds?: number };

function isGenerationCancellationClaim(tabId: number, jobId: string, operation: symbol): boolean {
  const claim = tabGenerationCancellationInFlight.get(tabId);

  return claim?.jobId === jobId && claim.operation === operation;
}

export default defineBackground(() => {
  // Remove credentials left by upgrades from the retired account-based release.
  void browser.storage.local.remove('extensionSession');
  // Chrome: clicking the toolbar action opens the side panel. No-op where the API is
  // absent (e.g. Firefox, which uses its native sidebar button).
  const actionSidePanel = (browser as unknown as {
    sidePanel?: { setPanelBehavior(options: { openPanelOnActionClick: boolean }): Promise<void> };
  }).sidePanel;
  void actionSidePanel?.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});

  browser.runtime.onConnect.addListener((port) => {
    if (port.name === 'panel') {
      panelPorts.add(port);
      port.onDisconnect.addListener(() => panelPorts.delete(port));
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
          ...(error instanceof SubtitleApiError ? { errorCode: error.code, details: error.details } : {}),
        });
      });

    return true;
  });

  browser.tabs.onRemoved.addListener((tabId) => {
    tabOperations.delete(tabId);
    tabSubtitleStates.delete(tabId);
    tabSubtitleStateOwners.delete(tabId);
    tabSubtitleStateSessions.delete(tabId);
    tabLyricsCorrectionStates.delete(tabId);
    tabLyricsCorrectionStateSessions.delete(tabId);
    tabGenerationInFlight.delete(tabId);
    tabGenerationCancellationInFlight.delete(tabId);
    tabCorrectionMutationInFlight.delete(tabId);
    void clearTabOperation(tabId);
  });
});

/**
 * Clearing correction sync state is a tombstone: the per-tab revision keeps
 * increasing so an already in-flight status response can never be accepted
 * against a fresh revision counter or a different job.
 */
function tombstoneLyricsCorrectionState(tabId: number): void {
  const current = tabLyricsCorrectionStates.get(tabId);

  if (current) {
    tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(current, { type: 'cleared' }));
  }
}

function tombstoneAllLyricsCorrectionStates(): void {
  for (const tabId of [...tabLyricsCorrectionStates.keys()]) {
    tombstoneLyricsCorrectionState(tabId);
  }
}

function resetLyricsCorrectionStatesForSession(sessionId?: string): void {
  for (const tabId of [...tabLyricsCorrectionStates.keys()]) {
    if (sessionId) {
      tabLyricsCorrectionStateSessions.set(tabId, sessionId);
    } else {
      tabLyricsCorrectionStateSessions.delete(tabId);
    }
  }
  tombstoneAllLyricsCorrectionStates();
}

async function handleRuntimeMessage(message: BackgroundRequest, sender: Browser.runtime.MessageSender): Promise<unknown> {
  switch (message.type) {
    case 'content.getState':
      return getContentState(sender, message.revalidateSavedGeneration);

    case 'content.updateSettings':
      return updateSettingsFromContent(message.patch, sender);

    case 'content.enrichLearningToken':
      return enrichLearningTokenFromContent(message, sender);

    case 'panel.getState':
      return getPanelState({ syncBackend: message.syncBackend ?? true, syncLyricsCorrection: message.syncLyricsCorrection, windowId: message.windowId });

    case 'panel.getActiveCue': {
      const tab = await browser.tabs.get(message.tabId);
      const page = parseYoutubePage(tab.url ?? '');
      if (!page.supported || page.videoId !== message.youtubeVideoId
        || (typeof message.windowId === 'number' && tab.windowId !== message.windowId)) return { ok: false };
      const snapshot = await browser.tabs.sendMessage(message.tabId, {
        type: 'background.getActiveCue', youtubeVideoId: message.youtubeVideoId, trackId: message.trackId,
      });
      return { ...snapshot, tabId: message.tabId, windowId: tab.windowId };
    }

    case 'panel.updateSettings':
      return updateSettingsFromPanel(message.patch, message.windowId);

    case 'panel.listGenerations': {
      const session = await getInstanceContext();
      const tab = await getActiveTab(message.windowId);
      const page = parseYoutubePage(tab?.url ?? '');
      const state = tab?.id !== undefined && page.supported && page.videoId === message.youtubeVideoId
        ? await getSubtitleStateForPage(tab.id, page, session.instanceId, session.sessionId) : undefined;
      const installId = await getOrCreateInstallId();
      const response = await subtitleApi.listSubtitleJobs(installId, message.youtubeVideoId);
      if (!await isCurrentSession(session.sessionId)) throw new Error('Your backend changed. Refresh the panel.');
      if (tab?.id !== undefined && page.supported && state?.type === 'ready'
        && tabSubtitleStates.get(tab.id) === state
        && !tabGenerationInFlight.has(tab.id) && !tabCorrectionMutationInFlight.has(tab.id)) {
        const checked = await revalidateSavedTrack(tab.id, page, state, installId, session, response.jobs);
        if (checked !== state && tabSubtitleStates.get(tab.id) === checked) {
          const recovered = checked.type === 'no-track'
            ? await recoverSubtitleStateFromBackend(tab.id, page, checked, installId, session) : checked;
          const currentTab = await browser.tabs.get(tab.id).catch(() => undefined);
          const currentPage = parseYoutubePage(currentTab?.url ?? '');
          if (await isCurrentSession(session.sessionId) && currentPage.supported && currentPage.videoId === page.videoId
            && tabSubtitleStates.get(tab.id) === recovered) {
            await sendTabMessage(tab.id, { type: 'background.subtitleStateChanged', subtitleState: recovered });
          }
          return { ...response, panelState: await getPanelState({ syncBackend: false, windowId: message.windowId }) };
        }
      }
      return response;
    }

    case 'panel.selectGeneration':
    case 'panel.deleteGeneration':
      return changeSavedGenerationFromPanel(message);

    case 'panel.generateSubtitles':
      return generateSubtitlesFromPanel(message.youtubeVideoId, message.tabId, message.windowId);

    case 'panel.cancelSubtitleJob':
      return cancelSubtitleJobFromPanel(message, message.windowId);

    case 'panel.submitLyricsCorrection':
      return submitLyricsCorrectionFromPanel(message, message.windowId);

    case 'panel.cancelLyricsCorrection':
      return cancelLyricsCorrectionFromPanel(message, message.windowId);

    case 'panel.quickFixToken':
      return quickFixTokenFromPanel(message, message.windowId);

    case 'panel.saveInstanceSettings':
      if (sender.tab) throw new Error('Provider settings can only be changed from the extension panel.');
      cachedInstanceSettings = await subtitleApi.updateInstanceSettings(await getOrCreateInstallId(), message.patch);
      return getPanelState({ syncBackend: true, windowId: message.windowId });

    case 'panel.clearLocalState':
      return clearLocalStateFromPanel(message.windowId);

    case 'content.activeCueChanged':
      if (panelPorts.size > 0 && typeof sender.tab?.id === 'number' && typeof message.trackId === 'string') {
        void browser.runtime.sendMessage({
          type: 'background.activeCueChanged',
          cueId: message.cueId,
          youtubeVideoId: message.youtubeVideoId,
          trackId: message.trackId,
          tabId: sender.tab.id,
          ...(typeof sender.tab.windowId === 'number' ? { windowId: sender.tab.windowId } : {}),
        }).catch(() => {});
      }
      return { ok: true };

    case 'content.focusPanelTranscript':
      void browser.runtime.sendMessage({
        type: 'background.focusTranscript',
        ...(typeof sender.tab?.windowId === 'number' ? { windowId: sender.tab.windowId } : {}),
      }).catch(() => {});
      return { ok: true };

    case 'panel.seekToCue': {
      const tab = await browser.tabs.get(message.tabId).catch(() => undefined);
      const page = parseYoutubePage(tab?.url ?? '');
      if (!tab || !page.supported || page.videoId !== message.youtubeVideoId
        || (typeof message.windowId === 'number' && tab.windowId !== message.windowId)) {
        return { ok: false };
      }

      await sendTabMessage(message.tabId, {
        type: 'background.seekToCue',
        youtubeVideoId: message.youtubeVideoId,
        trackId: message.trackId,
        cueId: message.cueId,
        mode: message.mode,
      });
      return { ok: true };
    }

    default:
      return assertNever(message);
  }
}

async function getContentState(sender: Browser.runtime.MessageSender, revalidateSavedGeneration = true): Promise<{
  installId: string;
  settings: ExtensionSettings;
  subtitleState: SubtitleState;
}> {
  const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;
  const pageStatus = parseYoutubePage(sender.tab?.url ?? '');
  const installId = await getOrCreateInstallId();
  const session = await getInstanceContext();
  const instanceId = session?.instanceId;
  const settings = await getExtensionSettings();
  if (revalidateSavedGeneration && tabId !== null && tabSubtitleStates.get(tabId)?.type === 'error') {
    tabSubtitleStates.delete(tabId);
  }
  let subtitleState = tabId === null ? DEFAULT_SUBTITLE_STATE : await getSubtitleStateForPage(tabId, pageStatus, instanceId, session?.sessionId);
  const initialState = subtitleState;

  if (revalidateSavedGeneration && tabId !== null && pageStatus.supported && session && subtitleState.type === 'ready') {
    subtitleState = await revalidateSavedTrack(tabId, pageStatus, subtitleState, installId, session);
  }

  if (tabId !== null && subtitleState.type === 'no-track' && pageStatus.supported) {
    subtitleState = await recoverSubtitleStateFromBackend(tabId, pageStatus, subtitleState, installId, session);
  }

  const currentSession = await getInstanceContext();
  if (session?.sessionId !== currentSession?.sessionId) {
    subtitleState = DEFAULT_SUBTITLE_STATE;
  }

  if (revalidateSavedGeneration && tabId !== null && subtitleState !== initialState
    && tabSubtitleStates.get(tabId) === subtitleState && session?.sessionId === currentSession?.sessionId) {
    const tab = await browser.tabs.get(tabId).catch(() => undefined);
    const currentPage = parseYoutubePage(tab?.url ?? '');
    if (currentPage.supported && pageStatus.supported && currentPage.videoId === pageStatus.videoId) {
      await sendTabMessage(tabId, { type: 'background.subtitleStateChanged', subtitleState });
    }
  }

  return { installId, settings, subtitleState };
}

/** Page entry validates saved lyrics once; normal panel reads keep using local state. */
async function revalidateSavedTrack(
  tabId: number,
  page: SupportedYoutubePageInfo,
  state: Extract<SubtitleState, { type: 'ready' }>,
  installId: string,
  session: InstanceContext,
  savedJobs?: SubtitleJobHistoryItem[],
): Promise<SubtitleState> {
  const resetVersion = localStateResetVersion;
  const operation = tabOperations.get(tabId);
  const mutation = tabCorrectionMutationInFlight.get(tabId);
  const stillCurrent = async (): Promise<boolean> => {
    const tab = await browser.tabs.get(tabId).catch(() => undefined);
    const currentPage = parseYoutubePage(tab?.url ?? '');
    return await isCurrentSession(session.sessionId) && resetVersion === localStateResetVersion
      && currentPage.supported && currentPage.videoId === page.videoId
      && tabSubtitleStates.get(tabId) === state && tabOperations.get(tabId) === operation
      && tabCorrectionMutationInFlight.get(tabId) === mutation;
  };
  let validatedTrack: TrackResponse | undefined;
  try {
    if (!await stillCurrent()) return getSubtitleStateForPage(tabId, page, session.instanceId, session.sessionId);
    const savedJob = savedJobs?.find(job => job.jobId === state.track.jobId);
    // Track IDs change on corrections. An unchanged identity retains locally fetched word cards.
    if (savedJob?.trackId === state.track.trackId) return state;
    if (!savedJobs || savedJob) {
      const job = await subtitleApi.getSubtitleJob(installId, state.track.jobId);
      if (job.jobId === state.track.jobId && job.status === 'completed' && job.track
        && job.track.jobId === state.track.jobId && job.track.youtubeVideoId === page.videoId
        && (job.track.expiresAt === null || Date.parse(job.track.expiresAt) > Date.now())) validatedTrack = job.track;
    }
  } catch (error) {
    if (!(error instanceof SubtitleApiError && error.code === 'not_found')) {

      // A network failure cannot confirm saved lyrics still exist. Keep the cache for a later retry.
      if (await stillCurrent()) {
        const failed: SubtitleState = { type: 'error', youtubeVideoId: page.videoId,
          message: 'Unable to check saved generations. Refresh the page to try again.' };
        await publishSubtitleState(tabId, failed, session.instanceId, session.sessionId);
        return failed;
      }
      return getSubtitleStateForPage(tabId, page, session.instanceId, session.sessionId);
    }
  }
  if (!await stillCurrent()) return getSubtitleStateForPage(tabId, page, session.instanceId, session.sessionId);
  if (validatedTrack) {
    if (validatedTrack.trackId === state.track.trackId) return state;
    tombstoneLyricsCorrectionState(tabId);
    const refreshed: SubtitleState = { type: 'ready', track: validatedTrack };
    tabSubtitleStates.set(tabId, refreshed);
    await rememberActiveTrack(validatedTrack, session.instanceId);
    return refreshed;
  }
  await forgetRememberedTrack(page.videoId, state.track.trackId, session.instanceId);
  if (!await stillCurrent()) return getSubtitleStateForPage(tabId, page, session.instanceId, session.sessionId);
  tombstoneLyricsCorrectionState(tabId);
  tabSubtitleStates.set(tabId, DEFAULT_SUBTITLE_STATE);
  return DEFAULT_SUBTITLE_STATE;
}

/** Global history includes obsolete completed versions; the video list contains readable tracks. */
async function savedTrackRecoveryJobs(
  state: SubtitleState,
  page: SupportedYoutubePageInfo,
  jobs: SubtitleJobHistoryItem[],
  installId: string,
  session: InstanceContext,
): Promise<SubtitleJobHistoryItem[]> {
  if (state.type !== 'no-track' || jobs.some((job) => job.youtubeVideoId === page.videoId
    && (job.status === 'queued' || job.status === 'running'))) return jobs;
  try {
    const saved = await subtitleApi.listSubtitleJobs(installId, page.videoId);
    return [...saved.jobs, ...jobs.filter((job) => job.youtubeVideoId !== page.videoId || job.status !== 'completed')];
  } catch (error) {

    return jobs;
  }
}

async function recoverSubtitleStateFromBackend(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  localState: SubtitleState,
  installId: string,
  session: InstanceContext | null,
): Promise<SubtitleState> {
  const originalOperation = tabOperations.get(tabId);
  const originalState = tabSubtitleStates.get(tabId);

  if (!session) {
    return localState;
  }

  if (await isSubtitleRecoveryBlocked()) return localState;

  const history = await getPanelJobHistory(installId, session, true);

  const recoveryJobs = await savedTrackRecoveryJobs(localState, pageStatus, history.jobs, installId, session);
  const resolved = await stateWithBackendProgress(localState, pageStatus, recoveryJobs, (job) =>
    resolveCompletedSubtitleJob(installId, job, session.sessionId),
  );

  const isCurrentRecoveryOwner = async (): Promise<boolean> => {
    if (!await isCurrentSession(session.sessionId) || await isSubtitleRecoveryBlocked()) return false;

    const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
    const currentPage = parseYoutubePage(currentTab?.url ?? '');

    return currentPage.supported
      && currentPage.videoId === pageStatus.videoId
      && tabOperations.get(tabId) === originalOperation
      && tabSubtitleStates.get(tabId) === originalState;
  };

  if (!await isCurrentRecoveryOwner()) return localState;

  if (resolved.type === 'ready') {
    tabSubtitleStates.set(tabId, resolved);
    tabSubtitleStateOwners.set(tabId, session.instanceId);
    tabSubtitleStateSessions.set(tabId, session.sessionId);

    if (await isCurrentSession(session.sessionId)) {
      await rememberActiveTrack(resolved.track, session.instanceId);
    }
    if (!await isCurrentSession(session.sessionId)
      && tabSubtitleStates.get(tabId) === resolved
      && tabSubtitleStateSessions.get(tabId) === session.sessionId
      && tabOperations.get(tabId) === originalOperation) {
      tabSubtitleStates.delete(tabId);
      tabSubtitleStateOwners.delete(tabId);
      tabSubtitleStateSessions.delete(tabId);
    }

    return resolved;
  }

  if (resolved.type === 'loading') {
    let monitorRecoveredJob = false;
    if (resolved.jobId && !tabGenerationInFlight.has(tabId)) {
      const persistedOperation = await getTabOperation(tabId);
      if (!await isCurrentRecoveryOwner()) return localState;
      if (persistedOperation?.kind === 'generation' && persistedOperation.instanceId === session.instanceId
        && persistedOperation.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId === resolved.jobId) {
        monitorRecoveredJob = true;
      } else if (!persistedOperation || persistedOperation.instanceId !== session.instanceId) {
        if (persistedOperation) await clearTabOperationIfMatches(tabId, persistedOperation);
        if (!await isCurrentRecoveryOwner()) return localState;
        await setTabOperation(tabId, {
          kind: 'generation',
          instanceId: session.instanceId,
          youtubeVideoId: pageStatus.videoId,
          jobId: resolved.jobId,
        });
        if (!await isCurrentRecoveryOwner()) return localState;
        monitorRecoveredJob = true;
      }
    }

    if (!await isCurrentRecoveryOwner()) return localState;

    tabSubtitleStates.set(tabId, resolved);
    tabSubtitleStateOwners.set(tabId, session.instanceId);
    tabSubtitleStateSessions.set(tabId, session.sessionId);
    if (monitorRecoveredJob) ensureRecoveredGenerationMonitor(tabId, pageStatus, installId, session);

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

  return getPanelState({ syncBackend: false, windowId });
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

async function generateSubtitlesFromPanel(youtubeVideoId: string, tabId: number, windowId?: number): Promise<PanelState> {
  const generationResetVersion = localStateResetVersion;
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

    return getPanelState({ syncBackend: true, windowId });
  }

  if (tabGenerationInFlight.has(activeTabId) || tabCorrectionMutationInFlight.has(activeTabId)) {
    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
  }

  if (activeTabId !== tabId || pageStatus.videoId !== youtubeVideoId) throw new Error('The selected video changed. Try again.');

  const operation = Symbol('generation');
  tabOperations.set(activeTabId, operation);
  tabGenerationCancellationInFlight.delete(activeTabId);
  tabGenerationInFlight.add(activeTabId);
  let generationStarted = false;

  try {
    const session = await getInstanceContext();
    const persistedOperation = await getTabOperation(activeTabId);
    if (session?.sessionId !== (await getInstanceContext())?.sessionId) {
      return getPanelState({ syncBackend: false, windowId });
    }

    if (persistedOperation && persistedOperation.instanceId !== session?.instanceId) {
      if (persistedOperation) await clearTabOperationIfMatches(activeTabId, persistedOperation);
    } else if (persistedOperation?.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId) {

      if (persistedOperation.kind === 'correction') {
        const correction = await subtitleApi.getLyricsCorrectionStatus(
          await getOrCreateInstallId(),

          persistedOperation.jobId,
        );

        if (correction.status === 'queued' || correction.status === 'running') {
          throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
        }
      } else {
        const job = await subtitleApi.getSubtitleJob(await getOrCreateInstallId(), persistedOperation.jobId);

        if (job.status === 'queued' || job.status === 'running') {
          throw new SubtitleApiError('lyrics_correction_in_progress', 'Subtitle generation is already in progress.', 409);
        }
      }

      await clearTabOperationIfMatches(activeTabId, persistedOperation);
    } else if (persistedOperation) {
      await clearTabOperationIfMatches(activeTabId, persistedOperation);
    }

    const currentState = await getSubtitleStateForPage(activeTabId, pageStatus, session?.instanceId, session?.sessionId);
    if (currentState.type === 'ready' && session) {
      try {
        const correction = await subtitleApi.getLyricsCorrectionStatus(
          await getOrCreateInstallId(),

          currentState.track.jobId,
        );

        if (correction.status === 'queued' || correction.status === 'running') {
          throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
        }
      } catch (error) {
        if (!(error instanceof SubtitleApiError) || error.code !== 'not_found') {
          throw error;
        }
      }
    }

    if (currentState.type !== 'loading') {

      await waitForExtensionSettingsWrites();
      if (!await isCurrentSession(session.sessionId)) {
        throw new Error('The local state changed. Try again.');
      }
      const settings = await getExtensionSettings();
      const providers = (await subtitleApi.getInstanceSettings(await getOrCreateInstallId())).providers;
      const analysisReady = providers[settings.aiProvider].configured;
      if (!providers.elevenlabs.configured || !analysisReady) {
        throw new SubtitleApiError('provider_not_configured', 'Add the required provider keys in Settings before generating subtitles.', 422);
      }
      const pageSnapshot = await getPageSnapshotFromTab(activeTabId);

      const now = new Date().toISOString();

      if (!await isCurrentSession(session.sessionId)) {
        throw new Error('The local state changed. Try again.');
      }
      if (generationResetVersion !== localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
      }
      await setSubtitleRecoveryBlocked(false);
      if (generationResetVersion !== localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
      }

      await publishSubtitleState(activeTabId, {
        type: 'loading',
        status: 'running',
        youtubeVideoId: pageStatus.videoId,
        youtubeUrl: pageStatus.url,
        message: 'Preparing request...',
        stage: 'preparing',
        progressPercent: 5,
        startedAt: now,
        lastUpdatedAt: now,
      }, session.instanceId, session.sessionId);
      if (!await isCurrentSession(session.sessionId)) {
        throw new Error('The local state changed. Try again.');
      }

      await setTabOperation(activeTabId, { kind: 'generation', instanceId: session.instanceId, youtubeVideoId: pageStatus.videoId });
      generationStarted = true;
      void generateSubtitlesForTab(activeTabId, pageStatus, settings, pageSnapshot, session, operation, currentState.type === 'ready', windowId)
        .finally(() => {
          if (tabOperations.get(activeTabId) !== operation) return;
          tabGenerationInFlight.delete(activeTabId);
          // Cancellation still needs its claim to clear the displayed state.
          if (tabGenerationCancellationInFlight.get(activeTabId)?.operation !== operation) {
            tabOperations.delete(activeTabId);
          }
        });
    }

    return getPanelState({ syncBackend: false, windowId });
  } finally {
    if (!generationStarted) {
      if (tabOperations.get(activeTabId) === operation) {
        tabOperations.delete(activeTabId);
        tabGenerationInFlight.delete(activeTabId);
      }
    }
  }
}

async function generateSubtitlesForTab(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  settings: ExtensionSettings,
  pageSnapshot: PageSnapshot,
  session: InstanceContext,
  operation: symbol,
  forceRegenerate: boolean,
  windowId?: number,
): Promise<void> {
  const sessionId = session.sessionId;
  const instanceId = session.instanceId;
  let retainOperation = false;

  try {
    if (tabOperations.get(tabId) !== operation || !await isCurrentSession(sessionId)) return;

    console.info('extension.subtitle_generation_started', {
      youtubeVideoId: pageStatus.videoId,
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      aiProvider: settings.aiProvider,
      includeRomanization: settings.showRomanization,
      includeTranslation: settings.showTranslation,
    });

    const installId = await getOrCreateInstallId();
    // Keep the request tied to its video and session after asynchronous preparation.
    const currentSession = await getInstanceContext();
    const currentTab = await getActiveTab(windowId);
    const currentPage = parseYoutubePage(currentTab?.url ?? '');
    if (tabOperations.get(tabId) !== operation || currentSession?.sessionId !== sessionId) return;
    if (currentTab?.id !== tabId || !currentPage.supported
      || currentPage.videoId !== pageStatus.videoId) {
      throw new Error('The selected video changed. Open generation for the current video.');
    }
    const initialJob = await subtitleApi.createSubtitleJob(installId, {
      ...(forceRegenerate ? { forceRegenerate: true } : {}),
      youtubeVideoId: pageStatus.videoId,
      youtubeUrl: pageStatus.url,
      ...(isCreatePayloadVideoDurationSeconds(pageSnapshot.videoDurationSeconds)
        ? { videoDurationSeconds: pageSnapshot.videoDurationSeconds }
        : {}),
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      aiProvider: settings.aiProvider,
      includeRomanization: settings.showRomanization,
      includeTranslation: settings.showTranslation,
    });
    if (tabOperations.get(tabId) !== operation || isGenerationCancellationClaim(tabId, initialJob.jobId, operation)
      || !await isCurrentSession(sessionId)) return;
    const current = tabSubtitleStates.get(tabId);
    if (current?.type !== 'loading') return;
    tabSubtitleStates.set(tabId, { ...current, jobId: initialJob.jobId });
    tabSubtitleStateOwners.set(tabId, instanceId);
    tabSubtitleStateSessions.set(tabId, sessionId);
    await setTabOperation(tabId, { kind: 'generation', instanceId, youtubeVideoId: pageStatus.videoId, jobId: initialJob.jobId });
    if (tabOperations.get(tabId) !== operation || !await isCurrentSession(sessionId)) return;
    const job = await waitForCompletedSubtitleJob(tabId, pageStatus, installId, session, initialJob, operation);

    if (job === null) {
      retainOperation = true;
      return;
    }

    if (job.status === 'cancelled') {
      await clearCancelledGenerationState(tabId, job.jobId, pageStatus.videoId, instanceId, sessionId, operation);
      return;
    }

    if (isGenerationCancellationClaim(tabId, job.jobId, operation)) {
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
        }, instanceId, sessionId);
      }

      return;
    }

    if (!job.track) {
      throw new Error('Completed subtitle job did not include a track.');
    }

    if (tabOperations.get(tabId) === operation
      && !isGenerationCancellationClaim(tabId, job.jobId, operation)
      && await isCurrentSession(sessionId)) {
      if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
        await publishSubtitleState(tabId, {
          type: 'ready',
          track: job.track,
        }, instanceId, sessionId);
      } else {
        await rememberActiveTrack(job.track, instanceId);
      }
    }

    console.info('extension.subtitle_generation_completed', {
      youtubeVideoId: pageStatus.videoId,
      jobId: job.jobId,
      trackId: job.track.trackId,
    });
  } catch (error) {

    console.warn('extension.subtitle_generation_failed', {
      youtubeVideoId: pageStatus.videoId,
      errorCode: error instanceof SubtitleApiError ? error.code : 'extension_error',
      status: error instanceof SubtitleApiError ? error.status : undefined,
    });

    if (await isCurrentSession(sessionId)
      && tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'error',
        youtubeVideoId: pageStatus.videoId,
        message: publicSubtitleErrorMessage(error),
      }, instanceId, sessionId);
    }
  } finally {
    const persistedOperation = await getTabOperation(tabId);

    if (!retainOperation && await isCurrentSession(sessionId) && tabOperations.get(tabId) === operation && persistedOperation?.kind === 'generation'
      && persistedOperation.instanceId === instanceId && persistedOperation.youtubeVideoId === pageStatus.videoId) {
      await clearTabOperationIfMatches(tabId, persistedOperation);
    }
  }
}

// Chunked transcription may publish a stable draft before analysis starts.

async function waitForCompletedSubtitleJob(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  installId: string,
  session: InstanceContext,
  initialJob: JobResponse,
  operation: symbol,
): Promise<JobResponse | null> {
  let job = initialJob;
  let partialTrack = (await getTabOperation(tabId))?.partialTrack;
  let jobPollInterval = job.status === 'queued' ? JOB_POLL_INTERVAL_MS : ACTIVE_JOB_POLL_INTERVAL_MS;

  while (tabOperations.get(tabId) === operation) {
    if (isGenerationCancellationClaim(tabId, job.jobId, operation)) return null;

    if (job.status === 'completed' || job.status === 'failed') {
      return job;
    }

    if (job.status === 'cancelled') {
      return job;
    }

    const currentSession = await getInstanceContext();
    if (!currentSession || currentSession.instanceId !== session.instanceId || currentSession.sessionId !== session.sessionId) {
      return null;
    }

    if (job.partialTrack) {
      const refreshedTrack: PartialSubtitleTrack = {
        ...job.partialTrack,
        sourceLanguage: job.detectedSourceLanguage ?? job.sourceLanguage,
      };
      const revisionChanged = refreshedTrack.revision !== partialTrack?.revision;
      if (revisionChanged && tabOperations.get(tabId) === operation) {
        jobPollInterval = ACTIVE_JOB_POLL_INTERVAL_MS;
        partialTrack = refreshedTrack;
        if (!await isCurrentSession(session.sessionId)) return null;
        const persistedOperation = await getTabOperation(tabId);
        if (tabOperations.get(tabId) === operation && persistedOperation?.kind === 'generation'
          && persistedOperation.instanceId === session.instanceId
          && persistedOperation.youtubeVideoId === pageStatus.videoId
          && persistedOperation.jobId === job.jobId) {
          await updateTabOperationIfMatches(tabId, persistedOperation, { ...persistedOperation, partialTrack });
        }
      }
    }

    if (tabOperations.get(tabId) !== operation) return null;

    if (isGenerationCancellationClaim(tabId, job.jobId, operation)) return null;

    if (await isCurrentSession(session.sessionId) && isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'loading',
        status: job.status,
        jobId: job.jobId,
        youtubeVideoId: pageStatus.videoId,
        youtubeUrl: pageStatus.url,
        message: partialTrack?.readyThroughMs
          ? `Subtitles ready through ${Math.floor(partialTrack.readyThroughMs / 60000)}:${String(Math.floor(partialTrack.readyThroughMs / 1000) % 60).padStart(2, '0')}. Preparing the rest...`
          : job.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(job.stage),
        stage: job.stage,
        progressPercent: job.progressPercent,
        startedAt: job.createdAt,
        lastUpdatedAt: job.updatedAt,
        ...(partialTrack ? { partialTrack } : {}),
      }, session.instanceId, session.sessionId);
    }

    await delay(Math.max(jobPollInterval, minimumActivePollInterval()));
    if (tabOperations.get(tabId) !== operation || isGenerationCancellationClaim(tabId, job.jobId, operation)) return null;
    const refreshedSession = await getInstanceContext();
    if (!refreshedSession || refreshedSession.instanceId !== session.instanceId || refreshedSession.sessionId !== session.sessionId) return null;

    try {
      const refreshedJob = await subtitleApi.getSubtitleJob(installId, job.jobId);
      jobPollInterval = refreshedJob.status === 'queued' ? JOB_POLL_INTERVAL_MS : ACTIVE_JOB_POLL_INTERVAL_MS;
      job = refreshedJob;
    } catch (error) {
      jobPollInterval = JOB_POLL_INTERVAL_MS;

      if (error instanceof SubtitleApiError && error.code === 'not_found') {
        throw error;
      }

      if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
        await publishSubtitleState(tabId, {
          type: 'loading',
          status: job.status === 'queued' ? 'queued' : 'running',
          jobId: job.jobId,
          youtubeVideoId: pageStatus.videoId,
          youtubeUrl: pageStatus.url,
          message: 'Reconnecting to generation status...',
          stage: job.stage,
          progressPercent: job.progressPercent,
          startedAt: job.createdAt,
          lastUpdatedAt: new Date().toISOString(),
          ...(partialTrack ? { partialTrack } : {}),
        }, session.instanceId, session.sessionId);
      }
      continue;
    }
    if (job.jobId !== initialJob.jobId || job.youtubeVideoId !== pageStatus.videoId) {
      throw new Error('Subtitle status did not match the requested job.');
    }
  }

  return null;
}

function minimumActivePollInterval(): number {
  // One status request per second across active tabs leaves rate-limit headroom.
  return ACTIVE_JOB_POLL_INTERVAL_MS * Math.max(1, tabOperations.size);
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

  const session = await getInstanceContext();

  const sessionId = session.sessionId;
  const instanceId = session.instanceId;
  const currentState = await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId, instanceId, sessionId);

  if (!currentState) {
    throw new Error('No generated subtitle track is active for this tab.');
  }

  const installId = await getOrCreateInstallId();
  const response: LearningTokenResponse = await subtitleApi.enrichLearningToken(installId, {
      trackId: message.trackId,
      cueId: message.cueId,
      tokenIndex: message.tokenIndex,
    });

  if (!await isCurrentSession(sessionId)) return { ok: true, stale: true };
  await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId, instanceId, sessionId);
  if (!await isCurrentSession(sessionId)) return { ok: true, stale: true };
  const freshState = tabSubtitleStates.get(tabId);

  if (response.cueId !== message.cueId || response.token.index !== message.tokenIndex || freshState?.type !== 'ready' || freshState.track.trackId !== message.trackId
    || tabSubtitleStateOwners.get(tabId) !== instanceId || freshState.track.youtubeVideoId !== message.youtubeVideoId
    || !freshState.track.cues.some((cue) => cue.cueId === message.cueId && cue.tokens.some((token) => token.index === message.tokenIndex))) {
    return { ok: true, stale: true };
  }

  const track = trackWithLearningToken(freshState.track, response.cueId, response.token);
  // Keep the final read/merge/write synchronous. A second enrichment reply
  // must see the first reply's token instead of merging both replies into the
  // same pre-await snapshot.
  const nextState: Extract<SubtitleState, { type: 'ready' }> = { type: 'ready', track };
  tabSubtitleStates.set(tabId, nextState);
  tabSubtitleStateOwners.set(tabId, instanceId);
  tabSubtitleStateSessions.set(tabId, sessionId);
  if (await isCurrentSession(sessionId)) await rememberActiveTrack(track, instanceId);

  return {
    ok: true,
    track,
    cueId: response.cueId,
    token: response.token,
  };
}

async function submitLyricsCorrectionFromPanel(
  message: Extract<BackgroundRequest, { type: 'panel.submitLyricsCorrection' }>,
  windowId?: number,
): Promise<PanelState> {
  const activeTab = await getActiveTab(windowId);
  const tabId = activeTab?.id ?? null;
  const session = await getInstanceContext();

  if (tabId === null || !session || !activeTab) {
    throw new Error('The local state changed. Try again.');
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  const currentState = await getSubtitleStateForPage(tabId, pageStatus, session.instanceId, session.sessionId);

  if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId || currentState.type !== 'ready' || currentState.track.jobId !== message.jobId || currentState.track.trackId !== message.trackId) {
    throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
  }

  if (tabGenerationInFlight.has(tabId) || tabCorrectionMutationInFlight.has(tabId)) {
    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
  }

  const correctionClaim = Symbol('lyrics-correction');
  tabCorrectionMutationInFlight.set(tabId, correctionClaim);
  try {
    const sessionId = session.sessionId;
    const instanceId = session.instanceId;
    const installId = await getOrCreateInstallId();
    await setTabOperation(tabId, {
      kind: 'correction',
      instanceId,
      youtubeVideoId: message.youtubeVideoId,
      jobId: message.jobId,
      trackId: message.trackId,
    });
    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    let correction: LyricsCorrectionStatus;

    try {
      correction = await subtitleApi.startLyricsCorrection(
        installId,

        message.jobId,
        {
          lyrics: message.lyrics,
          expectedTrackId: message.trackId,
          ...(message.allowPartial ? { allowPartial: true } : {}),
        },
      );
    } catch (error) {
      if (await isCurrentSession(sessionId)) {
        const operation = await getTabOperation(tabId);
        if (operation?.kind === 'correction' && operation.instanceId === instanceId
          && operation.youtubeVideoId === message.youtubeVideoId && operation.jobId === message.jobId
          && operation.trackId === message.trackId) {
          await clearTabOperationIfMatches(tabId, operation);
        }
      }
      throw error;
    }
    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    await setTabOperation(tabId, {
      kind: 'correction',
      instanceId,
      youtubeVideoId: message.youtubeVideoId,
      jobId: message.jobId,
      trackId: message.trackId,
      attemptId: correction.attemptId,
    });

    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, instanceId, sessionId)) {
      if (correction.status === 'completed' && correction.track && await isCurrentSession(sessionId)) {
        await rememberActiveTrack(correction.track, instanceId);
      }

      return getPanelState({ syncBackend: true, windowId });
    }

    tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(
      tabLyricsCorrectionStates.get(tabId) ?? lyricsCorrectionTabState(),
      { type: 'submit', jobId: message.jobId, status: correction },
    ));
    tabLyricsCorrectionStateSessions.set(tabId, sessionId);

    if (correction.status === 'completed' && correction.track) {
      await publishSubtitleState(tabId, { type: 'ready', track: correction.track }, instanceId, sessionId);
      const operation = await getTabOperation(tabId);
      if (await isCurrentSession(sessionId) && operation?.kind === 'correction'
        && operation.instanceId === instanceId && operation.youtubeVideoId === message.youtubeVideoId
        && operation.jobId === message.jobId && operation.trackId === message.trackId
        && operation.attemptId === correction.attemptId) {
        await clearTabOperationIfMatches(tabId, operation);
      }
    }

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    if (tabCorrectionMutationInFlight.get(tabId) === correctionClaim) tabCorrectionMutationInFlight.delete(tabId);
  }
}

async function cancelSubtitleJobFromPanel(
  message: Extract<BackgroundRequest, { type: 'panel.cancelSubtitleJob' }>,
  windowId?: number,
): Promise<PanelState> {
  const session = await getInstanceContext();

  let capturedTab: Browser.tabs.Tab | undefined;
  let capturedOperation: Awaited<ReturnType<typeof getTabOperation>> = null;
  let cancellationOperation: symbol | undefined;

  if (typeof message.tabId === 'number') {
    capturedTab = await browser.tabs.get(message.tabId).catch(() => undefined);
    const pageStatus = parseYoutubePage(capturedTab?.url ?? '');

    if (!capturedTab || !pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId
      || (typeof windowId === 'number' && capturedTab.windowId !== windowId)) {
      throw new SubtitleApiError('not_found', 'The video for this generation is no longer open. Refresh the panel and try again.', 404);
    }

    capturedOperation = await getTabOperation(message.tabId);
    const currentState = await getSubtitleStateForPage(message.tabId, pageStatus, session.instanceId, session.sessionId);
    const ownsCurrentJob = (currentState.type === 'loading' && currentState.youtubeVideoId === message.youtubeVideoId
      && currentState.jobId === message.jobId)
      || (capturedOperation?.kind === 'generation'
        && capturedOperation.instanceId === session.instanceId
        && capturedOperation.youtubeVideoId === message.youtubeVideoId
        && capturedOperation.jobId === message.jobId);

    if (!ownsCurrentJob) {
      throw new SubtitleApiError('not_found', 'This generation is no longer active in the selected video.', 404);
    }

    cancellationOperation = tabOperations.get(message.tabId) ?? Symbol('cancelled-generation');
    tabOperations.set(message.tabId, cancellationOperation);
    tabGenerationCancellationInFlight.set(message.tabId, { jobId: message.jobId, operation: cancellationOperation });
  }

  const sessionId = session.sessionId;

  try {
    const cancelled = await subtitleApi.cancelSubtitleJob(
      await getOrCreateInstallId(),

      message.jobId,
    );

    if (cancelled.status !== 'cancelled') {
      throw new Error('Cancelled subtitle job returned a non-cancelled status.');
    }

    if (!await isCurrentSession(sessionId)) {
      if (typeof message.tabId === 'number' && cancellationOperation
        && tabGenerationCancellationInFlight.get(message.tabId)?.jobId === message.jobId
        && tabGenerationCancellationInFlight.get(message.tabId)?.operation === cancellationOperation) {
        tabGenerationCancellationInFlight.delete(message.tabId);
      }
      return getPanelState({ syncBackend: false, windowId });
    }

    if (typeof message.tabId === 'number' && capturedTab) {
      await clearCancelledGenerationState(
        message.tabId,
        message.jobId,
        message.youtubeVideoId,
        session.instanceId,
        sessionId,
        cancellationOperation!,
      );
      if (cancellationOperation && tabGenerationCancellationInFlight.get(message.tabId)?.operation === cancellationOperation) {
        tabGenerationCancellationInFlight.delete(message.tabId);
      }
    }

    return getPanelState({ syncBackend: true, windowId });
  } catch (error) {
    if (typeof message.tabId === 'number' && cancellationOperation
      && tabGenerationCancellationInFlight.get(message.tabId)?.jobId === message.jobId
      && tabGenerationCancellationInFlight.get(message.tabId)?.operation === cancellationOperation) {
      tabGenerationCancellationInFlight.delete(message.tabId);
      if (!tabGenerationInFlight.has(message.tabId) && tabOperations.get(message.tabId) === cancellationOperation) {
        tabOperations.delete(message.tabId);
      }
    }

    throw error;
  }
}

async function clearCancelledGenerationState(
  tabId: number,
  jobId: string,
  youtubeVideoId: string,
  instanceId: string,
  sessionId: string,
  operation: symbol,
): Promise<void> {
  if (!await isCurrentSession(sessionId)) return;
  let currentOperation = await getTabOperation(tabId);
  if (!await isCurrentSession(sessionId) || tabOperations.get(tabId) !== operation) return;
  const operationMatches = currentOperation === null || (currentOperation.kind === 'generation'
    && currentOperation.instanceId === instanceId
    && currentOperation.youtubeVideoId === youtubeVideoId
    && currentOperation.jobId === jobId);
  const currentState = tabSubtitleStates.get(tabId);
  const stateMatches = isCancelledGenerationState(currentState, tabId, youtubeVideoId, jobId, instanceId, sessionId);

  // A missing persisted operation is safe to recover when the in-memory state
  // still names this cancelled job. A different persisted operation belongs to
  // a newer request and must stop the cleanup.
  if (!operationMatches) return;

  if (stateMatches && currentState?.type === 'ready') {
    if (!await isCurrentSession(sessionId)) return;
    await forgetRememberedTrack(youtubeVideoId, currentState.track.trackId, instanceId);
  }

  if (!await isCurrentSession(sessionId) || tabOperations.get(tabId) !== operation) return;
  currentOperation = await getTabOperation(tabId);
  if (currentOperation && !(currentOperation.kind === 'generation'
    && currentOperation.instanceId === instanceId
    && currentOperation.youtubeVideoId === youtubeVideoId
    && currentOperation.jobId === jobId)) return;
  if (currentOperation && !await clearTabOperationIfMatches(tabId, currentOperation)) return;

  // All storage/browser awaits are complete. Only now inspect the live claim
  // and state, so a newer same-tab operation cannot be deleted by this one.
  const finalOperation = await getTabOperation(tabId);
  const finalSession = await getInstanceContext();
  const ownsClaim = tabOperations.get(tabId) === operation;
  const latestState = tabSubtitleStates.get(tabId);
  const stateStillMatches = isCancelledGenerationState(latestState, tabId, youtubeVideoId, jobId, instanceId, sessionId);
  if (finalSession?.sessionId !== sessionId || finalOperation !== null || !ownsClaim) return;

  tabOperations.delete(tabId);
  tabGenerationInFlight.delete(tabId);
  if (isGenerationCancellationClaim(tabId, jobId, operation)) {
    tabGenerationCancellationInFlight.delete(tabId);
  }

  if (stateStillMatches) {
    tabSubtitleStates.delete(tabId);
    tabSubtitleStateOwners.delete(tabId);
    tabSubtitleStateSessions.delete(tabId);
    await sendTabMessage(tabId, {
      type: 'background.subtitleStateChanged',
      subtitleState: DEFAULT_SUBTITLE_STATE,
    });
  }
}

function isCancelledGenerationState(
  state: SubtitleState | undefined,
  tabId: number,
  youtubeVideoId: string,
  jobId: string,
  instanceId: string,
  sessionId: string,
): boolean {
  const ownsState = tabSubtitleStateOwners.get(tabId) === instanceId
    && tabSubtitleStateSessions.get(tabId) === sessionId;

  return ownsState && ((state?.type === 'loading'
    && state.youtubeVideoId === youtubeVideoId
    && state.jobId === jobId)
    || (state?.type === 'ready'
      && state.track.youtubeVideoId === youtubeVideoId
      && state.track.jobId === jobId));
}

async function cancelLyricsCorrectionFromPanel(
  message: Extract<BackgroundRequest, { type: 'panel.cancelLyricsCorrection' }>,
  windowId?: number,
): Promise<PanelState> {
  const activeTab = await getActiveTab(windowId);
  const tabId = activeTab?.id ?? null;
  const session = await getInstanceContext();

  if (tabId === null || !session || !activeTab) {
    throw new Error('The local state changed. Try again.');
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId) {
    throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
  }

  if (tabGenerationInFlight.has(tabId) || tabCorrectionMutationInFlight.has(tabId)) {
    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
  }

  const correctionClaim = Symbol('lyrics-correction');
  tabCorrectionMutationInFlight.set(tabId, correctionClaim);
  try {
    const sessionId = session.sessionId;
    const instanceId = session.instanceId;
    const installId = await getOrCreateInstallId();
    const correction = await subtitleApi.cancelLyricsCorrection(installId, message.jobId, { attemptId: message.attemptId });
    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, instanceId, sessionId)) {
      if (correction.status === 'completed' && correction.track && await isCurrentSession(sessionId)) {
        await rememberActiveTrack(correction.track, instanceId);
      }

      return getPanelState({ syncBackend: true, windowId });
    }

    const currentCorrection = tabLyricsCorrectionStates.get(tabId);
    if (!currentCorrection || currentCorrection.jobId !== message.jobId || currentCorrection.status?.attemptId !== message.attemptId) {
      return getPanelState({ syncBackend: true, windowId });
    }

    tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(
      currentCorrection,
      { type: 'cancelled', jobId: message.jobId, attemptId: message.attemptId, status: correction },
    ));
    tabLyricsCorrectionStateSessions.set(tabId, sessionId);

    if (correction.status === 'completed' && correction.track) {
      await publishSubtitleState(tabId, { type: 'ready', track: correction.track }, instanceId, sessionId);
    }

    const operation = await getTabOperation(tabId);
    if (await isCurrentSession(sessionId) && operation?.kind === 'correction'
      && operation.instanceId === instanceId && operation.youtubeVideoId === message.youtubeVideoId
      && operation.jobId === message.jobId && operation.trackId === message.trackId
      && operation.attemptId === message.attemptId) {
      await clearTabOperationIfMatches(tabId, operation);
    }

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    if (tabCorrectionMutationInFlight.get(tabId) === correctionClaim) tabCorrectionMutationInFlight.delete(tabId);
  }
}

async function quickFixTokenFromPanel(
  message: Extract<BackgroundRequest, { type: 'panel.quickFixToken' }>,
  windowId?: number,
): Promise<PanelState> {
  const activeTab = await getActiveTab(windowId);
  const tabId = activeTab?.id ?? null;
  const session = await getInstanceContext();

  if (tabId === null || !session || !activeTab) {
    throw new Error('The local state changed. Try again.');
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  const currentState = await getSubtitleStateForPage(tabId, pageStatus, session.instanceId, session.sessionId);

  if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId || currentState.type !== 'ready' || currentState.track.jobId !== message.jobId || currentState.track.trackId !== message.trackId) {
    throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
  }

  if (tabGenerationInFlight.has(tabId) || tabCorrectionMutationInFlight.has(tabId)) {
    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
  }

  const correctionClaim = Symbol('lyrics-correction');
  tabCorrectionMutationInFlight.set(tabId, correctionClaim);
  try {
    const sessionId = session.sessionId;
    const instanceId = session.instanceId;
    const installId = await getOrCreateInstallId();
    let track: TrackResponse;

    try {
      track = await subtitleApi.quickFixToken(
        installId,

        message.jobId,
        message.cueId,
        message.tokenIndex,
        { expectedTrackId: message.trackId, text: message.text },
      );
    } catch (error) {
      if (error instanceof SubtitleApiError && error.code === 'lyrics_correction_in_progress' && error.details?.reason === 'stale_track') {
        if (await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, instanceId, sessionId)) {
          tabSubtitleStates.delete(tabId);
          tabSubtitleStateOwners.delete(tabId);
          tabSubtitleStateSessions.delete(tabId);
          await forgetRememberedTrack(message.youtubeVideoId, message.trackId, instanceId);
          await recoverExactJobAfterStaleTrack(tabId, message, installId, sessionId, instanceId);
        }
      }

      throw error;
    }

    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, instanceId, sessionId)) {
      if (await isCurrentSession(sessionId)) await rememberActiveTrack(track, instanceId);

      return getPanelState({ syncBackend: true, windowId });
    }

    await publishSubtitleState(tabId, { type: 'ready', track }, instanceId, sessionId);

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    if (tabCorrectionMutationInFlight.get(tabId) === correctionClaim) tabCorrectionMutationInFlight.delete(tabId);
  }
}

async function recoverExactJobAfterStaleTrack(
  tabId: number,
  message: Extract<BackgroundRequest, { type: 'panel.quickFixToken' }>,
  installId: string,
    sessionId: string,
  instanceId: string,
): Promise<void> {
  try {
    const session = await getInstanceContext();
    if (!session || session.sessionId !== sessionId || session.instanceId !== instanceId) return;
    const job = await subtitleApi.getSubtitleJob(installId, message.jobId);

    if (job.status !== 'completed' || !job.track) return;

    if (!await isCurrentSession(session.sessionId)) return;

    await rememberActiveTrack(job.track, instanceId);
    const tab = await browser.tabs.get(tabId).catch(() => undefined);
    const pageStatus = tab ? parseYoutubePage(tab.url ?? '') : undefined;

    if (pageStatus?.supported && pageStatus.videoId === message.youtubeVideoId) {
      await publishSubtitleState(tabId, { type: 'ready', track: job.track }, instanceId, sessionId);
    }
  } catch {
    // The original stale-track error remains the user-visible result.
  }
}

async function changeSavedGenerationFromPanel(
  message: Extract<BackgroundRequest, { type: 'panel.selectGeneration' | 'panel.deleteGeneration' }>,
): Promise<PanelState> {
  const tab = await getActiveTab(message.windowId);
  const session = await getInstanceContext();
  if (!session || tab?.id !== message.tabId) throw new Error('The active tab or backend changed. Refresh the panel.');
  const tabId = message.tabId;
  if (tabGenerationInFlight.has(tabId) || tabCorrectionMutationInFlight.has(tabId)) {
    throw new Error('Wait for the current subtitle operation to finish.');
  }
  const claim = Symbol('change-saved-generation');
  const resetVersion = localStateResetVersion;
  tabCorrectionMutationInFlight.set(tabId, claim);
  const stillCurrent = async (): Promise<boolean> => {
    const activeTab = await getActiveTab(message.windowId);
    const matches = activeTab?.id === tabId
      && await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.currentJobId, message.trackId, session.instanceId, session.sessionId);
    return matches && resetVersion === localStateResetVersion && tabCorrectionMutationInFlight.get(tabId) === claim;
  };
  try {
    if (!await stillCurrent()) throw new Error('The active transcript changed. Refresh the panel.');
    const installId = await getOrCreateInstallId();
    try {
      const correction = await subtitleApi.getLyricsCorrectionStatus(installId, message.currentJobId);
      if (correction.status === 'queued' || correction.status === 'running') {
        throw new Error('Wait for the lyrics replacement to finish.');
      }
    } catch (error) {
      if (!(error instanceof SubtitleApiError && error.code === 'not_found')) throw error;
    }
    if (message.type === 'panel.deleteGeneration') {
      if (message.jobId !== message.currentJobId || !await stillCurrent()) {
        throw new Error('The active transcript changed. Refresh the panel.');
      }
      try {
        await subtitleApi.deleteSavedGeneration(installId, message.jobId);
      } catch (error) {
        if (!(error instanceof SubtitleApiError && error.code === 'not_found')) throw error;
      }
      await forgetRememberedTrack(message.youtubeVideoId, message.trackId, session.instanceId);
      cachedPanelJobHistory = cachedPanelJobHistory.filter(job => job.jobId !== message.jobId);
      if (await stillCurrent()) {
        tombstoneLyricsCorrectionState(tabId);
        await publishSubtitleState(tabId, DEFAULT_SUBTITLE_STATE, session.instanceId, session.sessionId);
        const page = parseYoutubePage(tab.url ?? '');
        if (page.supported) {
          const recovered = await recoverSubtitleStateFromBackend(tabId, page, DEFAULT_SUBTITLE_STATE, installId, session);
          if (await isCurrentSession(session.sessionId) && resetVersion === localStateResetVersion
            && recovered.type === 'ready' && tabCorrectionMutationInFlight.get(tabId) === claim
            && await activeReadyTrackMatches(tabId, page.videoId, recovered.track.jobId, recovered.track.trackId, session.instanceId, session.sessionId)) {
            await sendTabMessage(tabId, { type: 'background.subtitleStateChanged', subtitleState: recovered });
          }
        }
      }
      return getPanelState({ syncBackend: false, windowId: message.windowId });
    }
    const job = await subtitleApi.getSubtitleJob(installId, message.jobId);
    if (job.jobId !== message.jobId || job.status !== 'completed' || !job.track
      || job.track.youtubeVideoId !== message.youtubeVideoId || job.track.jobId !== message.jobId
      || (job.track.expiresAt !== null && Date.parse(job.track.expiresAt) <= Date.now())) {
      throw new Error('This saved generation is no longer available.');
    }
    if (!await stillCurrent()) throw new Error('The active transcript changed. Refresh the panel.');
    const state: SubtitleState = { type: 'ready', track: job.track };
    tombstoneLyricsCorrectionState(tabId);
    tabSubtitleStates.set(tabId, state);
    tabSubtitleStateOwners.set(tabId, session.instanceId);
    tabSubtitleStateSessions.set(tabId, session.sessionId);
    await rememberActiveTrack(job.track, session.instanceId);
    if (await activeReadyTrackMatches(tabId, message.youtubeVideoId, job.jobId, job.track.trackId, session.instanceId, session.sessionId)
      && resetVersion === localStateResetVersion && tabCorrectionMutationInFlight.get(tabId) === claim) {
      await sendTabMessage(tabId, { type: 'background.subtitleStateChanged', subtitleState: state });
    }
  } finally {
    if (tabCorrectionMutationInFlight.get(tabId) === claim) tabCorrectionMutationInFlight.delete(tabId);
  }
  return getPanelState({ syncBackend: false, windowId: message.windowId });
}

async function activeReadyTrackMatches(
  tabId: number,
  youtubeVideoId: string,
  jobId: string,
  trackId: string,
  instanceId: string,
  expectedSessionId?: string,
): Promise<boolean> {
  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return false;
  const activeTab = await browser.tabs.get(tabId).catch(() => undefined);

  if (activeTab === undefined) {
    return false;
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  if (!pageStatus.supported || pageStatus.videoId !== youtubeVideoId) {
    return false;
  }

  const currentState = await getSubtitleStateForPage(tabId, pageStatus, instanceId, expectedSessionId);

  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return false;

  return currentState.type === 'ready'
    && currentState.track.jobId === jobId
    && currentState.track.trackId === trackId;
}

async function readySubtitleStateForEnrichment(
  tabId: number,
  youtubeVideoId: string,
  trackId: string,
  instanceId: string,
  expectedSessionId?: string,
): Promise<Extract<SubtitleState, { type: 'ready' }> | null> {
  const currentState = tabSubtitleStates.get(tabId);
  const ownsState = tabSubtitleStateOwners.get(tabId) === instanceId
    && (expectedSessionId === undefined || tabSubtitleStateSessions.get(tabId) === expectedSessionId);

  if (ownsState && currentState?.type === 'ready' && currentState.track.trackId === trackId) {
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return null;
    const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
    const currentPage = parseYoutubePage(currentTab?.url ?? '');
    if (!currentPage.supported || currentPage.videoId !== youtubeVideoId) return null;
    return currentState;
  }

  if (ownsState && currentState && currentState.type !== 'no-track' && isSubtitleStateForVideo(currentState, youtubeVideoId)) {
    return null;
  }

  const rememberedTrack = await getRememberedTrack(youtubeVideoId, instanceId);

  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return null;
  const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
  const currentPage = parseYoutubePage(currentTab?.url ?? '');
  if (!currentPage.supported || currentPage.videoId !== youtubeVideoId) return null;
  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return null;

  if (rememberedTrack?.trackId === trackId) {
    const restoredState: Extract<SubtitleState, { type: 'ready' }> = { type: 'ready', track: rememberedTrack };
    tabSubtitleStates.set(tabId, restoredState);
    tabSubtitleStateOwners.set(tabId, instanceId);
    if (expectedSessionId) tabSubtitleStateSessions.set(tabId, expectedSessionId);

    return restoredState;
  }

  return null;
}

async function clearLocalStateFromPanel(windowId?: number): Promise<PanelState> {
  const resetVersion = ++localStateResetVersion;
  const mutationVersion = ++instanceMutationVersion;
  await setSubtitleRecoveryBlocked(true);
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await waitForExtensionSettingsWrites();
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await clearLocalExtensionState();
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  const session = await getInstanceContext();

  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await clearRememberedTracks();
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await clearTabOperations();
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  const resetTabIds = new Set(tabSubtitleStates.keys());
  tabSubtitleStates.clear();
  tabSubtitleStateOwners.clear();
  tabSubtitleStateSessions.clear();
  tabGenerationCancellationInFlight.clear();
  tabOperations.clear();
  tabGenerationInFlight.clear();
  tabCorrectionMutationInFlight.clear();
  resetLyricsCorrectionStatesForSession();

  const activeTab = await getActiveTab(windowId);
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  const activeTabId = activeTab?.id ?? null;
  if (activeTabId !== null) resetTabIds.add(activeTabId);
  const settings = await getExtensionSettings();
  if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }

  console.info('extension.local_state_cleared', {
    activeTabId,
  });

  for (const tabId of resetTabIds) {
    await sendTabMessage(tabId, {
      type: 'background.settingsChanged',
      settings,
    });
    if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
      return getPanelState({ syncBackend: false, windowId });
    }

    await sendTabMessage(tabId, {
      type: 'background.subtitleStateChanged',
      subtitleState: DEFAULT_SUBTITLE_STATE,
    });
    if (resetVersion !== localStateResetVersion || mutationVersion !== instanceMutationVersion) {
      return getPanelState({ syncBackend: false, windowId });
    }
  }

  return getPanelState({ syncBackend: true, windowId });
}

async function getPanelState(options: { syncBackend: boolean; syncLyricsCorrection?: boolean; windowId?: number; retryOnSessionChange?: boolean }): Promise<PanelState> {
  const activeTab = await getActiveTab(options.windowId);
  const activeTabId = activeTab?.id ?? null;
  const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;
  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();
  let effectiveSession = await getInstanceContext();
  const startingSessionId = effectiveSession?.sessionId;
  const pageSnapshot = activeTabId === null || pageStatus?.supported !== true
    ? {}
    : await getPageSnapshotFromTab(activeTabId);

  if (options.syncBackend || !cachedInstanceSettings) {
    try {
      cachedInstanceSettings = await subtitleApi.getInstanceSettings(installId);
      instanceSettingsError = undefined;
    } catch {
      instanceSettingsError = 'Unable to load provider settings. Check that your backend is running.';
    }
  }
  const history = await getPanelJobHistory(installId, effectiveSession, options.syncBackend);
  let backendRecoveryBlocked = await isSubtitleRecoveryBlocked();

  const localState =
    activeTabId === null || pageStatus === undefined
      ? DEFAULT_SUBTITLE_STATE
       : await getSubtitleStateForPage(activeTabId, pageStatus, effectiveSession?.instanceId, effectiveSession?.sessionId);

  let stateForRecovery = localState;
  if (activeTabId !== null && pageStatus?.supported && effectiveSession) {
    let operation = await getTabOperation(activeTabId);
    if (!await isCurrentSession(effectiveSession.sessionId)) {
      return getPanelState({ ...options, retryOnSessionChange: false });
    }

    if (operation && operation.instanceId !== effectiveSession.instanceId) {
      const cleared = await clearTabOperationIfMatches(activeTabId, operation);
      if (cleared) operation = null;
      if (tabSubtitleStateOwners.get(activeTabId) !== effectiveSession.instanceId) {
        tabSubtitleStates.delete(activeTabId);
        tabSubtitleStateOwners.delete(activeTabId);
        tabSubtitleStateSessions.delete(activeTabId);
      }
    } else if (operation?.kind === 'generation' && operation.youtubeVideoId === pageStatus.videoId
      && !tabGenerationInFlight.has(activeTabId)) {
      const recoveryOperation = operation;
      const recoveryClaim = tabOperations.get(activeTabId) ?? Symbol('panel-recovery');
      tabOperations.set(activeTabId, recoveryClaim);
      let recoveryJob: JobResponse | undefined;
      let terminalStatusMissing = false;
      let unknownSubmission = false;
      let recoveryClaimValid = true;
      if (operation.jobId) {
        try {
          recoveryJob = await subtitleApi.getSubtitleJob(installId, operation.jobId);
          recoveryClaimValid = await isCurrentGenerationRecovery(
            activeTabId,
            pageStatus,
            effectiveSession.sessionId,
            recoveryClaim,
            recoveryOperation,
          );
        } catch (error) {

          if (error instanceof SubtitleApiError && error.code === 'not_found') {
            terminalStatusMissing = true;
            recoveryJob = undefined;
            recoveryClaimValid = await isCurrentGenerationRecovery(
              activeTabId,
              pageStatus,
              effectiveSession.sessionId,
              recoveryClaim,
              recoveryOperation,
            );
            if (recoveryClaimValid) {
              recoveryClaimValid = await clearTabOperationIfMatches(activeTabId, recoveryOperation);
              if (recoveryClaimValid) {
                recoveryClaimValid = await isCurrentGenerationRecovery(
                  activeTabId,
                  pageStatus,
                  effectiveSession.sessionId,
                  recoveryClaim,
                );
              }
            }
          } else {
            recoveryClaimValid = await isCurrentGenerationRecovery(
              activeTabId,
              pageStatus,
              effectiveSession.sessionId,
              recoveryClaim,
              recoveryOperation,
            );
          }
        }
      } else {
        const historyJob = history.jobs.find((job) => job.youtubeVideoId === pageStatus.videoId
          && (job.status === 'queued' || job.status === 'running'));
        recoveryJob = historyJob
          ? { ...historyJob, createdAt: historyJob.startedAt, updatedAt: historyJob.lastUpdatedAt }
          : undefined;
        if (recoveryJob && await isCurrentGenerationRecovery(
          activeTabId,
          pageStatus,
          effectiveSession.sessionId,
          recoveryClaim,
          recoveryOperation,
        )) {
          const nextOperation = { ...operation, jobId: recoveryJob.jobId };
          recoveryClaimValid = await updateTabOperationIfMatches(activeTabId, operation, nextOperation);
          if (recoveryClaimValid) operation = nextOperation;
        } else if (!recoveryJob && !history.error && localState.type !== 'ready'
          && !tabGenerationInFlight.has(activeTabId)) {
          unknownSubmission = true;
          recoveryClaimValid = await isCurrentGenerationRecovery(
            activeTabId,
            pageStatus,
            effectiveSession.sessionId,
            recoveryClaim,
            recoveryOperation,
          );
          if (recoveryClaimValid) {
            recoveryClaimValid = await clearTabOperationIfMatches(activeTabId, recoveryOperation);
            if (recoveryClaimValid) {
              recoveryClaimValid = await isCurrentGenerationRecovery(
                activeTabId,
                pageStatus,
                effectiveSession.sessionId,
                recoveryClaim,
              );
            }
          }
        }
      }

      if (!recoveryJob && (terminalStatusMissing || unknownSubmission) && recoveryClaimValid) {
        stateForRecovery = {
          type: 'error',
          ...(operation.jobId ? { jobId: operation.jobId } : {}),
          youtubeVideoId: pageStatus.videoId,
          message: terminalStatusMissing
            ? 'This generation is no longer available. Start a new generation if needed.'
            : 'Generation status is unavailable. Try Generate again.',
        };
        tabSubtitleStates.set(activeTabId, stateForRecovery);
        tabSubtitleStateOwners.set(activeTabId, effectiveSession.instanceId);
        tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
        tabOperations.delete(activeTabId);
        tabGenerationInFlight.delete(activeTabId);
      } else if (!recoveryJob && localState.type !== 'ready') {
        stateForRecovery = {
          type: 'loading',
          status: localState.type === 'loading' ? localState.status : 'running',
          ...(operation.jobId ? { jobId: operation.jobId } : {}),
          youtubeVideoId: pageStatus.videoId,
          youtubeUrl: pageStatus.url,
          message: localState.type === 'loading' ? localState.message : 'Preparing request...',
          stage: localState.type === 'loading' ? localState.stage : 'preparing',
          progressPercent: localState.type === 'loading' ? localState.progressPercent : 5,
          ...(localState.type === 'loading' && localState.startedAt ? { startedAt: localState.startedAt } : {}),
          ...(localState.type === 'loading' && localState.lastUpdatedAt ? { lastUpdatedAt: localState.lastUpdatedAt } : {}),
          ...(operation.partialTrack ? { partialTrack: operation.partialTrack } : {}),
        };
        if (operation.jobId && !terminalStatusMissing && recoveryClaimValid
          && await isCurrentGenerationRecovery(
            activeTabId,
            pageStatus,
            effectiveSession.sessionId,
            recoveryClaim,
            operation,
          )) {
          ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession);
        }
      } else if (!recoveryJob) {
        // A ready state belongs to the current tab but has no matching pending
        // operation; preserve it rather than clearing a newer track.
        stateForRecovery = localState;
      } else if (recoveryJob.status === 'queued' || recoveryJob.status === 'running') {
        stateForRecovery = {
          type: 'loading',
          status: recoveryJob.status,
          jobId: recoveryJob.jobId,
          youtubeVideoId: pageStatus.videoId,
          youtubeUrl: pageStatus.url,
          message: recoveryJob.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(recoveryJob.stage),
          stage: recoveryJob.stage,
          progressPercent: recoveryJob.progressPercent,
          startedAt: recoveryJob.createdAt,
          lastUpdatedAt: recoveryJob.updatedAt,
          ...(operation.partialTrack ? { partialTrack: operation.partialTrack } : {}),
        };
        if (recoveryClaimValid && await isCurrentGenerationRecovery(
          activeTabId,
          pageStatus,
          effectiveSession.sessionId,
          recoveryClaim,
          operation,
        )) {
          tabSubtitleStates.set(activeTabId, stateForRecovery);
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.instanceId);
          tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
          ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession, recoveryJob);
        }
      } else if (recoveryJob.status === 'completed' && recoveryJob.track) {
        stateForRecovery = { type: 'ready', track: recoveryJob.track };
        if (recoveryClaimValid && await isCurrentGenerationRecovery(
          activeTabId,
          pageStatus,
          effectiveSession.sessionId,
          recoveryClaim,
          operation,
        )) {
          tabSubtitleStates.set(activeTabId, stateForRecovery);
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.instanceId);
          tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
          if (await isCurrentSession(effectiveSession.sessionId)) {
            await rememberActiveTrack(recoveryJob.track, effectiveSession.instanceId);
          }
          if (await isCurrentGenerationRecovery(
            activeTabId,
            pageStatus,
            effectiveSession.sessionId,
            recoveryClaim,
            operation,
          )) {
            await clearTabOperationIfMatches(activeTabId, operation);
          }
        }
      } else if (recoveryJob.status === 'cancelled') {
        stateForRecovery = DEFAULT_SUBTITLE_STATE;
        if (recoveryClaimValid && await isCurrentGenerationRecovery(
          activeTabId,
          pageStatus,
          effectiveSession.sessionId,
          recoveryClaim,
          operation,
        )) {
          const cleared = await clearTabOperationIfMatches(activeTabId, operation);
          if (cleared && await isCurrentGenerationRecovery(
            activeTabId,
            pageStatus,
            effectiveSession.sessionId,
            recoveryClaim,
          )) {
            tabSubtitleStates.delete(activeTabId);
            tabSubtitleStateOwners.delete(activeTabId);
            tabSubtitleStateSessions.delete(activeTabId);
            tabOperations.delete(activeTabId);
            tabGenerationInFlight.delete(activeTabId);
          }
        }
      } else {
        stateForRecovery = {
          type: 'error',
          jobId: recoveryJob.jobId,
          youtubeVideoId: pageStatus.videoId,
          message: publicSubtitleJobFailureMessage(recoveryJob),
        };
        if (recoveryClaimValid && await isCurrentGenerationRecovery(
          activeTabId,
          pageStatus,
          effectiveSession.sessionId,
          recoveryClaim,
          operation,
        )) {
          const cleared = await clearTabOperationIfMatches(activeTabId, operation);
          if (cleared && await isCurrentGenerationRecovery(
            activeTabId,
            pageStatus,
            effectiveSession.sessionId,
            recoveryClaim,
          )) {
            tabSubtitleStates.set(activeTabId, stateForRecovery);
            tabSubtitleStateOwners.set(activeTabId, effectiveSession.instanceId);
            tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
            tabOperations.delete(activeTabId);
            tabGenerationInFlight.delete(activeTabId);
          }
        }
      }
    }

    if (!backendRecoveryBlocked && !operation && localState.type !== 'ready' && localState.type !== 'error') {
      const historyJob = history.jobs.find((job) => job.youtubeVideoId === pageStatus.videoId
        && (job.status === 'queued' || job.status === 'running'));
      if (historyJob && await isCurrentSession(effectiveSession.sessionId)) {
        const recoveredOperation = {
          kind: 'generation' as const,
          instanceId: effectiveSession.instanceId,
          youtubeVideoId: pageStatus.videoId,
          jobId: historyJob.jobId,
        };
        const recoveredClaim = tabOperations.get(activeTabId) ?? Symbol('panel-history-recovery');
        tabOperations.set(activeTabId, recoveredClaim);
        await setTabOperation(activeTabId, recoveredOperation);
        if (await isCurrentGenerationRecovery(
          activeTabId,
          pageStatus,
          effectiveSession.sessionId,
          recoveredClaim,
          recoveredOperation,
        ) && !tabGenerationInFlight.has(activeTabId)) {
          tabSubtitleStates.set(activeTabId, {
            type: 'loading',
            status: historyJob.status === 'queued' ? 'queued' : 'running',
            jobId: historyJob.jobId,
            youtubeVideoId: pageStatus.videoId,
            youtubeUrl: pageStatus.url,
            message: historyJob.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(historyJob.stage),
            stage: historyJob.stage,
            progressPercent: historyJob.progressPercent,
            startedAt: historyJob.startedAt,
            lastUpdatedAt: historyJob.lastUpdatedAt,
          });
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.instanceId);
          tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
          ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession, {
            ...historyJob,
            createdAt: historyJob.startedAt,
            updatedAt: historyJob.lastUpdatedAt,
          });
        }
      }
    }
  }

  backendRecoveryBlocked = await isSubtitleRecoveryBlocked();
  const recoveryJobs = !backendRecoveryBlocked && options.syncBackend && effectiveSession && pageStatus?.supported
    ? await savedTrackRecoveryJobs(stateForRecovery, pageStatus, history.jobs, installId, effectiveSession)
    : history.jobs;
  let subtitleState = backendRecoveryBlocked || !options.syncBackend || (activeTabId !== null && tabGenerationInFlight.has(activeTabId))
    ? stateForRecovery : await stateWithBackendProgress(stateForRecovery, pageStatus, recoveryJobs, (job) =>
    effectiveSession ? resolveCompletedSubtitleJob(installId, job, effectiveSession.sessionId) : Promise.resolve(null),
  );
  const lyricsCorrection = await syncLyricsCorrection(activeTabId, pageStatus, effectiveSession, installId, options.syncBackend || options.syncLyricsCorrection === true, stateForRecovery);

  if (activeTabId !== null && pageStatus?.supported) {
    const currentSubtitleState = effectiveSession?.instanceId === undefined
      ? undefined
      : tabSubtitleStateOwners.get(activeTabId) === effectiveSession.instanceId
        && tabSubtitleStateSessions.get(activeTabId) === effectiveSession.sessionId
        ? tabSubtitleStates.get(activeTabId)
        : undefined;

    if (currentSubtitleState && currentSubtitleState !== localState && isSubtitleStateForVideo(currentSubtitleState, pageStatus.videoId)) {
      subtitleState = currentSubtitleState;
    }
  }

  if (activeTabId !== null && pageStatus && subtitleState.type === 'ready' && localState.type !== 'ready'
    && (!effectiveSession || await isCurrentSession(effectiveSession.sessionId))) {
    if (await isSubtitleRecoveryBlocked()) {
      subtitleState = await getSubtitleStateForPage(activeTabId, pageStatus, effectiveSession?.instanceId, effectiveSession?.sessionId);
    } else {
      await publishSubtitleState(activeTabId, subtitleState, effectiveSession?.instanceId, effectiveSession?.sessionId);
    }
  }

  const currentSession = await getInstanceContext();
  const latestActiveTab = await getActiveTab(options.windowId);
  const latestPageStatus = latestActiveTab ? parseYoutubePage(latestActiveTab.url ?? '') : undefined;
  const pageChanged = latestActiveTab?.id !== activeTabId
    || (pageStatus?.supported === true && (!latestPageStatus?.supported || latestPageStatus.videoId !== pageStatus.videoId));
  if (options.retryOnSessionChange !== false && (startingSessionId !== currentSession?.sessionId || pageChanged)) {
    return getPanelState({ ...options, retryOnSessionChange: false });
  }
  if (startingSessionId !== currentSession?.sessionId || pageChanged) {
    // The bounded retry also raced a session or navigation change. Return a
    // redacted current snapshot instead of pairing old history/state with it.
    return {
      installId,
      settings,
      activeTabId: latestActiveTab?.id,
      pageStatus: latestPageStatus,
      pageTitle: latestActiveTab?.title,
      backendUrl: DEFAULT_BACKEND_API_BASE_URL,
      instanceSettings: cachedInstanceSettings,
      instanceSettingsError,
      subtitleState: DEFAULT_SUBTITLE_STATE,
      jobHistory: [],
      lyricsCorrection: null,
    };
  }

  if (import.meta.env.WXT_AUDIO_METADATA_PREFETCH === 'true' && panelPorts.size > 0
    && currentSession && pageStatus?.supported && subtitleState.type !== 'loading'
    && subtitleState.type !== 'ready') {
    const key = `${currentSession.sessionId}:${pageStatus.videoId}`;
    if (audioPrefetch?.key !== key || Date.now() - audioPrefetch.at > 45000) {
      audioPrefetch = { key, at: Date.now() };
      void subtitleApi.prefetchSubtitleAudio(installId, pageStatus.videoId).catch(() => {});
    }
  }

  return {
    installId,
    settings,
    activeTabId: activeTabId ?? undefined,
    pageStatus,
    pageTitle: activeTab?.title,
    pageVideoDurationSeconds: pageSnapshot.videoDurationSeconds,
      backendUrl: DEFAULT_BACKEND_API_BASE_URL,
      instanceSettings: cachedInstanceSettings,
      instanceSettingsError,
    subtitleState,
    jobHistory: history.jobs,
    jobHistoryError: history.error,
    lyricsCorrection,
    lyricsCorrectionSyncError: activeTabId !== null ? tabLyricsCorrectionStates.get(activeTabId)?.syncError : undefined,
  };
}

async function syncLyricsCorrection(
  tabId: number | null,
  pageStatus: YoutubePageInfo | undefined,
  session: InstanceContext | null,
  installId: string,
  syncBackend: boolean,
  subtitleState: SubtitleState,
): Promise<LyricsCorrectionStatus | null> {
  if (tabId === null || !pageStatus?.supported || !session) {
    if (syncBackend && tabId !== null) {
      tabLyricsCorrectionStateSessions.delete(tabId);
      tombstoneLyricsCorrectionState(tabId);
    }

    return null;
  }

  if (tabLyricsCorrectionStateSessions.get(tabId) !== session.sessionId) {
    tabLyricsCorrectionStates.set(tabId, lyricsCorrectionTabState());
  }
  tabLyricsCorrectionStateSessions.set(tabId, session.sessionId);
  const persistedOperation = await getTabOperation(tabId);
  if (persistedOperation && persistedOperation.instanceId !== session.instanceId) {
    if (await isCurrentSession(session.sessionId)) await clearTabOperationIfMatches(tabId, persistedOperation);
  }
  const trackedJobId = subtitleState.type === 'ready'
    ? subtitleState.track.jobId
    : tabLyricsCorrectionStates.get(tabId)?.jobId ?? (persistedOperation?.kind === 'correction' ? persistedOperation.jobId : undefined);

  if (!trackedJobId) {
    if (syncBackend) {
      tombstoneLyricsCorrectionState(tabId);
    }

    return null;
  }

  try {
    const sessionId = session.sessionId;
    let completedResult: LyricsCorrectionStatus | undefined;
    const status = await syncLyricsCorrectionStatus({
      tabId,
      jobId: trackedJobId,
      syncBackend,
      states: tabLyricsCorrectionStates,
      onCompleted: (result) => { completedResult = result; },
      fetchStatus: () => subtitleApi.getLyricsCorrectionStatus(installId, trackedJobId),
      canCommit: () => tabLyricsCorrectionStateSessions.get(tabId) === sessionId,
      onCurrentRequestError: (error) => {
        if (error instanceof SubtitleApiError && error.code === 'not_found') {
          void isCurrentSession(sessionId).then((current) => {
            if (current) tombstoneLyricsCorrectionState(tabId);
          });
        }
      },
    });

    if (!await isCurrentSession(sessionId)) return null;

    const latestCorrection = tabLyricsCorrectionStates.get(tabId);
    if (completedResult?.status === 'completed' && completedResult.track && latestCorrection?.jobId === trackedJobId
      && latestCorrection.status?.status === 'completed' && latestCorrection.status.attemptId === completedResult.attemptId) {
      const currentSubtitleState = tabSubtitleStates.get(tabId);
      const currentTrack = currentSubtitleState?.type === 'ready' ? currentSubtitleState.track : null;

      if (currentTrack && await activeReadyTrackMatches(tabId, pageStatus.videoId, trackedJobId, currentTrack.trackId, session.instanceId, sessionId)) {
        if (await isCurrentSession(sessionId)) {
          await publishSubtitleState(tabId, { type: 'ready', track: completedResult.track }, session.instanceId, session.sessionId);
        }
      } else if (await isCurrentSession(sessionId)) {
        await rememberActiveTrack(completedResult.track, session.instanceId);
      }
    }

    if (status && !['queued', 'running'].includes(status.status)) {
      const operation = await getTabOperation(tabId);

      if (operation?.kind === 'correction' && operation.instanceId === session.instanceId && operation.jobId === trackedJobId) {
        if (await isCurrentSession(session.sessionId)) await clearTabOperationIfMatches(tabId, operation);
      }
    }

    return status;
  } catch {
    return await isCurrentSession(session.sessionId) ? tabLyricsCorrectionStates.get(tabId)?.status ?? null : null;
  }
}

async function getPanelJobHistory(installId: string, session: InstanceContext | null, syncBackend: boolean): Promise<{ jobs: SubtitleJobHistoryItem[]; error?: string }> {
  if (!session) return { jobs: [] };
  const matches = cachedPanelJobHistoryInstanceId === session.instanceId && cachedPanelJobHistorySessionId === session.sessionId;
  if (!syncBackend) return matches ? { jobs: cachedPanelJobHistory, error: cachedPanelJobHistoryError } : { jobs: [] };
  try {
    const response = await subtitleApi.listSubtitleJobs(installId);
    if (!await isCurrentSession(session.sessionId)) return { jobs: [] };
    cachedPanelJobHistory = response.jobs;
    cachedPanelJobHistoryError = undefined;
    cachedPanelJobHistoryInstanceId = session.instanceId;
    cachedPanelJobHistorySessionId = session.sessionId;
  } catch {
    cachedPanelJobHistoryError = 'Unable to load backend job history.';
  }
  return { jobs: matches || cachedPanelJobHistorySessionId === session.sessionId ? cachedPanelJobHistory : [], error: cachedPanelJobHistoryError };
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

async function resolveCompletedSubtitleJob(
  installId: string,
    historyJob: SubtitleJobHistoryItem,
  expectedSessionId?: string,
): Promise<JobResponse | null> {
  try {
    const job = await subtitleApi.getSubtitleJob(installId, historyJob.jobId);

    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return null;

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

async function getSubtitleStateForPage(tabId: number, pageStatus: YoutubePageInfo, instanceId?: string, expectedSessionId?: string): Promise<SubtitleState> {
  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return DEFAULT_SUBTITLE_STATE;
  const subtitleState = instanceId !== undefined && tabSubtitleStateOwners.get(tabId) === instanceId
    ? tabSubtitleStates.get(tabId) ?? DEFAULT_SUBTITLE_STATE
    : DEFAULT_SUBTITLE_STATE;

  if (expectedSessionId !== undefined && tabSubtitleStates.has(tabId)
    && tabSubtitleStateSessions.get(tabId) !== expectedSessionId) return DEFAULT_SUBTITLE_STATE;

  if (!pageStatus.supported) {
    return DEFAULT_SUBTITLE_STATE;
  }

  if (subtitleState.type !== 'no-track' && isSubtitleStateForVideo(subtitleState, pageStatus.videoId)) {
    return subtitleState;
  }

  if (instanceId === undefined) {
    return DEFAULT_SUBTITLE_STATE;
  }

  const rememberedTrack = await getRememberedTrack(pageStatus.videoId, instanceId);

  if (!rememberedTrack) {
    return DEFAULT_SUBTITLE_STATE;
  }

  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return DEFAULT_SUBTITLE_STATE;
  const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
  const currentPage = parseYoutubePage(currentTab?.url ?? '');
  if (!currentPage.supported || currentPage.videoId !== pageStatus.videoId) return DEFAULT_SUBTITLE_STATE;

  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return DEFAULT_SUBTITLE_STATE;

  const restoredState: SubtitleState = { type: 'ready', track: rememberedTrack };
  tabSubtitleStates.set(tabId, restoredState);
  tabSubtitleStateOwners.set(tabId, instanceId);
  if (expectedSessionId !== undefined) tabSubtitleStateSessions.set(tabId, expectedSessionId);

  return restoredState;
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

async function publishSubtitleState(tabId: number, subtitleState: SubtitleState, instanceId?: string, sessionId?: string): Promise<void> {
  if (sessionId !== undefined && !await isCurrentSession(sessionId)) return;
  tabSubtitleStates.set(tabId, subtitleState);
  if (instanceId !== undefined) {
    tabSubtitleStateOwners.set(tabId, instanceId);
    if (sessionId !== undefined) tabSubtitleStateSessions.set(tabId, sessionId);
  } else {
    tabSubtitleStateOwners.delete(tabId);
    tabSubtitleStateSessions.delete(tabId);
  }

  if (subtitleState.type === 'ready' && instanceId !== undefined) {
    await rememberActiveTrack(subtitleState.track, instanceId);
  }

  if (sessionId !== undefined && !await isCurrentSession(sessionId)) return;

  await sendTabMessage(tabId, {
    type: 'background.subtitleStateChanged',
    subtitleState,
  });
}

async function getActiveTab(windowId?: number): Promise<Browser.tabs.Tab | undefined> {
  const [activeTab] = await browser.tabs.query(
    typeof windowId === 'number' ? { active: true, windowId } : { active: true, currentWindow: true },
  );

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
  return isPositiveVideoDurationSeconds(value);
}

function ensureRecoveredGenerationMonitor(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  installId: string,
  session: InstanceContext,
  initialJob?: JobResponse,
): void {
  if (tabGenerationInFlight.has(tabId)) return;

  const operation = Symbol('recovered-generation');
  tabOperations.set(tabId, operation);
  tabGenerationInFlight.add(tabId);
  let terminal = false;

  void (async () => {
    let job = initialJob;

    while (tabOperations.get(tabId) === operation && !job) {
      const currentSession = await getInstanceContext();
      if (!currentSession || currentSession.instanceId !== session.instanceId || currentSession.sessionId !== session.sessionId) return;

      try {
        const persisted = await getTabOperation(tabId);
        if (!persisted?.jobId || persisted.instanceId !== session.instanceId) return;
        job = await subtitleApi.getSubtitleJob(installId, persisted.jobId);
      } catch (error) {

        if (error instanceof SubtitleApiError && error.code === 'not_found') {
          terminal = true;
          throw error;
        }

        await delay(JOB_POLL_INTERVAL_MS);
      }
    }

    if (!job || tabOperations.get(tabId) !== operation) return;

    const result = await waitForCompletedSubtitleJob(tabId, pageStatus, installId, session, job, operation);
    if (!result || tabOperations.get(tabId) !== operation || !await isCurrentSession(session.sessionId)) return;
    terminal = true;

    if (result.status === 'cancelled') {
      terminal = true;
      await clearCancelledGenerationState(tabId, result.jobId, pageStatus.videoId, session.instanceId, session.sessionId, operation);
      return;
    }

    if (result.status === 'failed') {
      if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
        await publishSubtitleState(tabId, {
          type: 'error',
          jobId: result.jobId,
          youtubeVideoId: pageStatus.videoId,
          message: publicSubtitleJobFailureMessage(result),
        }, session.instanceId, session.sessionId);
      }
      return;
    }

    if (!result.track) return;

    if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, { type: 'ready', track: result.track }, session.instanceId, session.sessionId);
    } else {
      await rememberActiveTrack(result.track, session.instanceId);
    }
  })().catch(async (error: unknown) => {

    terminal = true;
    if (await isCurrentSession(session.sessionId)
      && tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'error',
        youtubeVideoId: pageStatus.videoId,
        message: publicSubtitleErrorMessage(error),
      }, session.instanceId, session.sessionId);
    }
  }).finally(async () => {
    if (tabOperations.get(tabId) !== operation) return;
    try {
      const persisted = await getTabOperation(tabId);
      const current = await getInstanceContext();
      if (terminal && tabOperations.get(tabId) === operation && current?.sessionId === session.sessionId
        && persisted?.kind === 'generation'
        && persisted.instanceId === session.instanceId
        && persisted.youtubeVideoId === pageStatus.videoId) {
        await clearTabOperationIfMatches(tabId, persisted);
      }
    } finally {
      if (tabOperations.get(tabId) === operation) {
        tabGenerationInFlight.delete(tabId);
        if (tabGenerationCancellationInFlight.get(tabId)?.operation !== operation) {
          tabOperations.delete(tabId);
        }
      }
    }
  }).catch(() => {});
}

async function isCurrentGenerationRecovery(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  sessionId: string,
  operation: symbol,
  expectedPersistedOperation: StoredTabOperation | null = null,
): Promise<boolean> {
  if (tabOperations.get(tabId) !== operation) return false;
  const session = await getInstanceContext();
  const tab = await browser.tabs.get(tabId).catch(() => undefined);
  const persistedOperation = await getTabOperation(tabId);
  const currentSession = await getInstanceContext();
  const currentPage = parseYoutubePage(tab?.url ?? '');

  return session?.sessionId === sessionId
    && currentSession?.sessionId === sessionId
    && currentPage.supported
    && currentPage.videoId === pageStatus.videoId
    && tabOperations.get(tabId) === operation
    && (expectedPersistedOperation === null
      ? persistedOperation === null
      : JSON.stringify(persistedOperation) === JSON.stringify(expectedPersistedOperation));
}

async function isCurrentSession(sessionId: string): Promise<boolean> {
  const session = await getInstanceContext();

  return session?.sessionId === sessionId;
}

function assertNever(value: never): never {
  throw new Error(`Unhandled background request: ${JSON.stringify(value)}`);
}
