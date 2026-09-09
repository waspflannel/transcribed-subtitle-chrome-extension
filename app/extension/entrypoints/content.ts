import { browser, type Browser } from 'wxt/browser';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createExtensionSettingsFromPartial,
  type ExtensionSettings,
} from '../utils/settings-model';
import {
  DEFAULT_SUBTITLE_STATE,
  isRuntimeMessage,
  type PartialSubtitleTrack,
  type SubtitleState,
} from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { cueForNavigation, cueForPlaybackTime, cueStartPlaybackSeconds } from '../utils/cue-navigation';
import { CueHoldController } from '../utils/cue-hold';
import { shortcutActionFromKeyboardEvent, type KeyboardShortcutAction } from '../utils/keyboard-shortcuts';
import { hasLearningMetadata, tokenKey, trackWithLearningToken } from '../utils/track-tokens';
import { bindWebVttTrackToVideo, buildWebVttFromCues } from '../utils/webvtt-track';
import { webVttTrackLogger } from '../utils/webvtt-track-logger';
import type { LearningToken, PartialSubtitleCue, SubtitleCue, TrackResponse } from '../utils/contracts';
import { parseYoutubePage } from '../utils/youtube';
import { findActiveYoutubeVideo } from '../utils/youtube-video';

const YOUTUBE_ROUTE_EVENTS = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];
const ROUTE_REHYDRATE_DELAY_MS = 150;
const VIDEO_BIND_RETRY_LIMIT = 10;
const VIDEO_BIND_RETRY_DELAY_MS = 300;

export default defineContentScript({
  matches: ['*://*.youtube.com/*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let subtitleState: SubtitleState = DEFAULT_SUBTITLE_STATE;
    let activeCue: SubtitleCue | null = null;
    let activePartialCue: PartialSubtitleCue | null = null;
    let boundPartialTrackKey: string | null = null;
    let boundReadyTrackId: string | null = null;
    let activeVideo: HTMLVideoElement | null = null;
    let stopWebVttTrack: (() => void) | null = null;
    let stopVideoStateListeners: (() => void) | null = null;
    let bindingError: string | null = null;
    let routeHydrateTimer: number | undefined;
    let videoBindRetryTimer: number | undefined;
    let videoBindRetriesLeft = 0;
    let studyHoverActive = false;
    let studyFocusActive = false;
    let studyPauseOwned = false;
    let studyPauseRequestVideo: HTMLVideoElement | null = null;
    let studyPauseCycle = 0;
    const pendingTokenKeys = new Set<string>();
    const failedTokenKeys = new Set<string>();
    let disposed = false;
    let stateEpoch = 0;
    let hydrationRequest = 0;
    let lastBroadcastCue: string | null = null;
    const cueHold = new CueHoldController({
      holdMs: 1800,
      view: window,
      onExpire: () => {
        activeCue = null;
        updateOverlay();
      },
    });

    const overlay = new OverlayShell(document, {
      onCopyCue: (cue) => copyCueToClipboard(cue),
      onReplayCue: (cue) => replayCue(cue),
      onRetryBinding: () => retryVideoBinding(),
      onStudyHoverEnd: () => endStudyHover(),
      onTokenPreview: () => beginStudyHover(),
      onTokenPreviewEnd: () => endStudyHover(),
      onTokenFocus: () => beginStudyFocus(),
      onTokenBlur: () => endStudyFocus(),
      onTokenClick: (cue, token) => {
        beginStudyFocus();
        void enrichLearningToken(cue, token);
      },
    });
    // YouTube swaps videos without reloading the page, so this content script
    // never restarts on SPA navigation. Clearing alone left the overlay empty
    // until a manual refresh, because the background only pushes state on
    // panel activity — re-pull once the navigation settles.
    const handleYoutubeRouteChange = (): void => {
      clearSubtitles();

      if (routeHydrateTimer !== undefined) {
        window.clearTimeout(routeHydrateTimer);
      }

      routeHydrateTimer = window.setTimeout(() => {
        routeHydrateTimer = undefined;
        void hydrateContentState();
      }, ROUTE_REHYDRATE_DELAY_MS);
    };

    for (const eventName of YOUTUBE_ROUTE_EVENTS) {
      window.addEventListener(eventName, handleYoutubeRouteChange);
    }

    window.addEventListener('keydown', handleKeyboardShortcut, true);
    window.addEventListener('fullscreenchange', recoverPlayerBinding);
    window.addEventListener('resize', recoverPlayerBinding);
    window.addEventListener('scroll', recoverPlayerBinding, true);
    const playerRecoveryTimer = window.setInterval(recoverPlayerBinding, 1000);
    browser.runtime.onMessage.addListener(handleRuntimeMessage);
    void hydrateContentState();

    updateOverlay();

    ctx.onInvalidated(() => {
      disposed = true;
      stateEpoch += 1;
      if (routeHydrateTimer !== undefined) {
        window.clearTimeout(routeHydrateTimer);
        routeHydrateTimer = undefined;
      }
      for (const eventName of YOUTUBE_ROUTE_EVENTS) {
        window.removeEventListener(eventName, handleYoutubeRouteChange);
      }
      window.removeEventListener('keydown', handleKeyboardShortcut, true);
      browser.runtime.onMessage.removeListener(handleRuntimeMessage);
      window.clearInterval(playerRecoveryTimer);
      window.removeEventListener('fullscreenchange', recoverPlayerBinding);
      window.removeEventListener('resize', recoverPlayerBinding);
      window.removeEventListener('scroll', recoverPlayerBinding, true);
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

        if (!settings.overlayVisible || !settings.pauseOnWordHover) {
          releaseStudyPause();
        }

        if (timingOffsetChanged && subtitleState.type === 'ready') {
          clearBoundWebVttTrack();
          videoBindRetriesLeft = VIDEO_BIND_RETRY_LIMIT;
          bindGeneratedSubtitles(subtitleState.track);
        } else if (timingOffsetChanged && subtitleState.type === 'loading' && subtitleState.partialTrack) {
          const partialKey = partialTrackKey(subtitleState);
          clearBoundWebVttTrack();
          videoBindRetriesLeft = VIDEO_BIND_RETRY_LIMIT;
          bindPartialSubtitles(subtitleState.partialTrack, partialKey);
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

      if (message.type === 'background.getActiveCue') {
        const matches = subtitleState.type === 'ready' && subtitleStateMatchesCurrentPage(subtitleState)
          && subtitleState.track.youtubeVideoId === message.youtubeVideoId && subtitleState.track.trackId === message.trackId;
        sendResponse({ ok: matches, youtubeVideoId: message.youtubeVideoId, trackId: message.trackId,
          cueId: matches ? activeCueFromState()?.cueId ?? null : null });
        return false;
      }

      if (message.type === 'background.seekToCue') {
        const page = parseYoutubePage(window.location.href);
        if (page.supported && page.videoId === message.youtubeVideoId
          && subtitleState.type === 'ready' && subtitleState.track.youtubeVideoId === message.youtubeVideoId
          && subtitleState.track.trackId === message.trackId) {
          const cue = subtitleState.track.cues.find((c) => c.cueId === message.cueId);
          if (cue) {
            if (message.mode === 'replay') {
              replayCue(cue);
            } else {
              // Transcript "jump" should take you to the cue AND play it — seeking
              // without playing looks like nothing happened on a paused video.
              jumpToCue(cue);
              void activeVideo?.play()?.catch(() => {});
            }
          }
        }
        sendResponse({ ok: true });
        return false;
      }

      return false;
    }

    function handleKeyboardShortcut(event: KeyboardEvent): void {
      if (!parseYoutubePage(window.location.href).supported) return;
      const action = shortcutActionFromKeyboardEvent(event, {
        enabled: settings.keyboardShortcutsEnabled,
      });

      if (!action) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      void handleShortcutAction(action);
    }

    async function handleShortcutAction(action: KeyboardShortcutAction): Promise<void> {
      switch (action) {
        case 'replay-current-cue':
          replayCueFromShortcut();
          return;

        case 'previous-cue':
          jumpToNeighborCue('previous');
          return;

        case 'next-cue':
          jumpToNeighborCue('next');
          return;

        case 'toggle-translation':
          if (await updateSettingsFromShortcut({ showTranslation: !settings.showTranslation })) {
            overlay.showActionStatus(settings.showTranslation ? 'Translation shown.' : 'Translation hidden.', 'success');
          }

          return;

        case 'toggle-source-blur':
          if (await updateSettingsFromShortcut({ blurSourceWords: !settings.blurSourceWords })) {
            overlay.showActionStatus(settings.blurSourceWords ? 'Source words blurred.' : 'Source words revealed.', 'success');
          }

          return;

        case 'toggle-auto-pause':
          if (await updateSettingsFromShortcut({ pauseOnWordHover: !settings.pauseOnWordHover })) {
            overlay.showActionStatus(settings.pauseOnWordHover ? 'Hover pause on.' : 'Hover pause off.', 'success');
          }

          return;

        case 'toggle-transcript':
          void browser.runtime.sendMessage({ type: 'content.focusPanelTranscript' }).catch(() => {});
          overlay.showActionStatus('Open the side panel for the transcript.', 'info');
          return;

        case 'copy-current-cue':
          await copyCueFromShortcut();
          return;

      }
    }

    async function hydrateContentState(): Promise<void> {
      if (!parseYoutubePage(window.location.href).supported) return;
      const request = ++hydrationRequest;
      const epoch = stateEpoch;
      const url = window.location.href;
      try {
        const state = await browser.runtime.sendMessage({ type: 'content.getState' });
        if (disposed || request !== hydrationRequest || epoch !== stateEpoch || url !== window.location.href) return;

        if (state?.settings) {
          settings = createExtensionSettingsFromPartial(state.settings);
        }

        if (state?.subtitleState) {
          applySubtitleState(state.subtitleState);

          return;
        }
      } catch {
        if (disposed || request !== hydrationRequest || epoch !== stateEpoch || url !== window.location.href) return;
        // The overlay can still render its default local state when background state is unavailable.
      }

      updateOverlay();
    }

    function updateOverlay(): void {
      if (disposed) {
        return;
      }

      if (!parseYoutubePage(window.location.href).supported) {
        overlay.unmount();
        return;
      }

      overlay.update({
        page: parseYoutubePage(window.location.href),
        subtitleState,
        settings,
        activeCue,
        activePartialCue,
        pendingTokenKeys,
        failedTokenKeys,
        bindingError,
      });
      const page = parseYoutubePage(window.location.href);
      if (page.supported) {
        const cueId = activeCue?.cueId ?? null;
        const identity = JSON.stringify([page.videoId, boundReadyTrackId, cueId]);
        if (identity !== lastBroadcastCue) {
          lastBroadcastCue = identity;
          void browser.runtime.sendMessage({
            type: 'content.activeCueChanged',
            youtubeVideoId: page.videoId,
            trackId: boundReadyTrackId,
            cueId,
          }).catch(() => {});
        }
      }
    }

    function recoverPlayerBinding(): void {
      if (disposed || !subtitleStateMatchesCurrentPage(subtitleState)) return;
      if (subtitleState.type !== 'ready' && !(subtitleState.type === 'loading' && subtitleState.partialTrack)) {
        updateOverlay();
        return;
      }
      const video = findActiveYoutubeVideo(document);
      if (video !== activeVideo || (activeVideo !== null && !activeVideo.isConnected)) {
        applySubtitleState(subtitleState);
      } else if (video && !stopWebVttTrack
        && (subtitleState.type === 'ready' || (subtitleState.type === 'loading' && subtitleState.partialTrack))) {
        applySubtitleState(subtitleState);
      }
      updateOverlay();
    }

    /**
     * Right after navigation the target `<video>` element is often not
     * mounted yet, so the first bind attempt can find nothing. Instead of
     * giving up (which used to leave the overlay empty until a refresh),
     * retry briefly while the current subtitle state stays unchanged.
     */
    function scheduleVideoBindRetry(bind: () => void): boolean {
      if (disposed || videoBindRetriesLeft <= 0) {
        return false;
      }

      videoBindRetriesLeft -= 1;
      const stateAtSchedule = subtitleState;

      if (videoBindRetryTimer !== undefined) {
        window.clearTimeout(videoBindRetryTimer);
      }

      videoBindRetryTimer = window.setTimeout(() => {
        videoBindRetryTimer = undefined;

        if (disposed || subtitleState !== stateAtSchedule) {
          return;
        }

        bind();
      }, VIDEO_BIND_RETRY_DELAY_MS);

      return true;
    }

    function clearBoundWebVttTrack(): void {
      stateEpoch += 1;
      stopWebVttTrack?.();
      stopVideoStateListeners?.();
      stopWebVttTrack = null;
      stopVideoStateListeners = null;
      if (videoBindRetryTimer !== undefined) {
        window.clearTimeout(videoBindRetryTimer);
        videoBindRetryTimer = undefined;
      }
      releaseStudyPause();
      studyHoverActive = false;
      studyFocusActive = false;
      studyPauseRequestVideo = null;
      cueHold.clear();
      bindingError = null;
      activeCue = null;
      activePartialCue = null;
      boundPartialTrackKey = null;
      boundReadyTrackId = null;
      activeVideo = null;
      pendingTokenKeys.clear();
      failedTokenKeys.clear();
    }

    function clearSubtitles(): void {
      clearBoundWebVttTrack();
      subtitleState = DEFAULT_SUBTITLE_STATE;
      updateOverlay();
    }

    function retryVideoBinding(): void {
      if (disposed) return;
      const state = subtitleState;
      if (state.type !== 'ready' && !(state.type === 'loading' && state.partialTrack)) return;
      clearBoundWebVttTrack();
      videoBindRetriesLeft = VIDEO_BIND_RETRY_LIMIT;
      if (state.type === 'ready') {
        bindGeneratedSubtitles(state.track);
      } else {
        const partialTrack = state.partialTrack;
        if (!partialTrack) return;
        bindPartialSubtitles(partialTrack, partialTrackKey(state));
      }
      updateOverlay();
    }

    function applySubtitleState(nextSubtitleState: SubtitleState): void {
      if (disposed || !subtitleStateMatchesCurrentPage(nextSubtitleState)) return;
      hydrationRequest += 1;
      // Loading updates for an already-bound partial track (progress text,
      // unchanged revision) must not rebind: rebinding resets the text track
      // and drops the currently displayed cue every 2s poll.
      const nextPartialKey = partialTrackKey(nextSubtitleState);

      if (
        nextPartialKey !== null
        && nextPartialKey === boundPartialTrackKey
        && activeVideo?.isConnected && activeVideo === findActiveYoutubeVideo(document)
        && subtitleStateMatchesCurrentPage(nextSubtitleState)
      ) {
        subtitleState = nextSubtitleState;
        updateOverlay();

        return;
      }

      // Metadata-only updates do not need a native timing-track replacement.
      if (
        nextSubtitleState.type === 'ready'
        && boundReadyTrackId === nextSubtitleState.track.trackId
        && subtitleState.type === 'ready'
        && subtitleState.track.webVtt === nextSubtitleState.track.webVtt
        && JSON.stringify(subtitleState.track.cues.map(({ cueId, startMs, endMs }) => [cueId, startMs, endMs]))
          === JSON.stringify(nextSubtitleState.track.cues.map(({ cueId, startMs, endMs }) => [cueId, startMs, endMs]))
        && activeVideo?.isConnected && activeVideo === findActiveYoutubeVideo(document)
        && subtitleStateMatchesCurrentPage(nextSubtitleState)
      ) {
        subtitleState = nextSubtitleState;
        activeCue = nextSubtitleState.track.cues.find((cue) => cue.cueId === activeCue?.cueId) ?? null;
        updateOverlay();
        return;
      }

      clearBoundWebVttTrack();
      videoBindRetriesLeft = VIDEO_BIND_RETRY_LIMIT;

      subtitleState = nextSubtitleState;
      pendingTokenKeys.clear();
      failedTokenKeys.clear();

      if (nextSubtitleState.type === 'loading' && nextSubtitleState.partialTrack) {
        bindPartialSubtitles(nextSubtitleState.partialTrack, nextPartialKey);

        return;
      }

      if (nextSubtitleState.type !== 'ready') {
        updateOverlay();

        return;
      }

      bindGeneratedSubtitles(nextSubtitleState.track);
    }

    function partialTrackKey(state: SubtitleState): string | null {
      if (state.type !== 'loading' || !state.partialTrack) {
        return null;
      }

      return `${state.partialTrack.jobId}:${state.partialTrack.revision}`;
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

    /**
     * Binds the cues of a still-running job to the video so subtitles render
     * while the pipeline finishes. Passive display only: no cue hold, token
     * cards, or study controls until the finalized track arrives.
     */
    function bindPartialSubtitles(partialTrack: PartialSubtitleTrack, partialKey: string | null): void {
      const page = parseYoutubePage(window.location.href);

      if (!page.supported || page.videoId !== partialTrack.youtubeVideoId) {
        updateOverlay();

        return;
      }

      const video = findActiveYoutubeVideo(document);

      if (!video) {
        if (!scheduleVideoBindRetry(() => bindPartialSubtitles(partialTrack, partialKey))) {
          bindingError = 'The video player is unavailable. Retry attachment when it is visible.';
        }
        updateOverlay();

        return;
      }

      activeVideo = video;
      boundPartialTrackKey = partialKey;
      bindingError = null;
      const bindingEpoch = stateEpoch;

      stopWebVttTrack = bindWebVttTrackToVideo({
        video,
        track: {
          youtubeVideoId: partialTrack.youtubeVideoId,
          sourceLanguage: partialTrack.sourceLanguage,
          webVtt: buildWebVttFromCues(partialTrack.cues),
          cues: partialTrack.cues,
        },
        timingOffsetSeconds: settings.subtitleTimingOffsetSeconds,
        onCueChange(change) {
          activePartialCue = change.activeCue;
          updateOverlay();
        },
        onTrackLoaded: () => {
          if (stateEpoch === bindingEpoch && subtitleState.type === 'loading' && partialTrackKey(subtitleState) === partialKey && activeVideo === video) {
            bindingError = null;
            updateOverlay();
          }
        },
        onTrackLoadError: () => {
          if (stateEpoch === bindingEpoch && subtitleState.type === 'loading' && partialTrackKey(subtitleState) === partialKey && activeVideo === video) {
            bindingError = 'The partial subtitle track could not load. Retry attachment.';
            updateOverlay();
          }
        },
        logger: webVttTrackLogger,
      });

      updateOverlay();
    }

    function bindGeneratedSubtitles(track: TrackResponse): void {
      const page = parseYoutubePage(window.location.href);

      if (!page.supported || page.videoId !== track.youtubeVideoId) {
        subtitleState = DEFAULT_SUBTITLE_STATE;
        updateOverlay();

        return;
      }

      const video = findActiveYoutubeVideo(document);

      if (!video) {
        webVttTrackLogger.videoMissing(track);
        if (!scheduleVideoBindRetry(() => bindGeneratedSubtitles(track))) {
          bindingError = 'The video player is unavailable. Retry attachment when it is visible.';
        }
        updateOverlay();

        return;
      }

      bindVideoStateListeners(video);
      boundReadyTrackId = track.trackId;
      bindingError = null;
      const bindingEpoch = stateEpoch;

      stopWebVttTrack = bindWebVttTrackToVideo({
        video,
        track,
        timingOffsetSeconds: settings.subtitleTimingOffsetSeconds,
        onCueChange(change) {
          const isPlaying = typeof video.currentTime === 'number' && !video.paused && !video.ended;
          const currentCue = subtitleState.type === 'ready' && subtitleState.track.trackId === track.trackId
            ? subtitleState.track.cues.find((cue) => cue.cueId === change.activeCue?.cueId) ?? null : null;
          const next = cueHold.select(currentCue, isPlaying, activeCue);

          if (next === activeCue && activeCue !== null) {
            updateOverlay(); // hold: keep prior cue rendered, no broadcast change
            return;
          }

          activeCue = next;
          updateOverlay();
        },
        onTrackLoaded: () => {
          if (stateEpoch === bindingEpoch && subtitleState.type === 'ready' && subtitleState.track.trackId === track.trackId && activeVideo === video) {
            bindingError = null;
            updateOverlay();
          }
        },
        onTrackLoadError: () => {
          if (stateEpoch === bindingEpoch && subtitleState.type === 'ready' && subtitleState.track.trackId === track.trackId && activeVideo === video) {
            bindingError = 'The subtitle track could not load. Retry attachment.';
            updateOverlay();
          }
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

    function bindVideoStateListeners(video: HTMLVideoElement): void {
      activeVideo = video;

      const handleVideoPause = (): void => {
        if (studyPauseRequestVideo === video) {
          studyPauseRequestVideo = null;
          return;
        }

        studyPauseOwned = false;
      };
      const handleVideoPlay = (): void => {
        if (studyPauseRequestVideo === video) {
          studyPauseRequestVideo = null;
        }

        studyPauseOwned = false;
      };

      video.addEventListener('pause', handleVideoPause);
      video.addEventListener('play', handleVideoPlay);
      video.addEventListener('playing', handleVideoPlay);
      video.addEventListener('seeked', handleSeeked);

      stopVideoStateListeners = () => {
        video.removeEventListener('pause', handleVideoPause);
        video.removeEventListener('play', handleVideoPlay);
        video.removeEventListener('playing', handleVideoPlay);
        video.removeEventListener('seeked', handleSeeked);
      };
    }

    function handleSeeked(): void {
      cueHold.clear();
      const next =
        subtitleState.type === 'ready'
          ? cueForPlaybackTime(subtitleState.track, activeVideo?.currentTime ?? 0, settings.subtitleTimingOffsetSeconds)
          : null;
      if (next?.cueId === activeCue?.cueId) {
        return;
      }
      activeCue = next;
      updateOverlay();
    }

    function beginStudyHover(): void {
      studyPauseCycle += 1;
      studyHoverActive = true;
      pauseVideoForStudy();
    }

    function endStudyHover(): void {
      studyHoverActive = false;
      releaseStudyPauseWhenIdle();
    }

    function beginStudyFocus(): void {
      studyPauseCycle += 1;
      studyFocusActive = true;
      pauseVideoForStudy();
    }

    function endStudyFocus(): void {
      studyFocusActive = false;
      releaseStudyPauseWhenIdle();
    }

    function pauseVideoForStudy(): void {
      if (!settings.pauseOnWordHover || !activeVideo || activeVideo.paused || studyPauseOwned) {
        return;
      }

      studyPauseOwned = true;
      cueHold.pause();
      const video = activeVideo;
      studyPauseRequestVideo = video;
      try {
        video.pause();
      } catch {
        studyPauseRequestVideo = null;
        studyPauseOwned = false;
      }
    }

    function releaseStudyPauseWhenIdle(): void {
      if (studyHoverActive || studyFocusActive) {
        return;
      }

      releaseStudyPause();
    }

    function releaseStudyPause(): void {
      const shouldResume = studyPauseOwned;
      studyPauseRequestVideo = null;
      studyPauseOwned = false;
      cueHold.resume(activeCue, Boolean(activeVideo && !activeVideo.paused));

      if (!shouldResume || !activeVideo || !activeVideo.paused) {
        return;
      }

      const video = activeVideo;
      const cue = activeCue;
      const epoch = stateEpoch;
      const cycle = studyPauseCycle;
      const playResult = video.play();
      const canResumeHold = (): boolean =>
        activeVideo === video
        && stateEpoch === epoch
        && activeCue === cue
        && studyPauseCycle === cycle
        && !studyPauseOwned
        && !video.paused
        && !video.ended;

      if (playResult && typeof playResult.catch === 'function') {
        playResult.then(() => {
          if (!canResumeHold()) return;
          cueHold.resume(cue, true);
        }).catch((error: unknown) => {
          console.warn('extension.subtitle_study_resume_failed', {
            error: error instanceof Error ? error.message : 'Unknown resume error',
          });
        });
      } else {
        if (canResumeHold()) {
          cueHold.resume(cue, true);
        }
      }
    }

    function replayCueFromShortcut(): void {
      const cue = activeCueFromState();

      if (!cue) {
        overlay.showActionStatus('No active cue to replay.', 'error');

        return;
      }

      replayCue(cue);
      overlay.showActionStatus('Replaying cue.', 'success');
    }

    function jumpToNeighborCue(direction: 'previous' | 'next'): void {
      if (subtitleState.type !== 'ready') {
        overlay.showActionStatus('No generated track is active.', 'error');

        return;
      }

      const cue = cueForNavigation({
        track: subtitleState.track,
        activeCue,
        currentTimeSeconds: typeof activeVideo?.currentTime === 'number' ? activeVideo.currentTime : null,
        timingOffsetSeconds: settings.subtitleTimingOffsetSeconds,
        direction,
      });

      if (!cue) {
        overlay.showActionStatus('No cue to navigate to.', 'error');

        return;
      }

      jumpToCue(cue);
      overlay.showActionStatus(direction === 'previous' ? 'Previous cue.' : 'Next cue.', 'success');
    }

    function activeCueFromState(): SubtitleCue | null {
      if (activeCue) {
        return activeCue;
      }

      if (subtitleState.type !== 'ready' || !activeVideo) {
        return null;
      }

      return cueForPlaybackTime(subtitleState.track, activeVideo.currentTime, settings.subtitleTimingOffsetSeconds);
    }

    function jumpToCue(cue: SubtitleCue): void {
      if (!activeVideo) {
        return;
      }

      activeVideo.currentTime = cueStartPlaybackSeconds(cue, settings.subtitleTimingOffsetSeconds);
      activeCue = cue;
      updateOverlay();
    }

    function replayCue(cue: SubtitleCue): void {
      if (!activeVideo) {
        return;
      }

      const sourceCue = subtitleState.type === 'ready'
        ? subtitleState.track.cues.find((candidate) => candidate.cueId === cue.cueId) ?? cue
        : cue;

      activeVideo.currentTime = cueStartPlaybackSeconds(sourceCue, settings.subtitleTimingOffsetSeconds);
      activeCue = sourceCue;
      updateOverlay();
      const playResult = activeVideo.play();

      if (playResult && typeof playResult.catch === 'function') {
        playResult.catch((error: unknown) => {
          console.warn('extension.subtitle_replay_failed', {
            cueId: cue.cueId,
            error: error instanceof Error ? error.message : 'Unknown replay error',
          });
        });
      }
    }

    async function copyCueFromShortcut(): Promise<void> {
      const cue = activeCueFromState();

      if (!cue) {
        overlay.showActionStatus('No active cue to copy.', 'error');

        return;
      }

      if (await copyCueToClipboard(cue)) {
        overlay.showActionStatus('Cue copied.', 'success');
      } else {
        overlay.showActionStatus('Copy failed.', 'error');
      }
    }

    async function copyCueToClipboard(cue: SubtitleCue): Promise<boolean> {
      const clipboard = navigator.clipboard;

      if (!clipboard) {
        return false;
      }

      try {
        await clipboard.writeText(clipboardTextForCue(cue));

        return true;
      } catch (error) {
        console.warn('extension.subtitle_copy_failed', {
          cueId: cue.cueId,
          error: error instanceof Error ? error.message : 'Unknown clipboard error',
        });

        return false;
      }
    }

    function clipboardTextForCue(cue: SubtitleCue): string {
      const lines = [cue.sourceText.trim()];
      const cueRomanization = cue.romanization?.trim();
      const translatedText = cue.translatedText.trim();

      if (settings.showRomanization && cueRomanization) {
        lines.push(cueRomanization);
      }

      if (settings.showTranslation && translatedText !== '' && translatedText !== cue.sourceText.trim()) {
        lines.push(translatedText);
      }

      return lines.join('\n');
    }

    async function updateSettingsFromShortcut(patch: Partial<ExtensionSettings>): Promise<boolean> {
      try {
        const response = (await browser.runtime.sendMessage({
          type: 'content.updateSettings',
          patch,
        })) as { ok?: boolean; settings?: ExtensionSettings; error?: string };

        if (response?.settings) {
          settings = createExtensionSettingsFromPartial(response.settings);
          updateOverlay();

          return true;
        }

        throw new Error(response?.error ?? 'Unable to update extension settings.');
      } catch (error) {
        overlay.showActionStatus(
          error instanceof Error ? error.message : 'Unable to update extension settings.',
          'error',
        );

        return false;
      }
    }

    function currentVideoDurationSeconds(): number | undefined {
      const video = findActiveYoutubeVideo(document);
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

      const { trackId, youtubeVideoId } = subtitleState.track;
      const epoch = stateEpoch;
      const isCurrent = (): boolean => !disposed && epoch === stateEpoch
        && subtitleState.type === 'ready' && subtitleState.track.trackId === trackId
        && subtitleState.track.youtubeVideoId === youtubeVideoId
        && subtitleStateMatchesCurrentPage(subtitleState);

      pendingTokenKeys.add(key);
      failedTokenKeys.delete(key);
      updateOverlay();

      try {
        const response = (await browser.runtime.sendMessage({
          type: 'content.enrichLearningToken',
          youtubeVideoId,
          trackId,
          cueId: cue.cueId,
          tokenIndex: token.index,
        })) as { ok?: boolean; stale?: boolean; track?: TrackResponse; error?: string };

        if (!isCurrent()) return;

        if (response?.ok === false || !response?.track) {
          if (response?.stale) {
            pendingTokenKeys.delete(key);
            updateOverlay();
            return;
          }

          throw new Error(response?.error ?? 'Unable to generate word card.');
        }
        if (response.track.trackId !== trackId || response.track.youtubeVideoId !== youtubeVideoId) return;

        const enrichedToken = response.track.cues.find((candidate) => candidate.cueId === cue.cueId)
          ?.tokens.find((candidate) => candidate.index === token.index);
        if (!enrichedToken || enrichedToken.text !== token.text) throw new Error('Word card does not match the requested token.');
        if (subtitleState.type === 'ready') {
          applyEnrichedTrack(trackWithLearningToken(subtitleState.track, cue.cueId, enrichedToken), cue.cueId, key);
        }
      } catch (error) {
        if (!isCurrent()) return;
        console.warn('extension.learning_token_enrichment_failed', {
          trackId,
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
