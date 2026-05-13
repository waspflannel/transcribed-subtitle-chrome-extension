import { browser, type Browser } from 'wxt/browser';

import { SubtitleApiClient, publicSubtitleErrorMessage, SubtitleApiError } from '../utils/api';
import { clearRememberedTracks, getRememberedTrack, rememberActiveTrack } from '../utils/active-tracks';
import type { SubtitleJobHistoryItem, TrackResponse } from '../utils/contracts';
import {
  DEFAULT_SUBTITLE_STATE,
  isRuntimeMessage,
  type PopupState,
  type RuntimeMessage,
  type SubtitleState,
} from '../utils/messages';
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
const ESTIMATED_PROGRESS_INTERVAL_MS = 4000;
type SupportedYoutubePageInfo = Extract<YoutubePageInfo, { supported: true }>;
type LoadingStage = NonNullable<SubtitleJobHistoryItem['stage']>;

export default defineBackground(() => {
  browser.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!isRuntimeMessage(message)) {
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
    tabSubtitleStates.delete(tabId);
  });
});

async function handleRuntimeMessage(message: RuntimeMessage, sender: Browser.runtime.MessageSender): Promise<unknown> {
  switch (message.type) {
    case 'content.getState':
      return getContentState(sender);

    case 'content.enrichLearningToken':
      return enrichLearningTokenFromContent(message, sender);

    case 'popup.getState':
      return getPopupState({ syncBackend: message.syncBackend ?? true });

    case 'popup.updateSettings':
      return updateSettingsFromPopup(message.patch);

    case 'popup.generateSubtitles':
      return generateSubtitlesFromPopup();

    case 'popup.clearLocalState':
      return clearLocalStateFromPopup();
  }
}

async function getContentState(sender: Browser.runtime.MessageSender): Promise<{
  installId: string;
  settings: ExtensionSettings;
  subtitleState: SubtitleState;
}> {
  const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;
  const pageStatus = parseYoutubePage(sender.tab?.url ?? '');

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    subtitleState: tabId === null ? DEFAULT_SUBTITLE_STATE : await getSubtitleStateForPage(tabId, pageStatus),
  };
}

async function updateSettingsFromPopup(patch: Partial<ExtensionSettings>): Promise<PopupState> {
  const settings = await updateExtensionSettings(patch);
  const activeTab = await getActiveTab();
  const activeTabId = activeTab?.id ?? null;

  if (activeTabId !== null) {
    await sendTabMessage(activeTabId, {
      type: 'background.settingsChanged',
      settings,
    });
  }

  return getPopupState({ syncBackend: true });
}

async function generateSubtitlesFromPopup(): Promise<PopupState> {
  const activeTab = await getActiveTab();
  const activeTabId = activeTab?.id ?? null;

  if (activeTabId === null) {
    throw new Error('Open a YouTube watch tab before generating subtitles.');
  }

  const pageStatus = parseYoutubePage(activeTab?.url ?? '');

  if (!pageStatus.supported) {
    const subtitleState: SubtitleState = {
      type: 'error',
      message: 'Open a supported YouTube watch page before generating subtitles.',
    };

    await publishSubtitleState(activeTabId, subtitleState);

    return getPopupState({ syncBackend: true });
  }

  const currentState = await getSubtitleStateForPage(activeTabId, pageStatus);

  if (currentState.type !== 'loading') {
    const settings = await getExtensionSettings();
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

    void generateSubtitlesForTab(activeTabId, pageStatus, settings);
  }

  return getPopupState({ syncBackend: false });
}

async function generateSubtitlesForTab(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  settings: ExtensionSettings,
): Promise<void> {
  const stopEstimatedProgress = startEstimatedProgress(
    tabId,
    pageStatus,
    settings.fullTrackEnrichment,
    settings.showRomanization,
  );

  try {
    console.info('extension.subtitle_generation_started', {
      youtubeVideoId: pageStatus.videoId,
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      enrichmentMode: settings.fullTrackEnrichment ? 'full' : 'on_demand',
      includeRomanization: settings.showRomanization,
    });

    const installId = await getOrCreateInstallId();
    const job = await subtitleApi.createSubtitleJob(installId, {
      youtubeVideoId: pageStatus.videoId,
      youtubeUrl: pageStatus.url,
      sourceLanguage: settings.sourceLanguage,
      targetLanguage: settings.targetLanguage,
      enrichmentMode: settings.fullTrackEnrichment ? 'full' : 'on_demand',
      includeRomanization: settings.showRomanization,
    });

    if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
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
    console.warn('extension.subtitle_generation_failed', {
      youtubeVideoId: pageStatus.videoId,
      errorCode: error instanceof SubtitleApiError ? error.code : 'extension_error',
      status: error instanceof SubtitleApiError ? error.status : undefined,
    });

    if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
      await publishSubtitleState(tabId, {
        type: 'error',
        youtubeVideoId: pageStatus.videoId,
        message: publicSubtitleErrorMessage(error),
      });
    }
  } finally {
    stopEstimatedProgress();
  }
}

async function enrichLearningTokenFromContent(
  message: Extract<RuntimeMessage, { type: 'content.enrichLearningToken' }>,
  sender: Browser.runtime.MessageSender,
): Promise<unknown> {
  const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;

  if (tabId === null) {
    throw new Error('Learning token enrichment requires an active content tab.');
  }

  const currentState = await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId);

  if (!currentState) {
    throw new Error('No generated subtitle track is active for this tab.');
  }

  const installId = await getOrCreateInstallId();
  const response = await subtitleApi.enrichLearningToken(installId, {
    trackId: message.trackId,
    cueId: message.cueId,
    tokenIndex: message.tokenIndex,
  });
  const track = patchActiveTrack(currentState.track, response.cueId, response.token);

  await publishSubtitleState(tabId, {
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

async function clearLocalStateFromPopup(): Promise<PopupState> {
  await clearLocalExtensionState();
  await clearRememberedTracks();
  tabSubtitleStates.clear();

  const activeTab = await getActiveTab();
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

  return getPopupState({ syncBackend: true });
}

async function getPopupState(options: { syncBackend: boolean }): Promise<PopupState> {
  const activeTab = await getActiveTab();
  const activeTabId = activeTab?.id ?? null;
  const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;
  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();
  const { jobs, error } = options.syncBackend
    ? await listBackendJobHistory(installId)
    : { jobs: [] as SubtitleJobHistoryItem[], error: undefined };
  const localState =
    activeTabId === null || pageStatus === undefined
      ? DEFAULT_SUBTITLE_STATE
      : await getSubtitleStateForPage(activeTabId, pageStatus);

  return {
    installId,
    settings,
    activeTabId: activeTabId ?? undefined,
    pageStatus,
    subtitleState: stateWithBackendProgress(localState, pageStatus, jobs),
    jobHistory: jobs,
    jobHistoryError: error,
  };
}

async function listBackendJobHistory(
  installId: string,
): Promise<{ jobs: SubtitleJobHistoryItem[]; error?: string }> {
  try {
    const response = await subtitleApi.listSubtitleJobs(installId);

    return { jobs: response.jobs };
  } catch (error) {
    return {
      jobs: [],
      error: error instanceof Error ? error.message : 'Unable to load backend job history.',
    };
  }
}

function stateWithBackendProgress(
  localState: SubtitleState,
  pageStatus: YoutubePageInfo | undefined,
  jobs: SubtitleJobHistoryItem[],
): SubtitleState {
  if (!pageStatus?.supported) {
    return localState;
  }

  if (localState.type === 'ready' || localState.type === 'error') {
    return localState;
  }

  const job = jobs.find((candidate) => candidate.youtubeVideoId === pageStatus.videoId && candidate.status !== 'completed');

  if (!job) {
    return localState;
  }

  if (job.status === 'failed') {
    return {
      type: 'error',
      youtubeVideoId: job.youtubeVideoId,
      message: job.message ?? 'Generation did not complete.',
    };
  }

  return {
    type: 'loading',
    youtubeVideoId: job.youtubeVideoId,
    youtubeUrl: job.youtubeUrl,
    message: loadingMessageForStage(job.stage),
    stage: job.stage,
    progressPercent: job.progressPercent,
    startedAt: job.startedAt,
    lastUpdatedAt: job.lastUpdatedAt,
  };
}

function loadingMessageForStage(stage: SubtitleJobHistoryItem['stage']): string {
  switch (stage) {
    case 'acquiring-audio':
      return 'Acquiring audio...';

    case 'transcribing':
      return 'Transcribing audio...';

    case 'tokenizing':
      return 'Tokenizing subtitles...';

    case 'romanizing':
      return 'Adding romanization...';

    case 'enriching':
      return 'Generating word cards...';

    case 'finalizing':
      return 'Finalizing track...';

    case 'preparing':
    default:
      return 'Preparing request...';
  }
}

function startEstimatedProgress(
  tabId: number,
  pageStatus: SupportedYoutubePageInfo,
  fullTrackEnrichment: boolean,
  includeRomanization: boolean,
): () => void {
  const startedAtMs = Date.now();
  let publishing = false;

  const timerId = globalThis.setInterval(() => {
    const currentState = tabSubtitleStates.get(tabId);

    if (currentState?.type !== 'loading' || currentState.youtubeVideoId !== pageStatus.videoId) {
      globalThis.clearInterval(timerId);

      return;
    }

    const estimate = estimatedGenerationProgress(Date.now() - startedAtMs, fullTrackEnrichment, includeRomanization);

    if (
      publishing ||
      (currentState.stage === estimate.stage && (currentState.progressPercent ?? 0) >= estimate.progressPercent)
    ) {
      return;
    }

    publishing = true;

    void publishSubtitleState(tabId, {
      ...currentState,
      message: loadingMessageForStage(estimate.stage),
      stage: estimate.stage,
      progressPercent: Math.max(currentState.progressPercent ?? 0, estimate.progressPercent),
      lastUpdatedAt: new Date().toISOString(),
    }).finally(() => {
      publishing = false;
    });
  }, ESTIMATED_PROGRESS_INTERVAL_MS);

  return () => globalThis.clearInterval(timerId);
}

function estimatedGenerationProgress(
  elapsedMs: number,
  fullTrackEnrichment: boolean,
  includeRomanization: boolean,
): { stage: LoadingStage; progressPercent: number } {
  const elapsedSeconds = elapsedMs / 1000;

  if (elapsedSeconds < 4) {
    return { stage: 'preparing', progressPercent: 5 };
  }

  if (elapsedSeconds < 14) {
    return {
      stage: 'acquiring-audio',
      progressPercent: interpolateProgress(elapsedSeconds, 4, 14, 12, 25),
    };
  }

  if (elapsedSeconds < 45) {
    return {
      stage: 'transcribing',
      progressPercent: interpolateProgress(elapsedSeconds, 14, 45, 25, 65),
    };
  }

  if (elapsedSeconds < 70) {
    return {
      stage: 'tokenizing',
      progressPercent: interpolateProgress(elapsedSeconds, 45, 70, 65, 78),
    };
  }

  if (includeRomanization && elapsedSeconds < 100) {
    return {
      stage: 'romanizing',
      progressPercent: interpolateProgress(elapsedSeconds, 70, 100, 78, 85),
    };
  }

  const enrichmentEndSeconds = fullTrackEnrichment ? 180 : 120;

  if (elapsedSeconds < enrichmentEndSeconds) {
    return {
      stage: fullTrackEnrichment ? 'enriching' : 'finalizing',
      progressPercent: interpolateProgress(elapsedSeconds, includeRomanization ? 100 : 70, enrichmentEndSeconds, 85, 92),
    };
  }

  return { stage: 'finalizing', progressPercent: 95 };
}

function interpolateProgress(
  value: number,
  inputMin: number,
  inputMax: number,
  outputMin: number,
  outputMax: number,
): number {
  const ratio = Math.max(0, Math.min(1, (value - inputMin) / (inputMax - inputMin)));

  return Math.round(outputMin + (outputMax - outputMin) * ratio);
}

function patchActiveTrack(track: TrackResponse, cueId: string, token: Parameters<typeof trackWithLearningToken>[2]): TrackResponse {
  return trackWithLearningToken(track, cueId, token);
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

async function getActiveTab(): Promise<Browser.tabs.Tab | undefined> {
  const [activeTab] = await browser.tabs.query({
    active: true,
    currentWindow: true,
  });

  return activeTab;
}

async function sendTabMessage(tabId: number, message: RuntimeMessage): Promise<void> {
  try {
    await browser.tabs.sendMessage(tabId, message);
  } catch {
    // Unsupported pages do not have this content script; popup state still updates locally.
  }
}
