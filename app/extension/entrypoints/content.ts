import { browser, type Browser } from 'wxt/browser';

import {
  DEFAULT_EXTENSION_SETTINGS,
  createExtensionSettingsFromPartial,
  type ExtensionSettings,
} from '../utils/settings-model';
import { DEFAULT_SUBTITLE_STATE, isRuntimeMessage, type SubtitleState } from '../utils/messages';
import { OverlayShell } from '../utils/overlay';
import { cueForNavigation, cueForPlaybackTime, cueStartPlaybackSeconds } from '../utils/cue-navigation';
import { shortcutActionFromKeyboardEvent, type KeyboardShortcutAction } from '../utils/keyboard-shortcuts';
import { hasLearningMetadata, tokenKey } from '../utils/track-tokens';
import { bindWebVttTrackToVideo } from '../utils/webvtt-track';
import { webVttTrackLogger } from '../utils/webvtt-track-logger';
import type { LearningToken, SubtitleCue, TrackResponse } from '../utils/contracts';
import { parseYoutubePage } from '../utils/youtube';
import { findActiveYoutubeVideo } from '../utils/youtube-video';

const YOUTUBE_ROUTE_EVENTS = ['yt-navigate-finish', 'yt-page-data-updated', 'popstate', 'hashchange'];

export default defineContentScript({
  matches: ['*://*.youtube.com/watch*', '*://*.youtube.com/shorts/*'],
  runAt: 'document_idle',
  main(ctx) {
    let settings = DEFAULT_EXTENSION_SETTINGS;
    let subtitleState: SubtitleState = DEFAULT_SUBTITLE_STATE;
    let activeCue: SubtitleCue | null = null;
    let activeVideo: HTMLVideoElement | null = null;
    let stopWebVttTrack: (() => void) | null = null;
    let stopVideoStateListeners: (() => void) | null = null;
    let studyHoverPaused = false;
    const pendingTokenKeys = new Set<string>();
    const failedTokenKeys = new Set<string>();
    let disposed = false;

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
    const handleYoutubeRouteChange = (): void => clearSubtitles();

    for (const eventName of YOUTUBE_ROUTE_EVENTS) {
      window.addEventListener(eventName, handleYoutubeRouteChange);
    }

    window.addEventListener('keydown', handleKeyboardShortcut, true);
    browser.runtime.onMessage.addListener(handleRuntimeMessage);
    void hydrateContentState();

    updateOverlay();

    ctx.onInvalidated(() => {
      disposed = true;
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

      if (message.type === 'background.seekToCue') {
        if (subtitleState.type === 'ready') {
          const cue = subtitleState.track.cues.find((c) => c.cueId === message.cueId);
          if (cue) {
            if (message.mode === 'replay') replayCue(cue); else jumpToCue(cue);
          }
        }
        sendResponse({ ok: true });
        return false;
      }

      return false;
    }

    function handleKeyboardShortcut(event: KeyboardEvent): void {
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

        case 'save-current-cue':
          showSaveCuePlaceholder(activeCueFromState());
          return;
      }
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
      stopVideoStateListeners?.();
      stopWebVttTrack = null;
      stopVideoStateListeners = null;
      activeCue = null;
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

      const video = findActiveYoutubeVideo(document);

      if (!video) {
        webVttTrackLogger.videoMissing(track);
        updateOverlay();

        return;
      }

      bindVideoStateListeners(video);

      stopWebVttTrack = bindWebVttTrackToVideo({
        video,
        track,
        timingOffsetSeconds: settings.subtitleTimingOffsetSeconds,
        onCueChange(change) {
          activeCue = change.activeCue;
          updateOverlay();
          const page = parseYoutubePage(window.location.href);
          if (page.supported) {
            void browser.runtime.sendMessage({
              type: 'content.activeCueChanged',
              cueId: change.activeCue?.cueId ?? null,
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

      stopVideoStateListeners = () => {
        video.removeEventListener('play', clearStudyHoverPause);
        video.removeEventListener('playing', clearStudyHoverPause);
      };
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

    function showSaveCuePlaceholder(cue: SubtitleCue | null): void {
      if (!cue) {
        overlay.showActionStatus('No active cue to save.', 'error');

        return;
      }

      overlay.showActionStatus('Save cue is reserved for Phase 02.', 'info');
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
