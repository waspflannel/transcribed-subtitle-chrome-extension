import { browser, type Browser } from 'wxt/browser';

import {
  clearExtensionSession,
  getStoredExtensionSession,
  storeExtensionSession,
  updateStoredAccount,
  type StoredExtensionSession,
} from '../utils/account-session';
import { SubtitleApiClient, publicSubtitleErrorMessage, SubtitleApiError } from '../utils/api';
import { clearRememberedTracks, clearTabOperation, clearTabOperationIfMatches, clearTabOperations, forgetRememberedTrack, getRememberedTrack, getTabOperation, rememberActiveTrack, setTabOperation, updateTabOperationIfMatches } from '../utils/active-tracks';
import type { JobResponse, LearningTokenResponse, LyricsCorrectionStatus, SubtitleJobHistoryItem, TrackResponse } from '../utils/contracts';
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
let cachedPanelJobHistoryAccountId: string | undefined;
let cachedPanelJobHistorySessionId: string | undefined;
let localStateResetVersion = 0;
let accountMutationVersion = 0;
const JOB_POLL_INTERVAL_MS = 2000;
type SupportedYoutubePageInfo = Extract<YoutubePageInfo, { supported: true }>;
type PageSnapshotResponse = { ok: true; videoDurationSeconds?: number };

function isGenerationCancellationClaim(tabId: number, jobId: string, operation: symbol): boolean {
  const claim = tabGenerationCancellationInFlight.get(tabId);

  return claim?.jobId === jobId && claim.operation === operation;
}

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
      if (!page.supported || page.videoId !== message.youtubeVideoId
        || (typeof message.windowId === 'number' && tab.windowId !== message.windowId)) return { ok: false };
      const snapshot = await browser.tabs.sendMessage(message.tabId, {
        type: 'background.getActiveCue', youtubeVideoId: message.youtubeVideoId, trackId: message.trackId,
      });
      return { ...snapshot, tabId: message.tabId, windowId: tab.windowId };
    }

    case 'panel.updateSettings':
      return updateSettingsFromPanel(message.patch, message.windowId);

    case 'panel.generateSubtitles':
      return generateSubtitlesFromPanel(message.windowId);

    case 'panel.cancelSubtitleJob':
      return cancelSubtitleJobFromPanel(message, message.windowId);

    case 'panel.submitLyricsCorrection':
      return submitLyricsCorrectionFromPanel(message, message.windowId);

    case 'panel.cancelLyricsCorrection':
      return cancelLyricsCorrectionFromPanel(message, message.windowId);

    case 'panel.quickFixToken':
      return quickFixTokenFromPanel(message, message.windowId);

    case 'panel.login':
      return loginFromPanel(message.email, message.password, message.windowId);

    case 'panel.logout':
      return logoutFromPanel(message.windowId);

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

async function getContentState(sender: Browser.runtime.MessageSender): Promise<{
  installId: string;
  settings: ExtensionSettings;
  subtitleState: SubtitleState;
}> {
  const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;
  const pageStatus = parseYoutubePage(sender.tab?.url ?? '');
  const installId = await getOrCreateInstallId();
  const session = await getStoredExtensionSession();
  const accountId = session?.account.id;
  const settings = await getExtensionSettings();
  let subtitleState = tabId === null ? DEFAULT_SUBTITLE_STATE : await getSubtitleStateForPage(tabId, pageStatus, accountId, session?.sessionId);

  if (tabId !== null && subtitleState.type === 'no-track' && pageStatus.supported) {
    subtitleState = await recoverSubtitleStateFromBackend(tabId, pageStatus, subtitleState, installId, session);
  }

  const currentSession = await getStoredExtensionSession();
  if (session?.sessionId !== currentSession?.sessionId) {
    subtitleState = DEFAULT_SUBTITLE_STATE;
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
  pageStatus: SupportedYoutubePageInfo,
  localState: SubtitleState,
  installId: string,
  session: StoredExtensionSession | null,
): Promise<SubtitleState> {
  if (!session) {
    return localState;
  }

  if (await isSubtitleRecoveryBlocked()) return localState;

  const history = await getPanelJobHistory(installId, session, true);

  if (history.sessionInvalid) {
    return localState;
  }

  const resolved = await stateWithBackendProgress(localState, pageStatus, history.jobs, (job) =>
    resolveCompletedSubtitleJob(installId, session.plainTextToken, job, session.sessionId),
  );

  if (!await isCurrentSession(session.sessionId) || await isSubtitleRecoveryBlocked()) return localState;

  if (resolved.type === 'ready') {
    await storeReadySubtitleState(tabId, resolved, session.account.id, session.sessionId);
    if (!await isCurrentSession(session.sessionId)) return localState;

    return resolved;
  }

  if (resolved.type === 'loading') {
    tabSubtitleStates.set(tabId, resolved);
    tabSubtitleStateOwners.set(tabId, session.account.id);
    tabSubtitleStateSessions.set(tabId, session.sessionId);

    if (resolved.jobId && !tabGenerationInFlight.has(tabId) && await isCurrentSession(session.sessionId)) {
      const persistedOperation = await getTabOperation(tabId);
      if (!await isCurrentSession(session.sessionId)) return localState;
      if (persistedOperation?.kind === 'generation' && persistedOperation.accountId === session.account.id
        && persistedOperation.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId === resolved.jobId) {
        ensureRecoveredGenerationMonitor(tabId, pageStatus, installId, session);
      } else if (!persistedOperation || persistedOperation.accountId !== session.account.id) {
        if (persistedOperation) await clearTabOperationIfMatches(tabId, persistedOperation);
        if (await isCurrentSession(session.sessionId)) {
          await setTabOperation(tabId, {
            kind: 'generation',
            accountId: session.account.id,
            youtubeVideoId: pageStatus.videoId,
            jobId: resolved.jobId,
          });
          ensureRecoveredGenerationMonitor(tabId, pageStatus, installId, session);
        }
      }
    }

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

async function generateSubtitlesFromPanel(windowId?: number): Promise<PanelState> {
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

  const operation = Symbol('generation');
  tabOperations.set(activeTabId, operation);
  tabGenerationCancellationInFlight.delete(activeTabId);
  tabGenerationInFlight.add(activeTabId);
  let generationStarted = false;

  try {
    const session = await getStoredExtensionSession();
    const persistedOperation = await getTabOperation(activeTabId);
    if (session?.sessionId !== (await getStoredExtensionSession())?.sessionId) {
      return getPanelState({ syncBackend: false, windowId });
    }

    if (persistedOperation && persistedOperation.accountId !== session?.account.id) {
      if (persistedOperation) await clearTabOperationIfMatches(activeTabId, persistedOperation);
    } else if (persistedOperation?.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId) {
      if (!session) {
        throw new SubtitleApiError('unauthenticated', 'Sign in before continuing subtitle work.', 401);
      }

      if (persistedOperation.kind === 'correction') {
        const correction = await subtitleApi.getLyricsCorrectionStatus(
          await getOrCreateInstallId(),
          session.plainTextToken,
          persistedOperation.jobId,
        );

        if (correction.status === 'queued' || correction.status === 'running') {
          throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
        }
      } else {
        const job = await subtitleApi.getSubtitleJob(await getOrCreateInstallId(), session.plainTextToken, persistedOperation.jobId);

        if (job.status === 'queued' || job.status === 'running') {
          throw new SubtitleApiError('lyrics_correction_in_progress', 'Subtitle generation is already in progress.', 409);
        }
      }

      await clearTabOperationIfMatches(activeTabId, persistedOperation);
    } else if (persistedOperation) {
      await clearTabOperationIfMatches(activeTabId, persistedOperation);
    }

    const currentState = await getSubtitleStateForPage(activeTabId, pageStatus, session?.account.id, session?.sessionId);
    if (currentState.type === 'ready' && session) {
      try {
        const correction = await subtitleApi.getLyricsCorrectionStatus(
          await getOrCreateInstallId(),
          session.plainTextToken,
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
      if (!session) {
        throw new SubtitleApiError('unauthenticated', 'Sign in before generating subtitles.', 401);
      }

      await waitForExtensionSettingsWrites();
      if (!await isCurrentSession(session.sessionId)) {
        throw new SubtitleApiError('unauthenticated', 'Sign in before generating subtitles.', 401);
      }
      const settings = await getExtensionSettings();
      const pageSnapshot = await getPageSnapshotFromTab(activeTabId);
      const now = new Date().toISOString();

      if (!await isCurrentSession(session.sessionId)) {
        throw new SubtitleApiError('unauthenticated', 'Sign in before generating subtitles.', 401);
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
      }, session.account.id, session.sessionId);
      if (!await isCurrentSession(session.sessionId)) {
        throw new SubtitleApiError('unauthenticated', 'Sign in before generating subtitles.', 401);
      }

      await setTabOperation(activeTabId, { kind: 'generation', accountId: session.account.id, youtubeVideoId: pageStatus.videoId });
      generationStarted = true;
      void generateSubtitlesForTab(activeTabId, pageStatus, settings, pageSnapshot, session, operation)
        .finally(() => {
          if (tabOperations.get(activeTabId) === operation) tabGenerationInFlight.delete(activeTabId);
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
  session: StoredExtensionSession,
  operation: symbol,
): Promise<void> {
  const sessionId = session.sessionId;
  const accountId = session.account.id;
  let retainOperation = false;

  try {
    if (tabOperations.get(tabId) !== operation || !await isCurrentSession(sessionId)) return;

    console.info('extension.subtitle_generation_started', {
      youtubeVideoId: pageStatus.videoId,
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      enrichmentMode: 'on_demand',
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
      enrichmentMode: 'on_demand',
      includeRomanization: settings.showRomanization,
      includeTranslation: settings.showTranslation,
    });
    if (tabOperations.get(tabId) !== operation || isGenerationCancellationClaim(tabId, initialJob.jobId, operation)
      || !await isCurrentSession(sessionId)) return;
    const current = tabSubtitleStates.get(tabId);
    if (current?.type !== 'loading') return;
    tabSubtitleStates.set(tabId, { ...current, jobId: initialJob.jobId });
    tabSubtitleStateOwners.set(tabId, accountId);
    tabSubtitleStateSessions.set(tabId, sessionId);
    await setTabOperation(tabId, { kind: 'generation', accountId, youtubeVideoId: pageStatus.videoId, jobId: initialJob.jobId });
    if (tabOperations.get(tabId) !== operation || !await isCurrentSession(sessionId)) return;
    const job = await waitForCompletedSubtitleJob(tabId, pageStatus, installId, session, initialJob, operation);

    if (job === null) {
      retainOperation = true;
      return;
    }

    if (job.status === 'cancelled') {
      await clearCancelledGenerationState(tabId, job.jobId, pageStatus.videoId, accountId, sessionId, operation);
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
        }, accountId, sessionId);
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
        }, accountId, sessionId);
      } else {
        await rememberActiveTrack(job.track, accountId);
      }
    }

    console.info('extension.subtitle_generation_completed', {
      youtubeVideoId: pageStatus.videoId,
      jobId: job.jobId,
      trackId: job.track.trackId,
    });
  } catch (error) {
    if (isSessionInvalidError(error)) retainOperation = true;
    await clearSessionIfInvalid(error, sessionId);

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
      }, accountId, sessionId);
    }
  } finally {
    const persistedOperation = await getTabOperation(tabId);

    if (!retainOperation && await isCurrentSession(sessionId) && tabOperations.get(tabId) === operation && persistedOperation?.kind === 'generation'
      && persistedOperation.accountId === accountId && persistedOperation.youtubeVideoId === pageStatus.videoId) {
      await clearTabOperationIfMatches(tabId, persistedOperation);
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
  session: StoredExtensionSession,
  initialJob: JobResponse,
  operation: symbol,
): Promise<JobResponse | null> {
  let job = initialJob;
  let partialTrack = (await getTabOperation(tabId))?.partialTrack;

  while (tabOperations.get(tabId) === operation) {
    if (isGenerationCancellationClaim(tabId, job.jobId, operation)) return null;

    if (job.status === 'completed' || job.status === 'failed') {
      return job;
    }

    if (job.status === 'cancelled') {
      return job;
    }

    const currentSession = await getStoredExtensionSession();
    if (!currentSession || currentSession.account.id !== session.account.id || currentSession.sessionId !== session.sessionId) {
      return null;
    }

    if (PARTIAL_TRACK_STAGES.has(job.stage)) {
      partialTrack = (await fetchPartialTrack(installId, currentSession.plainTextToken, job)) ?? partialTrack;
      if (partialTrack && tabOperations.get(tabId) === operation) {
        if (!await isCurrentSession(session.sessionId)) return null;
        const persistedOperation = await getTabOperation(tabId);
        if (tabOperations.get(tabId) === operation && persistedOperation?.kind === 'generation'
          && persistedOperation.accountId === session.account.id
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
        message: job.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(job.stage),
        stage: job.stage,
        progressPercent: job.progressPercent,
        startedAt: job.createdAt,
        lastUpdatedAt: job.updatedAt,
        ...(partialTrack ? { partialTrack } : {}),
      }, session.account.id, session.sessionId);
    }

    await delay(JOB_POLL_INTERVAL_MS);
    if (tabOperations.get(tabId) !== operation || isGenerationCancellationClaim(tabId, job.jobId, operation)) return null;
    const refreshedSession = await getStoredExtensionSession();
    if (!refreshedSession || refreshedSession.account.id !== session.account.id || refreshedSession.sessionId !== session.sessionId) return null;

    try {
      job = await subtitleApi.getSubtitleJob(installId, refreshedSession.plainTextToken, job.jobId);
    } catch (error) {
      if (isSessionInvalidError(error)) {
        await clearSessionIfInvalid(error, refreshedSession.sessionId);

        return null;
      }

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
        }, session.account.id, session.sessionId);
      }
      continue;
    }
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

    if (response.jobId !== job.jobId || response.youtubeVideoId !== job.youtubeVideoId) return undefined;

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

  const session = await getStoredExtensionSession();

  if (!session) {
    throw new SubtitleApiError('unauthenticated', 'Sign in before enriching learning tokens.', 401);
  }

  const sessionId = session.sessionId;
  const accountId = session.account.id;
  const currentState = await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId, accountId, sessionId);

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
    await clearSessionIfInvalid(error, sessionId);

    throw error;
  }

  if (!await isCurrentSession(sessionId)) return { ok: true, stale: true };
  await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId, accountId, sessionId);
  if (!await isCurrentSession(sessionId)) return { ok: true, stale: true };
  const freshState = tabSubtitleStates.get(tabId);

  if (response.cueId !== message.cueId || response.token.index !== message.tokenIndex || freshState?.type !== 'ready' || freshState.track.trackId !== message.trackId
    || tabSubtitleStateOwners.get(tabId) !== accountId || freshState.track.youtubeVideoId !== message.youtubeVideoId
    || !freshState.track.cues.some((cue) => cue.cueId === message.cueId && cue.tokens.some((token) => token.index === message.tokenIndex))) {
    return { ok: true, stale: true };
  }

  const track = trackWithLearningToken(freshState.track, response.cueId, response.token);
  // Keep the final read/merge/write synchronous. A second enrichment reply
  // must see the first reply's token instead of merging both replies into the
  // same pre-await snapshot.
  const nextState: Extract<SubtitleState, { type: 'ready' }> = { type: 'ready', track };
  tabSubtitleStates.set(tabId, nextState);
  tabSubtitleStateOwners.set(tabId, accountId);
  tabSubtitleStateSessions.set(tabId, sessionId);
  if (await isCurrentSession(sessionId)) await rememberActiveTrack(track, accountId);

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
  const session = await getStoredExtensionSession();

  if (tabId === null || !session || !activeTab) {
    throw new SubtitleApiError('unauthenticated', 'Sign in before correcting lyrics.', 401);
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  const currentState = await getSubtitleStateForPage(tabId, pageStatus, session.account.id, session.sessionId);

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
    const accountId = session.account.id;
    const installId = await getOrCreateInstallId();
    await setTabOperation(tabId, {
      kind: 'correction',
      accountId,
      youtubeVideoId: message.youtubeVideoId,
      jobId: message.jobId,
      trackId: message.trackId,
    });
    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    let correction: LyricsCorrectionStatus;

    try {
      correction = await subtitleApi.startLyricsCorrection(
        installId,
        session.plainTextToken,
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
        if (operation?.kind === 'correction' && operation.accountId === accountId
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
      accountId,
      youtubeVideoId: message.youtubeVideoId,
      jobId: message.jobId,
      trackId: message.trackId,
      attemptId: correction.attemptId,
    });

    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, accountId, sessionId)) {
      if (correction.status === 'completed' && correction.track && await isCurrentSession(sessionId)) {
        await rememberActiveTrack(correction.track, accountId);
      }

      return getPanelState({ syncBackend: true, windowId });
    }

    tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(
      tabLyricsCorrectionStates.get(tabId) ?? lyricsCorrectionTabState(),
      { type: 'submit', jobId: message.jobId, status: correction },
    ));
    tabLyricsCorrectionStateSessions.set(tabId, sessionId);

    if (correction.status === 'completed' && correction.track) {
      await publishSubtitleState(tabId, { type: 'ready', track: correction.track }, accountId, sessionId);
      const operation = await getTabOperation(tabId);
      if (await isCurrentSession(sessionId) && operation?.kind === 'correction'
        && operation.accountId === accountId && operation.youtubeVideoId === message.youtubeVideoId
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
  const session = await getStoredExtensionSession();

  if (!session) {
    throw new SubtitleApiError('unauthenticated', 'Sign in before cancelling subtitle generation.', 401);
  }

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
    const currentState = await getSubtitleStateForPage(message.tabId, pageStatus, session.account.id, session.sessionId);
    const ownsCurrentJob = (currentState.type === 'loading' && currentState.youtubeVideoId === message.youtubeVideoId
      && currentState.jobId === message.jobId)
      || (capturedOperation?.kind === 'generation'
        && capturedOperation.accountId === session.account.id
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
      session.plainTextToken,
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
        session.account.id,
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
    }
    await clearSessionIfInvalid(error, sessionId);

    throw error;
  }
}

async function clearCancelledGenerationState(
  tabId: number,
  jobId: string,
  youtubeVideoId: string,
  accountId: string,
  sessionId: string,
  operation: symbol,
): Promise<void> {
  if (!await isCurrentSession(sessionId)) return;
  let currentOperation = await getTabOperation(tabId);
  if (!await isCurrentSession(sessionId) || tabOperations.get(tabId) !== operation) return;
  const operationMatches = currentOperation === null || (currentOperation.kind === 'generation'
    && currentOperation.accountId === accountId
    && currentOperation.youtubeVideoId === youtubeVideoId
    && currentOperation.jobId === jobId);
  const currentState = tabSubtitleStates.get(tabId);
  const stateMatches = isCancelledGenerationState(currentState, tabId, youtubeVideoId, jobId, accountId, sessionId);

  // A missing persisted operation is safe to recover when the in-memory state
  // still names this cancelled job. A different persisted operation belongs to
  // a newer request and must stop the cleanup.
  if (!operationMatches) return;

  if (stateMatches && currentState?.type === 'ready') {
    if (!await isCurrentSession(sessionId)) return;
    await forgetRememberedTrack(youtubeVideoId, currentState.track.trackId, accountId);
  }

  if (!await isCurrentSession(sessionId) || tabOperations.get(tabId) !== operation) return;
  currentOperation = await getTabOperation(tabId);
  if (currentOperation && !(currentOperation.kind === 'generation'
    && currentOperation.accountId === accountId
    && currentOperation.youtubeVideoId === youtubeVideoId
    && currentOperation.jobId === jobId)) return;
  if (currentOperation && !await clearTabOperationIfMatches(tabId, currentOperation)) return;

  // All storage/browser awaits are complete. Only now inspect the live claim
  // and state, so a newer same-tab operation cannot be deleted by this one.
  const finalOperation = await getTabOperation(tabId);
  const finalSession = await getStoredExtensionSession();
  const ownsClaim = tabOperations.get(tabId) === operation;
  const latestState = tabSubtitleStates.get(tabId);
  const stateStillMatches = isCancelledGenerationState(latestState, tabId, youtubeVideoId, jobId, accountId, sessionId);
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
  accountId: string,
  sessionId: string,
): boolean {
  const ownsState = tabSubtitleStateOwners.get(tabId) === accountId
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
  const session = await getStoredExtensionSession();

  if (tabId === null || !session || !activeTab) {
    throw new SubtitleApiError('unauthenticated', 'Sign in before cancelling lyrics.', 401);
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
    const accountId = session.account.id;
    const installId = await getOrCreateInstallId();
    const correction = await subtitleApi.cancelLyricsCorrection(installId, session.plainTextToken, message.jobId, { attemptId: message.attemptId });
    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, accountId, sessionId)) {
      if (correction.status === 'completed' && correction.track && await isCurrentSession(sessionId)) {
        await rememberActiveTrack(correction.track, accountId);
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
      await publishSubtitleState(tabId, { type: 'ready', track: correction.track }, accountId, sessionId);
    }

    const operation = await getTabOperation(tabId);
    if (await isCurrentSession(sessionId) && operation?.kind === 'correction'
      && operation.accountId === accountId && operation.youtubeVideoId === message.youtubeVideoId
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
  const session = await getStoredExtensionSession();

  if (tabId === null || !session || !activeTab) {
    throw new SubtitleApiError('unauthenticated', 'Sign in before fixing a token.', 401);
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  const currentState = await getSubtitleStateForPage(tabId, pageStatus, session.account.id, session.sessionId);

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
    const accountId = session.account.id;
    const installId = await getOrCreateInstallId();
    let track: TrackResponse;

    try {
      track = await subtitleApi.quickFixToken(
        installId,
        session.plainTextToken,
        message.jobId,
        message.cueId,
        message.tokenIndex,
        { expectedTrackId: message.trackId, text: message.text },
      );
    } catch (error) {
      if (error instanceof SubtitleApiError && error.code === 'lyrics_correction_in_progress' && error.details?.reason === 'stale_track') {
        if (await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, accountId, sessionId)) {
          tabSubtitleStates.delete(tabId);
          tabSubtitleStateOwners.delete(tabId);
          tabSubtitleStateSessions.delete(tabId);
          await forgetRememberedTrack(message.youtubeVideoId, message.trackId, accountId);
          await recoverExactJobAfterStaleTrack(tabId, message, installId, session.plainTextToken, sessionId, accountId);
        }
      }

      throw error;
    }

    if (!await isCurrentSession(sessionId)) return getPanelState({ syncBackend: false, windowId });
    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, accountId, sessionId)) {
      if (await isCurrentSession(sessionId)) await rememberActiveTrack(track, accountId);

      return getPanelState({ syncBackend: true, windowId });
    }

    await publishSubtitleState(tabId, { type: 'ready', track }, accountId, sessionId);

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    if (tabCorrectionMutationInFlight.get(tabId) === correctionClaim) tabCorrectionMutationInFlight.delete(tabId);
  }
}

async function recoverExactJobAfterStaleTrack(
  tabId: number,
  message: Extract<BackgroundRequest, { type: 'panel.quickFixToken' }>,
  installId: string,
  authToken: string,
  sessionId: string,
  accountId: string,
): Promise<void> {
  try {
    const session = await getStoredExtensionSession();
    if (!session || session.sessionId !== sessionId || session.account.id !== accountId) return;
    const job = await subtitleApi.getSubtitleJob(installId, authToken, message.jobId);

    if (job.status !== 'completed' || !job.track) return;

    if (!await isCurrentSession(session.sessionId)) return;

    await rememberActiveTrack(job.track, accountId);
    const tab = await browser.tabs.get(tabId).catch(() => undefined);
    const pageStatus = tab ? parseYoutubePage(tab.url ?? '') : undefined;

    if (pageStatus?.supported && pageStatus.videoId === message.youtubeVideoId) {
      await publishSubtitleState(tabId, { type: 'ready', track: job.track }, accountId, sessionId);
    }
  } catch {
    // The original stale-track error remains the user-visible result.
  }
}

async function activeReadyTrackMatches(
  tabId: number,
  youtubeVideoId: string,
  jobId: string,
  trackId: string,
  accountId: string,
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

  const currentState = await getSubtitleStateForPage(tabId, pageStatus, accountId, expectedSessionId);

  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return false;

  return currentState.type === 'ready'
    && currentState.track.jobId === jobId
    && currentState.track.trackId === trackId;
}

async function storeReadySubtitleState(
  tabId: number,
  subtitleState: Extract<SubtitleState, { type: 'ready' }>,
  accountId: string,
  expectedSessionId: string,
): Promise<void> {
  if (!await isCurrentSession(expectedSessionId)) return;
  tabSubtitleStates.set(tabId, subtitleState);
  tabSubtitleStateOwners.set(tabId, accountId);
  tabSubtitleStateSessions.set(tabId, expectedSessionId);
  await rememberActiveTrack(subtitleState.track, accountId);
  if (!await isCurrentSession(expectedSessionId)
    && tabSubtitleStateSessions.get(tabId) === expectedSessionId
    && tabSubtitleStates.get(tabId) === subtitleState) {
    tabSubtitleStates.delete(tabId);
    tabSubtitleStateOwners.delete(tabId);
    tabSubtitleStateSessions.delete(tabId);
  }
}

async function readySubtitleStateForEnrichment(
  tabId: number,
  youtubeVideoId: string,
  trackId: string,
  accountId: string,
  expectedSessionId?: string,
): Promise<Extract<SubtitleState, { type: 'ready' }> | null> {
  const currentState = tabSubtitleStates.get(tabId);
  const ownsState = tabSubtitleStateOwners.get(tabId) === accountId
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

  const rememberedTrack = await getRememberedTrack(youtubeVideoId, accountId);

  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return null;
  const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
  const currentPage = parseYoutubePage(currentTab?.url ?? '');
  if (!currentPage.supported || currentPage.videoId !== youtubeVideoId) return null;
  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return null;

  if (rememberedTrack?.trackId === trackId) {
    const restoredState: Extract<SubtitleState, { type: 'ready' }> = { type: 'ready', track: rememberedTrack };
    tabSubtitleStates.set(tabId, restoredState);
    tabSubtitleStateOwners.set(tabId, accountId);
    if (expectedSessionId) tabSubtitleStateSessions.set(tabId, expectedSessionId);

    return restoredState;
  }

  return null;
}

async function clearLocalStateFromPanel(windowId?: number): Promise<PanelState> {
  const resetVersion = ++localStateResetVersion;
  const mutationVersion = ++accountMutationVersion;
  await setSubtitleRecoveryBlocked(true);
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await waitForExtensionSettingsWrites();
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await clearLocalExtensionState();
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  const session = await getStoredExtensionSession();
  if (session && !await clearExtensionSession(session.sessionId)) {
    return getPanelState({ syncBackend: false, windowId });
  }
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await clearRememberedTracks();
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  await clearTabOperations();
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  tabSubtitleStates.clear();
  tabSubtitleStateOwners.clear();
  tabSubtitleStateSessions.clear();
  tabGenerationCancellationInFlight.clear();
  tabOperations.clear();
  tabGenerationInFlight.clear();
  tabCorrectionMutationInFlight.clear();
  resetLyricsCorrectionStatesForSession();

  const activeTab = await getActiveTab(windowId);
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }
  const activeTabId = activeTab?.id ?? null;
  const settings = await getExtensionSettings();
  if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }

  console.info('extension.local_state_cleared', {
    activeTabId,
  });

  if (activeTabId !== null) {
    await sendTabMessage(activeTabId, {
      type: 'background.settingsChanged',
      settings,
    });
    if (resetVersion !== localStateResetVersion || mutationVersion !== accountMutationVersion) {
      return getPanelState({ syncBackend: false, windowId });
    }

    await publishSubtitleState(activeTabId, DEFAULT_SUBTITLE_STATE);
  }

  return getPanelState({ syncBackend: true, windowId });
}

async function loginFromPanel(email: string, password: string, windowId?: number): Promise<PanelState> {
  const mutationVersion = ++accountMutationVersion;
  const resetVersion = localStateResetVersion;
  const installId = await getOrCreateInstallId();
  const response = await subtitleApi.loginExtension(installId, { email, password });
  if (mutationVersion !== accountMutationVersion || resetVersion !== localStateResetVersion) {
    return getPanelState({ syncBackend: false, windowId });
  }

  const storedSession = await storeExtensionSession(response);
  resetLyricsCorrectionStatesForSession(storedSession.sessionId);

  console.info('extension.account_login_completed');

  return getPanelState({ syncBackend: true, windowId });
}

async function logoutFromPanel(windowId?: number): Promise<PanelState> {
  const mutationVersion = ++accountMutationVersion;
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

  if (mutationVersion !== accountMutationVersion) return getPanelState({ syncBackend: false, windowId });

  if (session && !await clearExtensionSession(session.sessionId)) {
    return getPanelState({ syncBackend: false, windowId });
  }
  tabGenerationCancellationInFlight.clear();
  resetLyricsCorrectionStatesForSession();
  await clearLocalSubtitleStates(windowId, session?.sessionId, session?.account.id);

  console.info('extension.account_logout_completed');

  return getPanelState({ syncBackend: true, windowId });
}

async function getPanelState(options: { syncBackend: boolean; windowId?: number; retryOnSessionChange?: boolean }): Promise<PanelState> {
  const activeTab = await getActiveTab(options.windowId);
  const activeTabId = activeTab?.id ?? null;
  const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;
  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();
  let effectiveSession = await getStoredExtensionSession();
  const startingSessionId = effectiveSession?.sessionId;
  const pageSnapshot = activeTabId === null || pageStatus?.supported !== true
    ? {}
    : await getPageSnapshotFromTab(activeTabId);

  if (effectiveSession && options.syncBackend) {
    effectiveSession = await syncExtensionAccount(installId, effectiveSession);
  }

  const history = await getPanelJobHistory(installId, effectiveSession, options.syncBackend);
  let backendRecoveryBlocked = await isSubtitleRecoveryBlocked();

  if (history.sessionInvalid) {
    effectiveSession = null;
    cachedPanelJobHistory = [];
    cachedPanelJobHistoryError = undefined;
    cachedPanelJobHistoryAccountId = undefined;
    cachedPanelJobHistorySessionId = undefined;
  }

  const localState =
    activeTabId === null || pageStatus === undefined
      ? DEFAULT_SUBTITLE_STATE
       : await getSubtitleStateForPage(activeTabId, pageStatus, effectiveSession?.account.id, effectiveSession?.sessionId);

  let stateForRecovery = localState;
  if (activeTabId !== null && pageStatus?.supported && effectiveSession) {
    let operation = await getTabOperation(activeTabId);
    if (!await isCurrentSession(effectiveSession.sessionId)) {
      return getPanelState({ ...options, retryOnSessionChange: false });
    }

    if (operation && operation.accountId !== effectiveSession.account.id) {
      const cleared = await clearTabOperationIfMatches(activeTabId, operation);
      if (cleared) operation = null;
      if (tabSubtitleStateOwners.get(activeTabId) !== effectiveSession.account.id) {
        tabSubtitleStates.delete(activeTabId);
        tabSubtitleStateOwners.delete(activeTabId);
        tabSubtitleStateSessions.delete(activeTabId);
      }
    } else if (operation?.kind === 'generation' && operation.youtubeVideoId === pageStatus.videoId) {
      let recoveryJob: JobResponse | undefined;
      let terminalStatusMissing = false;
      if (operation.jobId) {
        try {
          recoveryJob = await subtitleApi.getSubtitleJob(installId, effectiveSession.plainTextToken, operation.jobId);
        } catch (error) {
          if (isSessionInvalidError(error)) {
            await clearSessionIfInvalid(error, effectiveSession.sessionId);
            return getPanelState({ ...options, retryOnSessionChange: false });
          }

          if (error instanceof SubtitleApiError && error.code === 'not_found') {
            terminalStatusMissing = true;
            if (await isCurrentSession(effectiveSession.sessionId)) {
              await clearTabOperationIfMatches(activeTabId, operation);
            }
            recoveryJob = undefined;
          }
        }
      } else {
        const historyJob = history.jobs.find((job) => job.youtubeVideoId === pageStatus.videoId
          && (job.status === 'queued' || job.status === 'running'));
        recoveryJob = historyJob
          ? { ...historyJob, createdAt: historyJob.startedAt, updatedAt: historyJob.lastUpdatedAt }
          : undefined;
        if (recoveryJob && await isCurrentSession(effectiveSession.sessionId)) {
          await updateTabOperationIfMatches(activeTabId, operation, { ...operation, jobId: recoveryJob.jobId });
        }
      }

      if (!recoveryJob && terminalStatusMissing) {
        stateForRecovery = {
          type: 'error',
          jobId: operation.jobId,
          youtubeVideoId: pageStatus.videoId,
          message: 'This generation is no longer available. Start a new generation if needed.',
        };
        if (await isCurrentSession(effectiveSession.sessionId)) {
          tabSubtitleStates.set(activeTabId, stateForRecovery);
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.account.id);
          tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
        }
      } else if (!recoveryJob) {
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
        if (operation.jobId && !terminalStatusMissing && await isCurrentSession(effectiveSession.sessionId)) {
          ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession);
        }
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
        if (await isCurrentSession(effectiveSession.sessionId)) {
          tabSubtitleStates.set(activeTabId, stateForRecovery);
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.account.id);
          tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
          ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession, recoveryJob);
        }
      } else if (recoveryJob.status === 'completed' && recoveryJob.track) {
        stateForRecovery = { type: 'ready', track: recoveryJob.track };
        if (await isCurrentSession(effectiveSession.sessionId)) {
          await storeReadySubtitleState(activeTabId, stateForRecovery, effectiveSession.account.id, effectiveSession.sessionId);
          if (await isCurrentSession(effectiveSession.sessionId)) await clearTabOperationIfMatches(activeTabId, operation);
        }
      } else if (recoveryJob.status === 'cancelled') {
        stateForRecovery = DEFAULT_SUBTITLE_STATE;
        if (await isCurrentSession(effectiveSession.sessionId)) {
          tabSubtitleStates.delete(activeTabId);
          tabSubtitleStateOwners.delete(activeTabId);
          tabSubtitleStateSessions.delete(activeTabId);
          await clearTabOperationIfMatches(activeTabId, operation);
        }
      } else {
        stateForRecovery = {
          type: 'error',
          jobId: recoveryJob.jobId,
          youtubeVideoId: pageStatus.videoId,
          message: publicSubtitleJobFailureMessage(recoveryJob),
        };
        if (await isCurrentSession(effectiveSession.sessionId)) {
          tabSubtitleStates.set(activeTabId, stateForRecovery);
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.account.id);
          tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
          await clearTabOperationIfMatches(activeTabId, operation);
        }
      }
    }

    if (!backendRecoveryBlocked && !operation && localState.type !== 'ready' && localState.type !== 'error') {
      const historyJob = history.jobs.find((job) => job.youtubeVideoId === pageStatus.videoId
        && (job.status === 'queued' || job.status === 'running'));
      if (historyJob && await isCurrentSession(effectiveSession.sessionId)) {
        const recoveredOperation = {
          kind: 'generation' as const,
          accountId: effectiveSession.account.id,
          youtubeVideoId: pageStatus.videoId,
          jobId: historyJob.jobId,
        };
        await setTabOperation(activeTabId, recoveredOperation);
        if (await isCurrentSession(effectiveSession.sessionId) && !tabGenerationInFlight.has(activeTabId)) {
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
          tabSubtitleStateOwners.set(activeTabId, effectiveSession.account.id);
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
  let subtitleState = backendRecoveryBlocked ? stateForRecovery : await stateWithBackendProgress(stateForRecovery, pageStatus, history.jobs, (job) =>
    effectiveSession ? resolveCompletedSubtitleJob(installId, effectiveSession.plainTextToken, job, effectiveSession.sessionId) : Promise.resolve(null),
  );
  const lyricsCorrection = await syncLyricsCorrection(activeTabId, pageStatus, effectiveSession, installId, options.syncBackend, stateForRecovery);

  if (activeTabId !== null && pageStatus?.supported) {
    const currentSubtitleState = effectiveSession?.account.id === undefined
      ? undefined
      : tabSubtitleStateOwners.get(activeTabId) === effectiveSession.account.id
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
      subtitleState = await getSubtitleStateForPage(activeTabId, pageStatus, effectiveSession?.account.id, effectiveSession?.sessionId);
    } else {
      await publishSubtitleState(activeTabId, subtitleState, effectiveSession?.account.id, effectiveSession?.sessionId);
    }
  }

  const currentSession = await getStoredExtensionSession();
  if (options.retryOnSessionChange !== false && startingSessionId !== currentSession?.sessionId) {
    return getPanelState({ ...options, retryOnSessionChange: false });
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
    lyricsCorrection,
    lyricsCorrectionSyncError: activeTabId !== null ? tabLyricsCorrectionStates.get(activeTabId)?.syncError : undefined,
  };
}

async function syncLyricsCorrection(
  tabId: number | null,
  pageStatus: YoutubePageInfo | undefined,
  session: StoredExtensionSession | null,
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
  if (persistedOperation && persistedOperation.accountId !== session.account.id) {
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
    const status = await syncLyricsCorrectionStatus({
      tabId,
      jobId: trackedJobId,
      syncBackend,
      states: tabLyricsCorrectionStates,
      fetchStatus: () => subtitleApi.getLyricsCorrectionStatus(installId, session.plainTextToken, trackedJobId),
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

    if (status?.status === 'completed' && status.track && await isCurrentSession(sessionId)) {
      const currentSubtitleState = tabSubtitleStates.get(tabId);
      const currentTrack = currentSubtitleState?.type === 'ready' ? currentSubtitleState.track : null;

      if (currentTrack && await activeReadyTrackMatches(tabId, pageStatus.videoId, trackedJobId, currentTrack.trackId, session.account.id, sessionId)) {
        if (await isCurrentSession(sessionId)) {
          await publishSubtitleState(tabId, { type: 'ready', track: status.track }, session.account.id, session.sessionId);
        }
      } else if (await isCurrentSession(sessionId)) {
        await rememberActiveTrack(status.track, session.account.id);
      }
    }

    if (status && !['queued', 'running'].includes(status.status)) {
      const operation = await getTabOperation(tabId);

      if (operation?.kind === 'correction' && operation.accountId === session.account.id && operation.jobId === trackedJobId) {
        if (await isCurrentSession(session.sessionId)) await clearTabOperationIfMatches(tabId, operation);
      }
    }

    return status;
  } catch {
    return await isCurrentSession(session.sessionId) ? tabLyricsCorrectionStates.get(tabId)?.status ?? null : null;
  }
}

async function getPanelJobHistory(
  installId: string,
  session: StoredExtensionSession | null,
  syncBackend: boolean,
): Promise<{ jobs: SubtitleJobHistoryItem[]; error?: string; sessionInvalid: boolean }> {
  if (!session) {
    cachedPanelJobHistory = [];
    cachedPanelJobHistoryError = undefined;
    cachedPanelJobHistoryAccountId = undefined;
    cachedPanelJobHistorySessionId = undefined;

    return { jobs: [], error: undefined, sessionInvalid: false };
  }

  if (!syncBackend) {
    if (cachedPanelJobHistoryAccountId !== session.account.id || cachedPanelJobHistorySessionId !== session.sessionId) {
      return { jobs: [], error: undefined, sessionInvalid: false };
    }

    return {
      jobs: cachedPanelJobHistory,
      error: cachedPanelJobHistoryError,
      sessionInvalid: false,
    };
  }

  const history = await listBackendJobHistory(installId, session.plainTextToken, session.sessionId);

  if (!history.sessionInvalid && !await isCurrentSession(session.sessionId)) {
    return { jobs: [], error: undefined, sessionInvalid: false };
  }

  if (history.sessionInvalid) {
    cachedPanelJobHistory = [];
    cachedPanelJobHistoryError = history.error;
    cachedPanelJobHistoryAccountId = undefined;
    cachedPanelJobHistorySessionId = undefined;
  } else if (history.error && cachedPanelJobHistoryAccountId === session.account.id
    && cachedPanelJobHistorySessionId === session.sessionId) {
    cachedPanelJobHistoryError = history.error;
    return {
      jobs: cachedPanelJobHistory,
      error: history.error,
      sessionInvalid: false,
    };
  } else if (await isCurrentAccount(session.account.id)) {
    cachedPanelJobHistory = history.jobs;
    cachedPanelJobHistoryError = history.error;
    cachedPanelJobHistoryAccountId = session.account.id;
    cachedPanelJobHistorySessionId = session.sessionId;
  }

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
  expectedSessionId: string,
): Promise<{ jobs: SubtitleJobHistoryItem[]; error?: string; sessionInvalid: boolean }> {
  try {
    const response = await subtitleApi.listSubtitleJobs(installId, authToken);

    return { jobs: response.jobs, sessionInvalid: false };
  } catch (error) {
    if (await clearSessionIfInvalid(error, expectedSessionId)) {
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
  expectedSessionId?: string,
): Promise<JobResponse | null> {
  try {
    const job = await subtitleApi.getSubtitleJob(installId, authToken, historyJob.jobId);

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

async function syncExtensionAccount(
  installId: string,
  session: StoredExtensionSession,
): Promise<StoredExtensionSession | null> {
  try {
    const response = await subtitleApi.getExtensionAccount(installId, session.plainTextToken);

    if (!await isCurrentSession(session.sessionId)) return getStoredExtensionSession();

    const updated = await updateStoredAccount(response.account, session.sessionId);

    if (updated && await isCurrentSession(session.sessionId)) return updated;

    return getStoredExtensionSession();
  } catch (error) {
    if (await clearSessionIfInvalid(error, session.sessionId)) {
      return null;
    }

    return await isCurrentSession(session.sessionId) ? session : getStoredExtensionSession();
  }
}

async function getSubtitleStateForPage(tabId: number, pageStatus: YoutubePageInfo, accountId?: string, expectedSessionId?: string): Promise<SubtitleState> {
  if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId)) return DEFAULT_SUBTITLE_STATE;
  const subtitleState = accountId !== undefined && tabSubtitleStateOwners.get(tabId) === accountId
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

  if (accountId === undefined) {
    return DEFAULT_SUBTITLE_STATE;
  }

  const rememberedTrack = await getRememberedTrack(pageStatus.videoId, accountId);

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
  tabSubtitleStateOwners.set(tabId, accountId);
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

async function publishSubtitleState(tabId: number, subtitleState: SubtitleState, accountId?: string, sessionId?: string): Promise<void> {
  if (sessionId !== undefined && !await isCurrentSession(sessionId)) return;
  tabSubtitleStates.set(tabId, subtitleState);
  if (accountId !== undefined) {
    tabSubtitleStateOwners.set(tabId, accountId);
    if (sessionId !== undefined) tabSubtitleStateSessions.set(tabId, sessionId);
  } else {
    tabSubtitleStateOwners.delete(tabId);
    tabSubtitleStateSessions.delete(tabId);
  }

  if (subtitleState.type === 'ready' && accountId !== undefined) {
    await rememberActiveTrack(subtitleState.track, accountId);
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
  return isPositiveVideoDurationSeconds(value) && value <= 3600;
}

function isSessionInvalidError(error: unknown): boolean {
  return error instanceof SubtitleApiError
    && (error.code === 'unauthenticated' || error.code === 'expired' || error.code === 'email_not_verified');
}

async function clearSessionIfInvalid(error: unknown, expectedSessionId?: string): Promise<boolean> {
  if (!isSessionInvalidError(error)) {
    return false;
  }

  const currentSession = await getStoredExtensionSession();

  if (currentSession && (expectedSessionId !== undefined && currentSession.sessionId !== expectedSessionId)) {
    return false;
  }

  if (!await clearExtensionSession(expectedSessionId ?? currentSession?.sessionId)) return false;
  tabGenerationCancellationInFlight.clear();
  resetLyricsCorrectionStatesForSession();
  await clearLocalSubtitleStates(undefined, expectedSessionId ?? currentSession?.sessionId, currentSession?.account.id);

  return true;
}

async function clearLocalSubtitleStates(windowId?: number, expectedSessionId?: string, expectedAccountId?: string): Promise<void> {
  const activeTabId = (await getActiveTab(windowId))?.id;
  if (expectedSessionId !== undefined) {
    const currentSession = await getStoredExtensionSession();
    if (currentSession && currentSession.sessionId !== expectedSessionId) return;
  }

  const ownsExpectedState = (tabId: number): boolean =>
    (expectedSessionId === undefined || tabSubtitleStateSessions.get(tabId) === expectedSessionId)
    && (expectedAccountId === undefined || tabSubtitleStateOwners.get(tabId) === expectedAccountId);
  const tabIds = new Set([...tabSubtitleStates.keys()].filter(ownsExpectedState));
  if (typeof activeTabId === 'number'
    && ownsExpectedState(activeTabId)) tabIds.add(activeTabId);
  for (const tabId of tabIds) {
    if (ownsExpectedState(tabId)) {
      tabSubtitleStates.delete(tabId);
      tabSubtitleStateOwners.delete(tabId);
      tabSubtitleStateSessions.delete(tabId);
    }
  }
  if (expectedAccountId === undefined) tabGenerationCancellationInFlight.clear();

  await Promise.all([...tabIds].map((tabId) => sendTabMessage(tabId, {
    type: 'background.subtitleStateChanged',
    subtitleState: DEFAULT_SUBTITLE_STATE,
  })));
}

function ensureRecoveredGenerationMonitor(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  installId: string,
  session: StoredExtensionSession,
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
      const currentSession = await getStoredExtensionSession();
      if (!currentSession || currentSession.account.id !== session.account.id || currentSession.sessionId !== session.sessionId) return;

      try {
        const persisted = await getTabOperation(tabId);
        if (!persisted?.jobId || persisted.accountId !== session.account.id) return;
        job = await subtitleApi.getSubtitleJob(installId, currentSession.plainTextToken, persisted.jobId);
      } catch (error) {
        if (isSessionInvalidError(error)) {
          await clearSessionIfInvalid(error, currentSession.sessionId);
          return;
        }

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
      await clearCancelledGenerationState(tabId, result.jobId, pageStatus.videoId, session.account.id, session.sessionId, operation);
      return;
    }

    if (result.status === 'failed') {
      if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
        await publishSubtitleState(tabId, {
          type: 'error',
          jobId: result.jobId,
          youtubeVideoId: pageStatus.videoId,
          message: publicSubtitleJobFailureMessage(result),
        }, session.account.id, session.sessionId);
      }
      return;
    }

    if (!result.track) return;

    if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, { type: 'ready', track: result.track }, session.account.id, session.sessionId);
    } else {
      await rememberActiveTrack(result.track, session.account.id);
    }
  })().catch(async (error: unknown) => {
    if (isSessionInvalidError(error)) {
      await clearSessionIfInvalid(error, session.sessionId);
      return;
    }

    terminal = true;
    if (await isCurrentSession(session.sessionId)
      && tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'error',
        youtubeVideoId: pageStatus.videoId,
        message: publicSubtitleErrorMessage(error),
      }, session.account.id, session.sessionId);
    }
  }).finally(async () => {
    if (tabOperations.get(tabId) === operation) {
      tabGenerationInFlight.delete(tabId);
      const persisted = await getTabOperation(tabId);
      const current = await getStoredExtensionSession();
      if (terminal && current?.sessionId === session.sessionId
        && persisted?.kind === 'generation'
        && persisted.accountId === session.account.id
        && persisted.youtubeVideoId === pageStatus.videoId) {
        await clearTabOperationIfMatches(tabId, persisted);
        tabOperations.delete(tabId);
      }
    }
  }).catch(() => {});
}

async function isCurrentSession(sessionId: string): Promise<boolean> {
  const session = await getStoredExtensionSession();

  return session?.sessionId === sessionId;
}

async function isCurrentAccount(accountId: string): Promise<boolean> {
  const session = await getStoredExtensionSession();

  return session?.account.id === accountId;
}

function assertNever(value: never): never {
  throw new Error(`Unhandled background request: ${JSON.stringify(value)}`);
}
