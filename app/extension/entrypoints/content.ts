import { browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, normalizeExtensionSettings } from '../utils/settings-model';
import { DEFAULT_OVERLAY_MODE, isOverlayMode, isRuntimeMessage, type ContentPageStatus } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { findActiveVideoElement } from '../utils/video';
import { parseYoutubePage } from '../utils/youtube';

export default defineContentScript({
  matches: ['*://*.youtube.com/watch*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let overlayMode = DEFAULT_OVERLAY_MODE;
    let activeVideoElement: HTMLVideoElement | null = null;
    let cleanupVideoListeners: (() => void) | null = null;
    let lastStatusKey = '';
    let disposed = false;

    const overlay = new OverlayShell(document);

    const syncPageState = () => {
      if (disposed) {
        return;
      }

      const status = buildStatus();

      overlay.update({
        page: status.page,
        videoElementFound: status.videoElementFound,
        mode: overlayMode,
        settings,
      });

      publishStatus(status);
    };

    const buildStatus = (): ContentPageStatus => {
      const page = parseYoutubePage(window.location.href);
      const video = page.supported ? findActiveVideoElement(document) : null;

      if (video !== activeVideoElement) {
        cleanupVideoListeners?.();
        activeVideoElement = video;
        cleanupVideoListeners = video ? bindVideoElement(video, syncPageState) : null;
      }

      return {
        page,
        videoElementFound: Boolean(activeVideoElement),
        videoDurationSeconds:
          activeVideoElement && Number.isFinite(activeVideoElement.duration) ? activeVideoElement.duration : undefined,
        videoCurrentTimeSeconds: activeVideoElement?.currentTime,
        updatedAt: Date.now(),
      };
    };

    const publishStatus = (status: ReturnType<typeof buildStatus>) => {
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
    };

    const stopRouteObserver = observeYoutubeRouteChanges(syncPageState);

    browser.runtime.onMessage.addListener((message, _sender, sendResponse) => {
      if (!isRuntimeMessage(message)) {
        return false;
      }

      if (message.type === 'background.settingsChanged') {
        settings = normalizeExtensionSettings(message.settings);
        syncPageState();
        sendResponse({ ok: true });

        return false;
      }

      if (message.type === 'background.overlayModeChanged') {
        if (isOverlayMode(message.mode)) {
          overlayMode = message.mode;
          syncPageState();
        }

        sendResponse({ ok: true });

        return false;
      }

      return false;
    });

    browser.runtime
      .sendMessage({ type: 'content.getState' })
      .then((state) => {
        if (state?.settings) {
          settings = normalizeExtensionSettings(state.settings);
        }

        if (isOverlayMode(state?.overlayMode)) {
          overlayMode = state.overlayMode;
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
      cleanupVideoListeners?.();
      overlay.unmount();
    });
  },
});

function observeYoutubeRouteChanges(callback: () => void): () => void {
  let lastHref = window.location.href;
  let lastHadVideo = Boolean(document.querySelector('video'));
  let debounceId: number | undefined;

  const schedule = () => {
    window.clearTimeout(debounceId);
    debounceId = window.setTimeout(() => {
      const hrefChanged = window.location.href !== lastHref;
      const hasVideo = Boolean(document.querySelector('video'));
      const videoPresenceChanged = hasVideo !== lastHadVideo;

      lastHref = window.location.href;
      lastHadVideo = hasVideo;

      if (hrefChanged || videoPresenceChanged || !hasVideo) {
        callback();
      }
    }, 150);
  };

  const events = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];

  for (const eventName of events) {
    window.addEventListener(eventName, schedule);
  }

  const observer = new MutationObserver(schedule);
  observer.observe(document.body ?? document.documentElement, {
    childList: true,
    subtree: true,
  });

  const intervalId = window.setInterval(schedule, 1_000);

  return () => {
    window.clearTimeout(debounceId);
    window.clearInterval(intervalId);
    observer.disconnect();

    for (const eventName of events) {
      window.removeEventListener(eventName, schedule);
    }
  };
}

function bindVideoElement(video: HTMLVideoElement, callback: () => void): () => void {
  const events = ['loadedmetadata', 'durationchange', 'emptied', 'play', 'pause', 'seeked'];

  for (const eventName of events) {
    video.addEventListener(eventName, callback);
  }

  return () => {
    for (const eventName of events) {
      video.removeEventListener(eventName, callback);
    }
  };
}
