import { browser, type Browser } from 'wxt/browser';

import { DEFAULT_EXTENSION_SETTINGS, createExtensionSettingsFromPartial } from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { hasLearningMetadata, tokenKey } from '../utils/track-tokens';
import { bindWebVttTrackToVideo } from '../utils/webvtt-track';
import { webVttTrackLogger } from '../utils/webvtt-track-logger';
import type { LearningToken, SubtitleCue, TrackResponse } from '../utils/contracts';
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
    const pendingTokenKeys = new Set<string>();
    const failedTokenKeys = new Set<string>();
    let disposed = false;

    const overlay = new OverlayShell(document, {
      onTokenClick: (cue, token) => {
        void enrichLearningToken(cue, token);
      },
    });
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
        const nextSettings = createExtensionSettingsFromPartial(message.settings);
        const timingOffsetChanged =
          nextSettings.subtitleTimingOffsetSeconds !== settings.subtitleTimingOffsetSeconds;

        settings = nextSettings;

        if (timingOffsetChanged && subtitleState.type === 'ready') {
          clearBoundWebVttTrack();
          bindGeneratedSubtitles(subtitleState.track);
        } else {
          updateOverlay();
        }

        sendResponse({ ok: true });

        return false;
      }

      if (message.type === 'background.subtitleStateChanged') {
        applySubtitleState(message.subtitleState);
        sendResponse({ ok: true });

        return false;
      }

      if (message.type === 'background.getPageSnapshot') {
        sendResponse({
          ok: true,
          videoDurationSeconds: currentVideoDurationSeconds(),
        });

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
        pendingTokenKeys,
        failedTokenKeys,
      });
    }

    function clearBoundWebVttTrack(): void {
      stopWebVttTrack?.();
      stopWebVttTrack = null;
      activeCue = null;
      pendingTokenKeys.clear();
      failedTokenKeys.clear();
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
      pendingTokenKeys.clear();
      failedTokenKeys.clear();

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
        timingOffsetSeconds: settings.subtitleTimingOffsetSeconds,
        onCueChange(change) {
          activeCue = change.activeCue;
          updateOverlay();
        },
        logger: webVttTrackLogger,
      });

      if (settings.subtitleTimingOffsetSeconds !== 0) {
        console.info('extension.subtitle_timing_offset_applied', {
          youtubeVideoId: track.youtubeVideoId,
          trackId: track.trackId,
          offsetSeconds: settings.subtitleTimingOffsetSeconds,
        });
      }
    }

    function currentVideoDurationSeconds(): number | undefined {
      const video = document.querySelector('video');
      const duration = video?.duration;

      if (typeof duration !== 'number' || !Number.isFinite(duration) || duration <= 0) {
        return undefined;
      }

      return Math.round(duration);
    }

    async function enrichLearningToken(cue: SubtitleCue, token: LearningToken): Promise<void> {
      if (subtitleState.type !== 'ready' || hasLearningMetadata(token)) {
        return;
      }

      const key = tokenKey(cue.cueId, token.index);

      if (pendingTokenKeys.has(key)) {
        return;
      }

      pendingTokenKeys.add(key);
      failedTokenKeys.delete(key);
      updateOverlay();

      try {
        const response = (await browser.runtime.sendMessage({
          type: 'content.enrichLearningToken',
          youtubeVideoId: subtitleState.track.youtubeVideoId,
          trackId: subtitleState.track.trackId,
          cueId: cue.cueId,
          tokenIndex: token.index,
        })) as { ok?: boolean; track?: TrackResponse; error?: string };

        if (response?.ok === false || !response?.track) {
          throw new Error(response?.error ?? 'Unable to generate word card.');
        }

        applyEnrichedTrack(response.track, cue.cueId, key);
      } catch (error) {
        console.warn('extension.learning_token_enrichment_failed', {
          trackId: subtitleState.track.trackId,
          cueId: cue.cueId,
          tokenIndex: token.index,
          error: error instanceof Error ? error.message : 'Unknown extension enrichment error',
        });
        pendingTokenKeys.delete(key);
        failedTokenKeys.add(key);
        updateOverlay();
      }
    }

    function applyEnrichedTrack(track: TrackResponse, cueId: string, tokenKeyValue: string): void {
      subtitleState = {
        type: 'ready',
        track,
      };

      if (activeCue?.cueId === cueId) {
        activeCue = track.cues.find((candidate) => candidate.cueId === cueId) ?? activeCue;
      }

      pendingTokenKeys.delete(tokenKeyValue);
      failedTokenKeys.delete(tokenKeyValue);
      updateOverlay();
    }
  },
});
