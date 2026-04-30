import { browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, createExtensionSettingsFromPartial } from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type ContentPageStatus, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { findActiveVideoElement } from '../utils/video';
import { parseYoutubePage } from '../utils/youtube';

const YOUTUBE_ROUTE_EVENTS = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];
const VIDEO_STATE_EVENTS = ['loadedmetadata', 'durationchange', 'emptied', 'play', 'pause', 'seeked'];

export default defineContentScript({
  matches: ['*://*.youtube.com/watch*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let subtitleState: SubtitleState = DEFAULT_SUBTITLE_STATE;
    let activeVideoElement: HTMLVideoElement | null = null;
    let unbindVideoListeners: (() => void) | null = null;
    let lastStatusKey = '';
    let disposed = false;

    const overlay = new OverlayShell(document);

    function syncPageState(): void {
      if (disposed) {
        return;
      }

      const status = buildStatus();

      overlay.update({
        page: status.page,
        videoElementFound: status.videoElementFound,
        subtitleState,
        settings,
      });

      publishStatus(status);
    }

    function buildStatus(): ContentPageStatus {
      const page = parseYoutubePage(window.location.href);
      const video = page.supported ? findActiveVideoElement(document) : null;

      trackVideoElement(video);

      return {
        page,
        videoElementFound: Boolean(activeVideoElement),
        videoDurationSeconds:
          activeVideoElement && Number.isFinite(activeVideoElement.duration) ? activeVideoElement.duration : undefined,
        videoCurrentTimeSeconds: activeVideoElement?.currentTime,
        updatedAt: Date.now(),
      };
    }

    function trackVideoElement(video: HTMLVideoElement | null): void {
      if (video === activeVideoElement) {
        return;
      }

      unbindVideoListeners?.();
      activeVideoElement = video;
      unbindVideoListeners = video ? bindVideoElement(video, syncPageState) : null;
    }

    function publishStatus(status: ContentPageStatus): void {
      const statusKey = JSON.stringify({
        page: status.page,
        videoElementFound: status.videoElementFound,
        videoDurationSeconds: status.videoDurationSeconds,
      });

      if (statusKey === lastStatusKey) {
        return;
      }

      lastStatusKey = statusKey;
      browser.runtime.sendMessage({ type: 'content.statusChanged', status }).catch(() => {
        // The service worker can be unavailable during reloads. The next state check will retry.
      });
    }

    const stopRouteObserver = observeYoutubeRouteChanges(syncPageState);

    browser.runtime.onMessage.addListener((message, _sender, sendResponse) => {
      if (!isRuntimeMessage(message)) {
        return false;
      }

      if (message.type === 'background.settingsChanged') {
        settings = createExtensionSettingsFromPartial(message.settings);
        syncPageState();
        sendResponse({ ok: true });

        return false;
      }

      if (message.type === 'background.subtitleStateChanged') {
        subtitleState = message.subtitleState;
        syncPageState();
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

        syncPageState();
      })
      .catch(() => {
        syncPageState();
      });

    syncPageState();

    ctx.onInvalidated(() => {
      disposed = true;
      stopRouteObserver();
      unbindVideoListeners?.();
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

function bindVideoElement(video: HTMLVideoElement, callback: () => void): () => void {
  for (const eventName of VIDEO_STATE_EVENTS) {
    video.addEventListener(eventName, callback);
  }

  return () => {
    for (const eventName of VIDEO_STATE_EVENTS) {
      video.removeEventListener(eventName, callback);
    }
  };
}
