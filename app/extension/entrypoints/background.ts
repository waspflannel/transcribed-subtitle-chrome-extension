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
import type { ExtensionSettings } from '../utils/settings-model';

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

    case 'popup.setOverlayMode':
      return setOverlayModeFromPopup(message.mode);

    default:
      return { ok: false, error: 'Unhandled extension message' };
  }
}

function saveContentStatus(sender: Browser.runtime.MessageSender, status: ContentPageStatus): void {
  const tabId = tabIdFromSender(sender);

  if (tabId === null) {
    return;
  }

  tabStatuses.set(tabId, status);
  ensureOverlayMode(tabId);
}

async function getContentState(sender: Browser.runtime.MessageSender): Promise<{
  installId: string;
  settings: ExtensionSettings;
  overlayMode: OverlayMode;
}> {
  const tabId = tabIdFromSender(sender);

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    overlayMode: tabId === null ? DEFAULT_OVERLAY_MODE : ensureOverlayMode(tabId),
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

async function setOverlayModeFromPopup(mode: unknown): Promise<PopupState> {
  const activeTabId = await getActiveTabId();

  if (activeTabId !== null && isOverlayMode(mode)) {
    tabOverlayModes.set(activeTabId, mode);
    await sendTabMessage(activeTabId, {
      type: 'background.overlayModeChanged',
      mode,
    });
  }

  return getPopupState();
}

async function getPopupState(): Promise<PopupState> {
  const activeTabId = await getActiveTabId();

  return {
    installId: await getOrCreateInstallId(),
    settings: await getExtensionSettings(),
    activeTabId: activeTabId ?? undefined,
    pageStatus: activeTabId === null ? undefined : tabStatuses.get(activeTabId),
    overlayMode: activeTabId === null ? DEFAULT_OVERLAY_MODE : ensureOverlayMode(activeTabId),
  };
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
