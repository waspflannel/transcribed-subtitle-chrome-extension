import { browser, type Browser } from 'wxt/browser';

import { SubtitleApiClient } from '../utils/api';
import type { CreateSubtitleJobRequest, JobResponse } from '../utils/contracts';
import {
  DEFAULT_SUBTITLE_STATE,
  isRuntimeMessage,
  type ContentPageStatus,
  type PopupState,
  type RuntimeMessage,
  type SubtitleState,
} from '../utils/messages';
import { getExtensionSettings, getOrCreateInstallId, updateExtensionSettings } from '../utils/settings';
import type { ExtensionSettings } from '../utils/settings-model';

const JOB_POLL_INTERVAL_MS = 1500;

const subtitleApi = new SubtitleApiClient();
const tabStatuses = new Map<number, ContentPageStatus>();
const tabSubtitleStates = new Map<number, SubtitleState>();
const tabPollingTimeouts = new Map<number, ReturnType<typeof globalThis.setTimeout>>();

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
    tabStatuses.delete(tabId);
    tabSubtitleStates.delete(tabId);
    stopPolling(tabId);
  });
});

async function handleRuntimeMessage(message: RuntimeMessage, sender: Browser.runtime.MessageSender): Promise<unknown> {
  switch (message.type) {
    case 'content.statusChanged':
      saveContentStatus(sender, message.status);
      return { ok: true };

    case 'content.getState':
      return getContentState(sender);

    case 'popup.getState':
      return getPopupState();

    case 'popup.updateSettings':
      return updateSettingsFromPopup(message.patch);

    case 'popup.generateSubtitles':
      return generateSubtitlesFromPopup();

    default:
      return { ok: false, error: 'Unhandled extension message' };
  }
}

function saveContentStatus(sender: Browser.runtime.MessageSender, status: ContentPageStatus): void {
  const tabId = tabIdFromSender(sender);

  if (tabId === null) {
    return;
  }

  const previousStatus = tabStatuses.get(tabId);
  tabStatuses.set(tabId, status);

  if (didPageVideoChange(previousStatus, status)) {
    stopPolling(tabId);
    saveSubtitleState(tabId, DEFAULT_SUBTITLE_STATE);
  }
}

async function getContentState(sender: Browser.runtime.MessageSender): Promise<{
  installId: string;
  settings: ExtensionSettings;
  subtitleState: SubtitleState;
}> {
  const tabId = tabIdFromSender(sender);

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    subtitleState: tabId === null ? DEFAULT_SUBTITLE_STATE : getSubtitleState(tabId),
  };
}

async function updateSettingsFromPopup(patch: Partial<ExtensionSettings>): Promise<PopupState> {
  const settings = await updateExtensionSettings(patch);
  const activeTabId = await getActiveTabId();

  if (activeTabId !== null) {
    await sendTabMessage(activeTabId, {
      type: 'background.settingsChanged',
      settings,
    });
  }

  return getPopupState();
}

async function generateSubtitlesFromPopup(): Promise<PopupState> {
  const activeTabId = await getActiveTabId();

  if (activeTabId === null) {
    throw new Error('Open a YouTube watch tab before generating subtitles.');
  }

  const pageStatus = tabStatuses.get(activeTabId);

  if (!pageStatus?.page.supported) {
    const subtitleState: SubtitleState = {
      type: 'error',
      message: 'Open a supported YouTube watch page before generating subtitles.',
    };

    saveSubtitleState(activeTabId, subtitleState);

    return getPopupState();
  }

  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();

  try {
    const request = createSubtitleJobRequest(pageStatus, settings);
    const lookup = await subtitleApi.lookupSubtitleTrack(installId, request);

    if (lookup.found && lookup.trackId) {
      saveSubtitleState(activeTabId, {
        type: 'ready',
        track: await subtitleApi.getSubtitleTrack(installId, lookup.trackId),
      });

      return getPopupState();
    }

    const job = await subtitleApi.createSubtitleJob(installId, request);
    await applyJobState(activeTabId, installId, job);

    return getPopupState();
  } catch (error) {
    saveSubtitleState(activeTabId, {
      type: 'error',
      message: error instanceof Error ? error.message : 'Unable to generate subtitles.',
    });

    return getPopupState();
  }
}

async function applyJobState(tabId: number, installId: string, job: JobResponse): Promise<void> {
  const pageStatus = tabStatuses.get(tabId);

  if (!pageStatus?.page.supported || pageStatus.page.videoId !== job.youtubeVideoId) {
    stopPolling(tabId);

    return;
  }

  if (job.status === 'completed' && job.trackId) {
    saveSubtitleState(tabId, {
      type: 'ready',
      track: await subtitleApi.getSubtitleTrack(installId, job.trackId),
    });
    stopPolling(tabId);

    return;
  }

  if (job.status === 'failed' || job.status === 'expired') {
    saveSubtitleState(tabId, {
      type: 'error',
      message: job.error?.message ?? `Subtitle job ${job.status}.`,
      job,
    });
    stopPolling(tabId);

    return;
  }

  saveSubtitleState(tabId, { type: 'processing', job });
  startPolling(tabId, installId, job.jobId);
}

function startPolling(tabId: number, installId: string, jobId: string): void {
  stopPolling(tabId);

  const poll = async (): Promise<void> => {
    try {
      await applyJobState(tabId, installId, await subtitleApi.getSubtitleJob(installId, jobId));
    } catch (error) {
      saveSubtitleState(tabId, {
        type: 'error',
        message: error instanceof Error ? error.message : 'Unable to check subtitle job status.',
      });
      stopPolling(tabId);
    }
  };

  tabPollingTimeouts.set(
    tabId,
    globalThis.setTimeout(() => void poll(), JOB_POLL_INTERVAL_MS),
  );
}

function stopPolling(tabId: number): void {
  const timeoutId = tabPollingTimeouts.get(tabId);

  if (timeoutId !== undefined) {
    globalThis.clearTimeout(timeoutId);
    tabPollingTimeouts.delete(tabId);
  }
}

async function getPopupState(): Promise<PopupState> {
  const activeTabId = await getActiveTabId();

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    activeTabId: activeTabId ?? undefined,
    pageStatus: activeTabId === null ? undefined : tabStatuses.get(activeTabId),
    subtitleState: activeTabId === null ? DEFAULT_SUBTITLE_STATE : getSubtitleState(activeTabId),
  };
}

function createSubtitleJobRequest(
  pageStatus: ContentPageStatus,
  settings: ExtensionSettings,
): CreateSubtitleJobRequest {
  if (!pageStatus.page.supported) {
    throw new Error('Cannot create a subtitle job for an unsupported page.');
  }

  const request: CreateSubtitleJobRequest = {
    youtubeVideoId: pageStatus.page.videoId,
    youtubeUrl: pageStatus.page.url,
    sourceLanguage: 'ar',
    targetLanguage: 'en',
    options: {
      includeRomanization: settings.showRomanization,
      includeGloss: settings.showGloss,
    },
  };

  if (typeof pageStatus.videoDurationSeconds === 'number' && Number.isFinite(pageStatus.videoDurationSeconds)) {
    request.videoDurationSeconds = Math.max(1, Math.round(pageStatus.videoDurationSeconds));
  }

  return request;
}

function saveSubtitleState(tabId: number, subtitleState: SubtitleState): void {
  tabSubtitleStates.set(tabId, subtitleState);
  void sendTabMessage(tabId, {
    type: 'background.subtitleStateChanged',
    subtitleState,
  });
}

function getSubtitleState(tabId: number): SubtitleState {
  return tabSubtitleStates.get(tabId) ?? DEFAULT_SUBTITLE_STATE;
}

function didPageVideoChange(previousStatus: ContentPageStatus | undefined, nextStatus: ContentPageStatus): boolean {
  const previousVideoId = previousStatus?.page.supported ? previousStatus.page.videoId : null;
  const nextVideoId = nextStatus.page.supported ? nextStatus.page.videoId : null;

  return previousVideoId !== nextVideoId;
}

async function getActiveTabId(): Promise<number | null> {
  return tabIdFromTab(await getActiveTab());
}

async function getActiveTab(): Promise<Browser.tabs.Tab | undefined> {
  const [activeTab] = await browser.tabs.query({
    active: true,
    currentWindow: true,
  });

  return activeTab;
}

function tabIdFromSender(sender: Browser.runtime.MessageSender): number | null {
  return typeof sender.tab?.id === 'number' ? sender.tab.id : null;
}

function tabIdFromTab(tab: Browser.tabs.Tab | undefined): number | null {
  return typeof tab?.id === 'number' ? tab.id : null;
}

async function sendTabMessage(tabId: number, message: RuntimeMessage): Promise<void> {
  try {
    await browser.tabs.sendMessage(tabId, message);
  } catch {
    // Unsupported pages do not have this content script; popup state still updates locally.
  }
}
