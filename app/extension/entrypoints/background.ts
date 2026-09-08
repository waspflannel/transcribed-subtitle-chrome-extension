import { browser, type Browser } from 'wxt/browser';

import {
  clearExtensionSession,
  getStoredExtensionSession,
  storeExtensionSession,
  updateStoredAccount,
  type StoredExtensionSession,
} from '../utils/account-session';
import { SubtitleApiClient, publicSubtitleErrorMessage, SubtitleApiError } from '../utils/api';
import { clearRememberedTracks, clearTabOperation, clearTabOperations, forgetRememberedTrack, getRememberedTrack, getTabOperation, rememberActiveTrack, setTabOperation } from '../utils/active-tracks';
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
  updateExtensionSettings,
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
const tabOperations = new Map<number, symbol>();
const tabLyricsCorrectionStates = new Map<number, LyricsCorrectionTabState>();
const tabGenerationInFlight = new Set<number>();
const tabCorrectionMutationInFlight = new Set<number>();
const panelPorts = new Set<Browser.runtime.Port>();
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
    tabLyricsCorrectionStates.delete(tabId);
    tabGenerationInFlight.delete(tabId);
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

    case 'panel.submitLyricsCorrection':
      return submitLyricsCorrectionFromPanel(message, message.windowId);

    case 'panel.cancelLyricsCorrection':
      return cancelLyricsCorrectionFromPanel(message, message.windowId);

    case 'panel.quickFixToken':
      return quickFixTokenFromPanel(message, message.windowId);

    case 'panel.login':
      return loginFromPanel(message.email, message.password);

    case 'panel.logout':
      return logoutFromPanel();

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
      const tabId = typeof message.tabId === 'number'
        ? message.tabId
        : await tabIdForVideo(message.youtubeVideoId, message.windowId);
      if (tabId !== null) {
        const tab = await browser.tabs.get(tabId).catch(() => undefined);
        const page = parseYoutubePage(tab?.url ?? '');
        if (!tab || !page.supported || page.videoId !== message.youtubeVideoId
          || (typeof message.windowId === 'number' && tab.windowId !== message.windowId)) {
          return { ok: false };
        }
        await sendTabMessage(tabId, {
          type: 'background.seekToCue',
          youtubeVideoId: message.youtubeVideoId,
          trackId: message.trackId,
          cueId: message.cueId,
          mode: message.mode,
        });
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

  const persistedOperation = await getTabOperation(activeTabId);
  if (persistedOperation?.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId) {
    const session = await getStoredExtensionSession();

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

    await clearTabOperation(activeTabId);
  } else if (persistedOperation) {
    await clearTabOperation(activeTabId);
  }

  tabGenerationInFlight.add(activeTabId);
  let generationStarted = false;

  try {
    const currentState = await getSubtitleStateForPage(activeTabId, pageStatus);
    const session = await getStoredExtensionSession();
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

      await setTabOperation(activeTabId, { kind: 'generation', youtubeVideoId: pageStatus.videoId });
      generationStarted = true;
      void generateSubtitlesForTab(activeTabId, pageStatus, settings, pageSnapshot, operation)
        .finally(() => tabGenerationInFlight.delete(activeTabId));
    }

    return getPanelState({ syncBackend: false, windowId });
  } finally {
    if (!generationStarted) {
      tabGenerationInFlight.delete(activeTabId);
    }
  }
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
    if (tabOperations.get(tabId) !== operation) return;
    const current = tabSubtitleStates.get(tabId);
    if (current?.type !== 'loading') return;
    tabSubtitleStates.set(tabId, { ...current, jobId: initialJob.jobId });
    await setTabOperation(tabId, { kind: 'generation', youtubeVideoId: pageStatus.videoId, jobId: initialJob.jobId });
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
  } finally {
    const operation = await getTabOperation(tabId);

    if (operation?.kind === 'generation' && operation.youtubeVideoId === pageStatus.videoId) {
      await clearTabOperation(tabId);
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

  await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId);
  const freshState = tabSubtitleStates.get(tabId);

  if (response.cueId !== message.cueId || response.token.index !== message.tokenIndex || freshState?.type !== 'ready' || freshState.track.trackId !== message.trackId
    || freshState.track.youtubeVideoId !== message.youtubeVideoId || !freshState.track.cues.some((cue) => cue.cueId === message.cueId && cue.tokens.some((token) => token.index === message.tokenIndex))) {
    return { ok: true, stale: true };
  }

  const track = trackWithLearningToken(freshState.track, response.cueId, response.token);

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
  const currentState = await getSubtitleStateForPage(tabId, pageStatus);

  if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId || currentState.type !== 'ready' || currentState.track.jobId !== message.jobId || currentState.track.trackId !== message.trackId) {
    throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
  }

  if (tabGenerationInFlight.has(tabId) || tabCorrectionMutationInFlight.has(tabId)) {
    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
  }

  tabCorrectionMutationInFlight.add(tabId);
  try {
    const installId = await getOrCreateInstallId();
    await setTabOperation(tabId, {
      kind: 'correction',
      youtubeVideoId: message.youtubeVideoId,
      jobId: message.jobId,
      trackId: message.trackId,
    });
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
      await clearTabOperation(tabId);
      throw error;
    }
    await setTabOperation(tabId, {
      kind: 'correction',
      youtubeVideoId: message.youtubeVideoId,
      jobId: message.jobId,
      trackId: message.trackId,
      attemptId: correction.attemptId,
    });

    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId)) {
      if (correction.status === 'completed' && correction.track) {
        await rememberActiveTrack(correction.track);
      }

      return getPanelState({ syncBackend: true, windowId });
    }

    tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(
      tabLyricsCorrectionStates.get(tabId) ?? lyricsCorrectionTabState(),
      { type: 'submit', jobId: message.jobId, status: correction },
    ));

    if (correction.status === 'completed' && correction.track) {
      await publishSubtitleState(tabId, { type: 'ready', track: correction.track });
      await clearTabOperation(tabId);
    }

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    tabCorrectionMutationInFlight.delete(tabId);
  }
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

  tabCorrectionMutationInFlight.add(tabId);
  try {
    const installId = await getOrCreateInstallId();
    const correction = await subtitleApi.cancelLyricsCorrection(installId, session.plainTextToken, message.jobId, { attemptId: message.attemptId });
    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId)) {
      if (correction.status === 'completed' && correction.track) {
        await rememberActiveTrack(correction.track);
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

    if (correction.status === 'completed' && correction.track) {
      await publishSubtitleState(tabId, { type: 'ready', track: correction.track });
    }

    await clearTabOperation(tabId);

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    tabCorrectionMutationInFlight.delete(tabId);
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
  const currentState = await getSubtitleStateForPage(tabId, pageStatus);

  if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId || currentState.type !== 'ready' || currentState.track.jobId !== message.jobId || currentState.track.trackId !== message.trackId) {
    throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
  }

  if (tabGenerationInFlight.has(tabId) || tabCorrectionMutationInFlight.has(tabId)) {
    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
  }

  tabCorrectionMutationInFlight.add(tabId);
  try {
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
        tabSubtitleStates.delete(tabId);
        await forgetRememberedTrack(message.youtubeVideoId, message.trackId);
        await recoverExactJobAfterStaleTrack(tabId, message, installId, session.plainTextToken);
      }

      throw error;
    }

    if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId)) {
      tabSubtitleStates.delete(tabId);
      await rememberActiveTrack(track);

      return getPanelState({ syncBackend: true, windowId });
    }

    await publishSubtitleState(tabId, { type: 'ready', track });

    return getPanelState({ syncBackend: true, windowId });
  } finally {
    tabCorrectionMutationInFlight.delete(tabId);
  }
}

async function recoverExactJobAfterStaleTrack(
  tabId: number,
  message: Extract<BackgroundRequest, { type: 'panel.quickFixToken' }>,
  installId: string,
  authToken: string,
): Promise<void> {
  try {
    const job = await subtitleApi.getSubtitleJob(installId, authToken, message.jobId);

    if (job.status !== 'completed' || !job.track) return;

    await rememberActiveTrack(job.track);
    const tab = await browser.tabs.get(tabId).catch(() => undefined);
    const pageStatus = tab ? parseYoutubePage(tab.url ?? '') : undefined;

    if (pageStatus?.supported && pageStatus.videoId === message.youtubeVideoId) {
      await publishSubtitleState(tabId, { type: 'ready', track: job.track });
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
): Promise<boolean> {
  const activeTab = await browser.tabs.get(tabId).catch(() => undefined);

  if (activeTab === undefined) {
    return false;
  }

  const pageStatus = parseYoutubePage(activeTab.url ?? '');
  if (!pageStatus.supported || pageStatus.videoId !== youtubeVideoId) {
    return false;
  }

  const currentState = await getSubtitleStateForPage(tabId, pageStatus);

  return currentState.type === 'ready'
    && currentState.track.jobId === jobId
    && currentState.track.trackId === trackId;
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
  await clearTabOperations();
  tabSubtitleStates.clear();
  tabOperations.clear();
  tombstoneAllLyricsCorrectionStates();

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

  return getPanelState({ syncBackend: true, windowId });
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
  tombstoneAllLyricsCorrectionStates();

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

  let stateForRecovery = localState;
  if (activeTabId !== null && pageStatus?.supported && effectiveSession) {
    const operation = await getTabOperation(activeTabId);

    if (operation?.kind === 'generation' && operation.youtubeVideoId === pageStatus.videoId) {
      if (!operation.jobId) {
        stateForRecovery = {
          type: 'loading',
          youtubeVideoId: pageStatus.videoId,
          youtubeUrl: pageStatus.url,
          message: 'Preparing request...',
          stage: 'preparing',
          progressPercent: 5,
        };
      } else {
        try {
          const job = await subtitleApi.getSubtitleJob(installId, effectiveSession.plainTextToken, operation.jobId);

          if (job.status === 'queued' || job.status === 'running') {
            stateForRecovery = {
              type: 'loading',
              jobId: job.jobId,
              youtubeVideoId: pageStatus.videoId,
              youtubeUrl: pageStatus.url,
              message: job.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(job.stage),
              stage: job.stage,
              progressPercent: job.progressPercent,
              startedAt: job.createdAt,
              lastUpdatedAt: job.updatedAt,
            };
          } else if (job.status === 'completed' && job.track) {
            stateForRecovery = { type: 'ready', track: job.track };
            await rememberActiveTrack(job.track);
            await clearTabOperation(activeTabId);
          } else {
            stateForRecovery = {
              type: 'error',
              jobId: job.jobId,
              youtubeVideoId: pageStatus.videoId,
              message: publicSubtitleJobFailureMessage(job),
            };
            await clearTabOperation(activeTabId);
          }
        } catch {
          stateForRecovery = {
            type: 'loading',
            jobId: operation.jobId,
            youtubeVideoId: pageStatus.videoId,
            youtubeUrl: pageStatus.url,
            message: 'Checking generation status...',
            stage: 'preparing',
            progressPercent: 5,
          };
        }
      }
    }
  }

  let subtitleState = await stateWithBackendProgress(stateForRecovery, pageStatus, history.jobs, (job) =>
    effectiveSession ? resolveCompletedSubtitleJob(installId, effectiveSession.plainTextToken, job) : Promise.resolve(null),
  );
  const lyricsCorrection = await syncLyricsCorrection(activeTabId, pageStatus, effectiveSession, installId, options.syncBackend, stateForRecovery);

  if (activeTabId !== null && pageStatus?.supported) {
    const currentSubtitleState = tabSubtitleStates.get(activeTabId);

    if (currentSubtitleState && currentSubtitleState !== localState && isSubtitleStateForVideo(currentSubtitleState, pageStatus.videoId)) {
      subtitleState = currentSubtitleState;
    }
  }

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
      tombstoneLyricsCorrectionState(tabId);
    }

    return null;
  }

  const persistedOperation = await getTabOperation(tabId);
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
    const status = await syncLyricsCorrectionStatus({
      tabId,
      jobId: trackedJobId,
      syncBackend,
      states: tabLyricsCorrectionStates,
      fetchStatus: () => subtitleApi.getLyricsCorrectionStatus(installId, session.plainTextToken, trackedJobId),
      onCurrentRequestError: (error) => {
        if (error instanceof SubtitleApiError && error.code === 'not_found') {
          tombstoneLyricsCorrectionState(tabId);
        }
      },
    });

    if (status?.status === 'completed' && status.track) {
      const currentSubtitleState = tabSubtitleStates.get(tabId);
      const currentTrack = currentSubtitleState?.type === 'ready' ? currentSubtitleState.track : null;

      if (currentTrack && await activeReadyTrackMatches(tabId, pageStatus.videoId, trackedJobId, currentTrack.trackId)) {
        await publishSubtitleState(tabId, { type: 'ready', track: status.track });
      } else {
        await rememberActiveTrack(status.track);
      }
    }

    if (status && !['queued', 'running'].includes(status.status)) {
      const operation = await getTabOperation(tabId);

      if (operation?.kind === 'correction' && operation.jobId === trackedJobId) {
        await clearTabOperation(tabId);
      }
    }

    return status;
  } catch {
    return tabLyricsCorrectionStates.get(tabId)?.status ?? null;
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
    if (typeof windowId === 'number') {
      const tab = await browser.tabs.get(tabId).catch(() => undefined);
      if (tab?.windowId !== windowId) continue;
    }

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

async function clearSessionIfInvalid(error: unknown): Promise<boolean> {
  if (!isSessionInvalidError(error)) {
    return false;
  }

  await clearExtensionSession();
  tombstoneAllLyricsCorrectionStates();

  return true;
}

function assertNever(value: never): never {
  throw new Error(`Unhandled background request: ${JSON.stringify(value)}`);
}
