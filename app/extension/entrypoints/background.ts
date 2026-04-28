import { browser, type Browser } from 'wxt/browser';

import {
  DEFAULT_OVERLAY_MODE,
  isOverlayMode,
  isRuntimeMessage,
  type ContentPageStatus,
  type OverlayMode,
  type PopupState,
  type RuntimeMessage,
} from '../utils/messages';
import { getExtensionSettings, getOrCreateInstallId, updateExtensionSettings } from '../utils/settings';

const tabStatuses = new Map<number, ContentPageStatus>();
const tabOverlayModes = new Map<number, OverlayMode>();

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
    tabOverlayModes.delete(tabId);
  });
});

async function handleRuntimeMessage(
  message: RuntimeMessage,
  sender: Browser.runtime.MessageSender,
): Promise<unknown> {
  if (message.type === 'content.statusChanged') {
    const tabId = sender.tab?.id;

    if (typeof tabId === 'number') {
      tabStatuses.set(tabId, message.status);
      ensureOverlayMode(tabId);
    }

    return { ok: true };
  }

  if (message.type === 'content.getState') {
    const tabId = sender.tab?.id;

    return {
      installId: await getOrCreateInstallId(),
      settings: await getExtensionSettings(),
      overlayMode: typeof tabId === 'number' ? ensureOverlayMode(tabId) : DEFAULT_OVERLAY_MODE,
    };
  }

  if (message.type === 'popup.getState') {
    return getPopupState();
  }

  if (message.type === 'popup.updateSettings') {
    const settings = await updateExtensionSettings(message.patch);
    const activeTab = await getActiveTab();

    if (typeof activeTab?.id === 'number') {
      await sendTabMessage(activeTab.id, {
        type: 'background.settingsChanged',
        settings,
      });
    }

    return getPopupState();
  }

  if (message.type === 'popup.setOverlayMode') {
    const activeTab = await getActiveTab();

    if (typeof activeTab?.id === 'number' && isOverlayMode(message.mode)) {
      tabOverlayModes.set(activeTab.id, message.mode);
      await sendTabMessage(activeTab.id, {
        type: 'background.overlayModeChanged',
        mode: message.mode,
      });
    }

    return getPopupState();
  }

  return { ok: false, error: 'Unhandled extension message' };
}

async function getPopupState(): Promise<PopupState> {
  const activeTab = await getActiveTab();
  const activeTabId = activeTab?.id;

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    activeTabId,
    pageStatus: typeof activeTabId === 'number' ? tabStatuses.get(activeTabId) : undefined,
    overlayMode: typeof activeTabId === 'number' ? ensureOverlayMode(activeTabId) : DEFAULT_OVERLAY_MODE,
  };
}

async function getActiveTab(): Promise<Browser.tabs.Tab | undefined> {
  const [activeTab] = await browser.tabs.query({
    active: true,
    currentWindow: true,
  });

  return activeTab;
}

function ensureOverlayMode(tabId: number): OverlayMode {
  const existingMode = tabOverlayModes.get(tabId);

  if (existingMode) {
    return existingMode;
  }

  tabOverlayModes.set(tabId, DEFAULT_OVERLAY_MODE);

  return DEFAULT_OVERLAY_MODE;
}

async function sendTabMessage(tabId: number, message: RuntimeMessage): Promise<void> {
  try {
    await browser.tabs.sendMessage(tabId, message);
  } catch {
    // Unsupported pages do not have this content script; popup state still updates locally.
  }
}
