import { browser, type Browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, createExtensionSettingsFromPartial } from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { bindWebVttTrackToVideo } from '../utils/webvtt-track';
import { webVttTrackLogger } from '../utils/webvtt-track-logger';
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
    let stopWebVttTrack: (() => void) | null = null;
    let disposed = false;

    const overlay = new OverlayShell(document);
    const handleYoutubeRouteChange = (): void => clearSubtitles();

    for (const eventName of YOUTUBE_ROUTE_EVENTS) {
      window.addEventListener(eventName, handleYoutubeRouteChange);
    }

    browser.runtime.onMessage.addListener(handleRuntimeMessage);
    void hydrateContentState();

    updateOverlay();

    ctx.onInvalidated(() => {
      disposed = true;
      for (const eventName of YOUTUBE_ROUTE_EVENTS) {
        window.removeEventListener(eventName, handleYoutubeRouteChange);
      }
      browser.runtime.onMessage.removeListener(handleRuntimeMessage);
      clearBoundWebVttTrack();
      overlay.unmount();
    });

    function handleRuntimeMessage(
      message: unknown,
      _sender: Browser.runtime.MessageSender,
      sendResponse: (response?: unknown) => void,
    ): boolean {
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
        applySubtitleState(message.subtitleState);
        sendResponse({ ok: true });

        return false;
      }

      return false;
    }

    async function hydrateContentState(): Promise<void> {
      try {
        const state = await browser.runtime.sendMessage({ type: 'content.getState' });

        if (state?.settings) {
          settings = createExtensionSettingsFromPartial(state.settings);
        }

        if (state?.subtitleState) {
          applySubtitleState(state.subtitleState);

          return;
        }
      } catch {
        // The overlay can still render its default local state when background state is unavailable.
      }

      updateOverlay();
    }

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

    function clearBoundWebVttTrack(): void {
      stopWebVttTrack?.();
      stopWebVttTrack = null;
      activeCue = null;
    }

    function clearSubtitles(): void {
      clearBoundWebVttTrack();
      subtitleState = DEFAULT_SUBTITLE_STATE;
      updateOverlay();
    }

    function applySubtitleState(nextSubtitleState: SubtitleState): void {
      clearBoundWebVttTrack();

      if (!subtitleStateMatchesCurrentPage(nextSubtitleState)) {
        subtitleState = DEFAULT_SUBTITLE_STATE;
        updateOverlay();

        return;
      }

      subtitleState = nextSubtitleState;

      if (nextSubtitleState.type !== 'ready') {
        updateOverlay();

        return;
      }

      bindGeneratedSubtitles(nextSubtitleState.track);
    }

    function subtitleStateMatchesCurrentPage(nextSubtitleState: SubtitleState): boolean {
      const page = parseYoutubePage(window.location.href);

      if (nextSubtitleState.type === 'no-track') {
        return true;
      }

      if (!page.supported) {
        return false;
      }

      if (nextSubtitleState.type === 'ready') {
        return nextSubtitleState.track.youtubeVideoId === page.videoId;
      }

      return nextSubtitleState.youtubeVideoId === page.videoId;
    }

    function bindGeneratedSubtitles(track: TrackResponse): void {
      const page = parseYoutubePage(window.location.href);

      if (!page.supported || page.videoId !== track.youtubeVideoId) {
        subtitleState = DEFAULT_SUBTITLE_STATE;
        updateOverlay();

        return;
      }

      const video = document.querySelector('video');

      if (!video) {
        webVttTrackLogger.videoMissing(track);
        updateOverlay();

        return;
      }

      stopWebVttTrack = bindWebVttTrackToVideo({
        video,
        track,
        onCueChange(change) {
          activeCue = change.activeCue;
          updateOverlay();
        },
        logger: webVttTrackLogger,
      });
    }
  },
});
