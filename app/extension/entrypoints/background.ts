import { browser, type Browser } from 'wxt/browser';

import { SubtitleApiClient } from '../utils/api';
import {
  DEFAULT_SUBTITLE_STATE,
  isRuntimeMessage,
  type PopupState,
  type RuntimeMessage,
  type SubtitleState,
} from '../utils/messages';
import { getExtensionSettings, getOrCreateInstallId, updateExtensionSettings } from '../utils/settings';
import type { ExtensionSettings } from '../utils/settings-model';
import { parseYoutubePage, type YoutubePageInfo } from '../utils/youtube';

const subtitleApi = new SubtitleApiClient();
const tabSubtitleStates = new Map<number, SubtitleState>();

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
    subtitleState: tabId === null ? DEFAULT_SUBTITLE_STATE : getSubtitleStateForPage(tabId, pageStatus),
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

  return getPopupState();
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

    tabSubtitleStates.set(activeTabId, subtitleState);
    void sendTabMessage(activeTabId, {
      type: 'background.subtitleStateChanged',
      subtitleState,
    });

    return getPopupState();
  }

  const installId = await getOrCreateInstallId();
  const settings = await getExtensionSettings();

  try {
    const job = await subtitleApi.createSubtitleJob(installId, {
      youtubeVideoId: pageStatus.videoId,
      youtubeUrl: pageStatus.url,
      sourceLanguage: 'ar',
      targetLanguage: 'en',
      options: {
        includeRomanization: settings.showRomanization,
        includeGloss: settings.showGloss,
      },
    });

    const subtitleState: SubtitleState = {
      type: 'ready',
      track: job.track,
    };

    tabSubtitleStates.set(activeTabId, subtitleState);
    void sendTabMessage(activeTabId, {
      type: 'background.subtitleStateChanged',
      subtitleState,
    });

    return getPopupState();
  } catch (error) {
    const subtitleState: SubtitleState = {
      type: 'error',
      message: error instanceof Error ? error.message : 'Unable to generate subtitles.',
    };

    tabSubtitleStates.set(activeTabId, subtitleState);
    void sendTabMessage(activeTabId, {
      type: 'background.subtitleStateChanged',
      subtitleState,
    });

    return getPopupState();
  }
}

async function getPopupState(): Promise<PopupState> {
  const activeTab = await getActiveTab();
  const activeTabId = activeTab?.id ?? null;
  const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    activeTabId: activeTabId ?? undefined,
    pageStatus,
    subtitleState:
      activeTabId === null || pageStatus === undefined
        ? DEFAULT_SUBTITLE_STATE
        : getSubtitleStateForPage(activeTabId, pageStatus),
  };
}

function getSubtitleStateForPage(tabId: number, pageStatus: YoutubePageInfo): SubtitleState {
  const subtitleState = tabSubtitleStates.get(tabId) ?? DEFAULT_SUBTITLE_STATE;

  if (!pageStatus.supported) {
    return DEFAULT_SUBTITLE_STATE;
  }

  if (subtitleState.type !== 'ready') {
    return DEFAULT_SUBTITLE_STATE;
  }

  if (subtitleState.track.youtubeVideoId !== pageStatus.videoId) {
    return DEFAULT_SUBTITLE_STATE;
  }

  return subtitleState;
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
