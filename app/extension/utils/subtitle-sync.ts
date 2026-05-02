import type { SubtitleCue, TrackResponse } from './contracts';

export type SubtitleSyncReason =
  | 'initial'
  | 'timeupdate'
  | 'play'
  | 'pause'
  | 'seeking'
  | 'seeked'
  | 'ratechange'
  | 'durationchange'
  | 'animationFrame';

export interface SubtitleCueChange {
  cue: SubtitleCue | null;
  currentTimeSeconds: number;
  reason: SubtitleSyncReason;
}

export type SubtitleSyncDiagnostic =
  | {
      type: 'duration_mismatch';
      trackId: string;
      youtubeVideoId: string;
      videoDurationSeconds: number;
      trackDurationSeconds: number;
      deltaSeconds: number;
    }
  | {
      type: 'missing_cue_gap';
      trackId: string;
      youtubeVideoId: string;
      currentTimeSeconds: number;
      previousCueId: string;
      nextCueId: string;
      gapMs: number;
    };

export interface SubtitleVideoSyncOptions {
  video: HTMLVideoElement;
  track: TrackResponse;
  onCueChange: (change: SubtitleCueChange) => void;
  onDiagnostic?: (diagnostic: SubtitleSyncDiagnostic) => void;
}

const DURATION_MISMATCH_MIN_SECONDS = 5;
const DURATION_MISMATCH_RATIO = 0.05;
const DIAGNOSTIC_GAP_MIN_MS = 1500;

export function findActiveCue(cues: readonly SubtitleCue[], currentTimeSeconds: number): SubtitleCue | null {
  if (!Number.isFinite(currentTimeSeconds) || cues.length === 0) {
    return null;
  }

  const currentMs = Math.max(0, Math.floor(currentTimeSeconds * 1000));
  let low = 0;
  let high = cues.length - 1;

  while (low <= high) {
    const mid = Math.floor((low + high) / 2);
    const cue = cues[mid];

    if (currentMs < cue.startMs) {
      high = mid - 1;
    } else if (currentMs >= cue.endMs) {
      low = mid + 1;
    } else {
      return cue;
    }
  }

  return null;
}

export function bindSubtitleTrackToVideo(options: SubtitleVideoSyncOptions): () => void {
  const { video, track, onCueChange, onDiagnostic } = options;
  const windowRef = video.ownerDocument.defaultView ?? window;
  let disposed = false;
  let frameId: number | null = null;
  let lastCueId: string | null | undefined;
  let durationDiagnosticLogged = false;
  let lastMissingGapKey: string | null = null;

  const sync = (reason: SubtitleSyncReason): void => {
    if (disposed) {
      return;
    }

    const currentTimeSeconds = Number.isFinite(video.currentTime) ? video.currentTime : 0;
    const cue = findActiveCue(track.cues, currentTimeSeconds);
    const cueId = cue?.cueId ?? null;

    if (cueId !== lastCueId) {
      lastCueId = cueId;
      onCueChange({
        cue,
        currentTimeSeconds,
        reason,
      });
    }

    if (onDiagnostic) {
      if (!durationDiagnosticLogged) {
        durationDiagnosticLogged = emitDurationDiagnostic(video, track, onDiagnostic);
      }

      if (cue === null) {
        const gap = findMissingCueGap(track.cues, currentTimeSeconds);
        const gapKey = gap ? `${gap.previousCueId}:${gap.nextCueId}` : null;

        if (gap && gapKey !== lastMissingGapKey) {
          lastMissingGapKey = gapKey;
          onDiagnostic({
            type: 'missing_cue_gap',
            trackId: track.trackId,
            youtubeVideoId: track.youtubeVideoId,
            currentTimeSeconds: roundSeconds(currentTimeSeconds),
            ...gap,
          });
        }
      }
    }
  };

  const startLoop = (): void => {
    if (frameId !== null || disposed) {
      return;
    }

    frameId = windowRef.requestAnimationFrame(() => {
      frameId = null;
      sync('animationFrame');

      if (!video.paused && !video.ended) {
        startLoop();
      }
    });
  };

  const stopLoop = (): void => {
    if (frameId === null) {
      return;
    }

    windowRef.cancelAnimationFrame(frameId);
    frameId = null;
  };

  const handlePlay = (): void => {
    sync('play');
    startLoop();
  };
  const handlePause = (): void => {
    stopLoop();
    sync('pause');
  };
  const handleTimeUpdate = (): void => sync('timeupdate');
  const handleSeeking = (): void => sync('seeking');
  const handleSeeked = (): void => sync('seeked');
  const handleRateChange = (): void => sync('ratechange');
  const handleDurationChange = (): void => sync('durationchange');

  video.addEventListener('play', handlePlay);
  video.addEventListener('pause', handlePause);
  video.addEventListener('timeupdate', handleTimeUpdate);
  video.addEventListener('seeking', handleSeeking);
  video.addEventListener('seeked', handleSeeked);
  video.addEventListener('ratechange', handleRateChange);
  video.addEventListener('loadedmetadata', handleDurationChange);
  video.addEventListener('durationchange', handleDurationChange);

  sync('initial');

  if (!video.paused && !video.ended) {
    startLoop();
  }

  return () => {
    disposed = true;
    stopLoop();
    video.removeEventListener('play', handlePlay);
    video.removeEventListener('pause', handlePause);
    video.removeEventListener('timeupdate', handleTimeUpdate);
    video.removeEventListener('seeking', handleSeeking);
    video.removeEventListener('seeked', handleSeeked);
    video.removeEventListener('ratechange', handleRateChange);
    video.removeEventListener('loadedmetadata', handleDurationChange);
    video.removeEventListener('durationchange', handleDurationChange);
  };
}

function emitDurationDiagnostic(
  video: HTMLVideoElement,
  track: TrackResponse,
  onDiagnostic: (diagnostic: SubtitleSyncDiagnostic) => void,
): boolean {
  if (!Number.isFinite(video.duration) || video.duration <= 0) {
    return false;
  }

  const trackDurationSeconds = track.cues[track.cues.length - 1].endMs / 1000;
  const deltaSeconds = Math.abs(video.duration - trackDurationSeconds);

  if (deltaSeconds <= Math.max(DURATION_MISMATCH_MIN_SECONDS, video.duration * DURATION_MISMATCH_RATIO)) {
    return true;
  }

  onDiagnostic({
    type: 'duration_mismatch',
    trackId: track.trackId,
    youtubeVideoId: track.youtubeVideoId,
    videoDurationSeconds: roundSeconds(video.duration),
    trackDurationSeconds: roundSeconds(trackDurationSeconds),
    deltaSeconds: roundSeconds(deltaSeconds),
  });

  return true;
}

function findMissingCueGap(
  cues: readonly SubtitleCue[],
  currentTimeSeconds: number,
): { previousCueId: string; nextCueId: string; gapMs: number } | null {
  const currentMs = Math.max(0, Math.floor(currentTimeSeconds * 1000));

  for (let index = 0; index < cues.length - 1; index++) {
    const previousCue = cues[index];
    const nextCue = cues[index + 1];
    const gapMs = nextCue.startMs - previousCue.endMs;

    if (gapMs >= DIAGNOSTIC_GAP_MIN_MS && currentMs >= previousCue.endMs && currentMs < nextCue.startMs) {
      return {
        previousCueId: previousCue.cueId,
        nextCueId: nextCue.cueId,
        gapMs,
      };
    }
  }

  return null;
}

function roundSeconds(seconds: number): number {
  return Math.round(seconds * 1000) / 1000;
}
