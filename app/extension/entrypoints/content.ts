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
import { hasLearningMetadata, tokenKey } from '../utils/track-tokens';
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
    let routeHydrateTimer: number | undefined;
    let videoBindRetryTimer: number | undefined;
    let videoBindRetriesLeft = 0;
    let studyHoverPaused = false;
    const pendingTokenKeys = new Set<string>();
    const failedTokenKeys = new Set<string>();
    let disposed = false;
    const cueHold = new CueHoldController({
      holdMs: 1800,
      view: window,
      onExpire: () => {
        activeCue = null;
        updateOverlay();
        const clearedPage = parseYoutubePage(window.location.href);
        if (clearedPage.supported) {
          void browser.runtime
            .sendMessage({ type: 'content.activeCueChanged', cueId: null, youtubeVideoId: clearedPage.videoId })
            .catch(() => {});
        }
      },
    });

    const overlay = new OverlayShell(document, {
      onCopyCue: (cue) => copyCueToClipboard(cue),
      onReplayCue: (cue) => replayCue(cue),
      onStudyHoverEnd: () => resumeVideoAfterStudyHover(),
      onTokenPreview: () => pauseVideoForStudy(),
      onTokenPreviewEnd: () => resumeVideoAfterStudyHover(),
      onTokenClick: (cue, token) => {
        pauseVideoForStudy();
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
    browser.runtime.onMessage.addListener(handleRuntimeMessage);
    void hydrateContentState();

    updateOverlay();

    ctx.onInvalidated(() => {
      disposed = true;
      if (routeHydrateTimer !== undefined) {
        window.clearTimeout(routeHydrateTimer);
        routeHydrateTimer = undefined;
      }
      for (const eventName of YOUTUBE_ROUTE_EVENTS) {
        window.removeEventListener(eventName, handleYoutubeRouteChange);
      }
      window.removeEventListener('keydown', handleKeyboardShortcut, true);
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

      if (message.type === 'background.seekToCue') {
        if (subtitleState.type === 'ready') {
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
          overlay.showActionStatus('Transcript is in the side panel.', 'info');
          return;

        case 'copy-current-cue':
          await copyCueFromShortcut();
          return;

      }
    }

    async function hydrateContentState(): Promise<void> {
      if (!parseYoutubePage(window.location.href).supported) return;
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
      });
    }

    /**
     * Right after navigation the target `<video>` element is often not
     * mounted yet, so the first bind attempt can find nothing. Instead of
     * giving up (which used to leave the overlay empty until a refresh),
     * retry briefly while the current subtitle state stays unchanged.
     */
    function scheduleVideoBindRetry(bind: () => void): void {
      if (disposed || videoBindRetriesLeft <= 0) {
        return;
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
    }

    function clearBoundWebVttTrack(): void {
      stopWebVttTrack?.();
      stopVideoStateListeners?.();
      stopWebVttTrack = null;
      stopVideoStateListeners = null;
      if (videoBindRetryTimer !== undefined) {
        window.clearTimeout(videoBindRetryTimer);
        videoBindRetryTimer = undefined;
      }
      cueHold.clear();
      activeCue = null;
      activePartialCue = null;
      boundPartialTrackKey = null;
      boundReadyTrackId = null;
      const clearedPage = parseYoutubePage(window.location.href);
      if (clearedPage.supported) {
        void browser.runtime
          .sendMessage({ type: 'content.activeCueChanged', cueId: null, youtubeVideoId: clearedPage.videoId })
          .catch(() => {});
      }
      activeVideo = null;
      studyHoverPaused = false;
      pendingTokenKeys.clear();
      failedTokenKeys.clear();
    }

    function clearSubtitles(): void {
      clearBoundWebVttTrack();
      subtitleState = DEFAULT_SUBTITLE_STATE;
      updateOverlay();
    }

    function applySubtitleState(nextSubtitleState: SubtitleState): void {
      // Loading updates for an already-bound partial track (progress text,
      // unchanged revision) must not rebind: rebinding resets the text track
      // and drops the currently displayed cue every 2s poll.
      const nextPartialKey = partialTrackKey(nextSubtitleState);

      if (
        nextPartialKey !== null
        && nextPartialKey === boundPartialTrackKey
        && subtitleStateMatchesCurrentPage(nextSubtitleState)
      ) {
        subtitleState = nextSubtitleState;
        updateOverlay();

        return;
      }

      // The same finalized track can be re-delivered (background publish plus
      // a hydrate pull). Rebinding would reset the text track and drop the
      // active cue, and the local copy may carry newer on-click enrichment —
      // keep it.
      if (
        nextSubtitleState.type === 'ready'
        && boundReadyTrackId === nextSubtitleState.track.trackId
        && subtitleStateMatchesCurrentPage(nextSubtitleState)
      ) {
        return;
      }

      clearBoundWebVttTrack();
      videoBindRetriesLeft = VIDEO_BIND_RETRY_LIMIT;

      if (!subtitleStateMatchesCurrentPage(nextSubtitleState)) {
        subtitleState = DEFAULT_SUBTITLE_STATE;
        updateOverlay();

        return;
      }

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
        scheduleVideoBindRetry(() => bindPartialSubtitles(partialTrack, partialKey));
        updateOverlay();

        return;
      }

      activeVideo = video;
      boundPartialTrackKey = partialKey;

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
        scheduleVideoBindRetry(() => bindGeneratedSubtitles(track));
        updateOverlay();

        return;
      }

      bindVideoStateListeners(video);
      boundReadyTrackId = track.trackId;

      stopWebVttTrack = bindWebVttTrackToVideo({
        video,
        track,
        timingOffsetSeconds: settings.subtitleTimingOffsetSeconds,
        onCueChange(change) {
          const isPlaying = typeof video.currentTime === 'number' && !video.paused && !video.ended;
          const next = cueHold.select(change.activeCue, isPlaying, activeCue);

          if (next === activeCue && activeCue !== null) {
            updateOverlay(); // hold: keep prior cue rendered, no broadcast change
            return;
          }

          activeCue = next;
          updateOverlay();
          const page = parseYoutubePage(window.location.href);
          if (page.supported) {
            void browser.runtime.sendMessage({
              type: 'content.activeCueChanged',
              cueId: activeCue?.cueId ?? null,
              youtubeVideoId: page.videoId,
            }).catch(() => {});
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

      const clearStudyHoverPause = (): void => {
        studyHoverPaused = false;
      };

      video.addEventListener('play', clearStudyHoverPause);
      video.addEventListener('playing', clearStudyHoverPause);
      video.addEventListener('seeked', handleSeeked);

      stopVideoStateListeners = () => {
        video.removeEventListener('play', clearStudyHoverPause);
        video.removeEventListener('playing', clearStudyHoverPause);
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
      const clearedPage = parseYoutubePage(window.location.href);
      if (clearedPage.supported) {
        void browser.runtime
          .sendMessage({ type: 'content.activeCueChanged', cueId: next?.cueId ?? null, youtubeVideoId: clearedPage.videoId })
          .catch(() => {});
      }
    }

    function pauseVideoForStudy(): void {
      if (!settings.pauseOnWordHover || !activeVideo || activeVideo.paused) {
        return;
      }

      studyHoverPaused = true;
      activeVideo.pause();
    }

    function resumeVideoAfterStudyHover(): void {
      if (!studyHoverPaused || !activeVideo) {
        return;
      }

      studyHoverPaused = false;

      if (!activeVideo.paused) {
        return;
      }

      const playResult = activeVideo.play();

      if (playResult && typeof playResult.catch === 'function') {
        playResult.catch((error: unknown) => {
          console.warn('extension.subtitle_study_resume_failed', {
            error: error instanceof Error ? error.message : 'Unknown resume error',
          });
        });
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
      studyHoverPaused = false;
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
      studyHoverPaused = false;
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
