import { browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, createExtensionSettingsFromPartial } from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { parseYoutubePage } from '../utils/youtube';

const YOUTUBE_ROUTE_EVENTS = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];

export default defineContentScript({
  matches: ['*://*.youtube.com/watch*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let subtitleState: SubtitleState = DEFAULT_SUBTITLE_STATE;
    let disposed = false;

    const overlay = new OverlayShell(document);

    function updateOverlay(): void {
      if (disposed) {
        return;
      }

      overlay.update({
        page: parseYoutubePage(window.location.href),
        subtitleState,
        settings,
      });
    }

    function resetOverlayForRouteChange(): void {
      subtitleState = DEFAULT_SUBTITLE_STATE;
      updateOverlay();
    }

    const stopRouteObserver = observeYoutubeRouteChanges(resetOverlayForRouteChange);

    browser.runtime.onMessage.addListener((message, _sender, sendResponse) => {
      if (!isRuntimeMessage(message)) {
        return false;
      }

      if (message.type === 'background.settingsChanged') {
        settings = createExtensionSettingsFromPartial(message.settings);
        updateOverlay();
        sendResponse({ ok: true });

        return false;
      }

      if (message.type === 'background.subtitleStateChanged') {
        subtitleState = message.subtitleState;
        updateOverlay();
        sendResponse({ ok: true });

        return false;
      }

      return false;
    });

    browser.runtime
      .sendMessage({ type: 'content.getState' })
      .then((state) => {
        if (state?.settings) {
          settings = createExtensionSettingsFromPartial(state.settings);
        }

        if (state?.subtitleState) {
          subtitleState = state.subtitleState;
        }

        updateOverlay();
      })
      .catch(() => {
        updateOverlay();
      });

    updateOverlay();

    ctx.onInvalidated(() => {
      disposed = true;
      stopRouteObserver();
      overlay.unmount();
    });
  },
});

function observeYoutubeRouteChanges(callback: () => void): () => void {
  for (const eventName of YOUTUBE_ROUTE_EVENTS) {
    window.addEventListener(eventName, callback);
  }

  return () => {
    for (const eventName of YOUTUBE_ROUTE_EVENTS) {
      window.removeEventListener(eventName, callback);
    }
  };
}
