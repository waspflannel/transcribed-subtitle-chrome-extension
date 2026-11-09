import { browser } from 'wxt/browser';
import { clearTabOperation } from '../utils/active-tracks';
import { SubtitleApiError } from '../utils/api';
import { runtime } from '../utils/background/runtime';
import { handleRuntimeMessage } from '../utils/background/state';
import { isBackgroundRequest,isRuntimeMessage } from '../utils/messages';

export default defineBackground(() => {
    // Remove credentials left by upgrades from the retired account-based release.
    void browser.storage.local.remove('extensionSession');
    // Chrome: clicking the toolbar action opens the side panel. No-op where the API is
    // absent (e.g. Firefox, which uses its native sidebar button).
    const actionSidePanel = (browser as unknown as {
        sidePanel?: {
            setPanelBehavior(options: {
                openPanelOnActionClick: boolean;
            }): Promise<void>;
        };
    }).sidePanel;
    void actionSidePanel?.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => { });
    browser.runtime.onConnect.addListener((port) => {
        if (port.name === 'panel') {
            runtime.panelPorts.add(port);
            port.onDisconnect.addListener(() => runtime.panelPorts.delete(port));
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
        runtime.tabOperations.delete(tabId);
        runtime.tabSubtitleStates.delete(tabId);
        runtime.tabSubtitleStateSessions.delete(tabId);
        runtime.tabLyricsCorrectionStates.delete(tabId);
        runtime.tabLyricsCorrectionStateSessions.delete(tabId);
        runtime.tabGenerationInFlight.delete(tabId);
        runtime.tabGenerationCancellationInFlight.delete(tabId);
        runtime.tabCorrectionMutationInFlight.delete(tabId);
        void clearTabOperation(tabId);
    });
});
