import { browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, createExtensionSettingsFromPartial } from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { bindSubtitleTrackToVideo, type SubtitleSyncDiagnostic } from '../utils/subtitle-sync';
import type { SubtitleCue, TrackResponse } from '../utils/contracts';
import { parseYoutubePage } from '../utils/youtube';

const YOUTUBE_ROUTE_EVENTS = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];

export default defineContentScript({
  matches: ['*://*.youtube.com/watch*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let subtitleState: SubtitleState = DEFAULT_SUBTITLE_STATE;
    let activeCue: SubtitleCue | null = null;
    let stopSubtitleSync: (() => void) | null = null;
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
        activeCue,
      });
    }

    function configureSubtitleSync(): void {
      stopSubtitleSync?.();
      stopSubtitleSync = null;
      activeCue = null;

      const page = parseYoutubePage(window.location.href);

      if (!page.supported || subtitleState.type !== 'ready') {
        updateOverlay();

        return;
      }

      const video = findPrimaryVideo(document);

      if (!video) {
        logVideoMissingDiagnostic(subtitleState.track);
        updateOverlay();

        return;
      }

      stopSubtitleSync = bindSubtitleTrackToVideo({
        video,
        track: subtitleState.track,
        onCueChange(change) {
          activeCue = change.cue;
          updateOverlay();
        },
        onDiagnostic: logSubtitleSyncDiagnostic,
      });
    }

    function resetOverlayForRouteChange(): void {
      subtitleState = DEFAULT_SUBTITLE_STATE;
      configureSubtitleSync();
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
        configureSubtitleSync();
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

        configureSubtitleSync();
      })
      .catch(() => {
        updateOverlay();
      });

    configureSubtitleSync();

    ctx.onInvalidated(() => {
      disposed = true;
      stopRouteObserver();
      stopSubtitleSync?.();
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

function findPrimaryVideo(documentRef: Document): HTMLVideoElement | null {
  return documentRef.querySelector('video');
}

function logSubtitleSyncDiagnostic(diagnostic: SubtitleSyncDiagnostic): void {
  console.warn(`extension.subtitle_sync_${diagnostic.type}`, diagnostic);
}

function logVideoMissingDiagnostic(track: TrackResponse): void {
  console.warn('extension.subtitle_sync_video_missing', {
    type: 'video_missing',
    trackId: track.trackId,
    youtubeVideoId: track.youtubeVideoId,
  });
}
