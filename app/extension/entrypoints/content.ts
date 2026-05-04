import { browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, createExtensionSettingsFromPartial } from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { bindWebVttTrackToVideo, type WebVttTrackDiagnostic } from '../utils/webvtt-track';
import type { TrackResponse } from '../utils/contracts';
import { parseYoutubePage } from '../utils/youtube';

const YOUTUBE_ROUTE_EVENTS = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];

export default defineContentScript({
  matches: ['*://*.youtube.com/watch*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let subtitleState: SubtitleState = DEFAULT_SUBTITLE_STATE;
    let activeSourceText: string | null = null;
    let stopWebVttTrack: (() => void) | null = null;
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
        activeSourceText,
      });
    }

    function configureWebVttTrack(): void {
      stopWebVttTrack?.();
      stopWebVttTrack = null;
      activeSourceText = null;

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

      stopWebVttTrack = bindWebVttTrackToVideo({
        video,
        track: subtitleState.track,
        onCueChange(change) {
          activeSourceText = change.activeSourceText;
          updateOverlay();
        },
        onDiagnostic: logWebVttTrackDiagnostic,
      });
    }

    function resetOverlayForRouteChange(): void {
      subtitleState = DEFAULT_SUBTITLE_STATE;
      configureWebVttTrack();
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
        configureWebVttTrack();
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

        configureWebVttTrack();
      })
      .catch(() => {
        updateOverlay();
      });

    configureWebVttTrack();

    ctx.onInvalidated(() => {
      disposed = true;
      stopRouteObserver();
      stopWebVttTrack?.();
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

function logWebVttTrackDiagnostic(diagnostic: WebVttTrackDiagnostic): void {
  console.warn(`extension.webvtt_track_${diagnostic.type}`, diagnostic);
}

function logVideoMissingDiagnostic(track: TrackResponse): void {
  console.warn('extension.webvtt_track_video_missing', {
    type: 'video_missing',
    trackId: track.trackId,
    youtubeVideoId: track.youtubeVideoId,
  });
}
