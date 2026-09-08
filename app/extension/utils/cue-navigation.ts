import type { SubtitleCue } from './contracts';

export type CueNavigationDirection = 'previous' | 'next';

export function cueForPlaybackTime(
  track: { cues: readonly SubtitleCue[] },
  currentTimeSeconds: number,
  timingOffsetSeconds = 0,
): SubtitleCue | null {
  if (!Number.isFinite(currentTimeSeconds)) {
    return null;
  }

  const sourceTimeMs = playbackTimeToSourceMilliseconds(currentTimeSeconds, timingOffsetSeconds);

  return (
    track.cues.find((cue) => sourceTimeMs >= cue.startMs && sourceTimeMs < cue.endMs) ??
    findLastCueEndingAt(track.cues, sourceTimeMs)
  );
}

function findLastCueEndingAt(cues: readonly SubtitleCue[], sourceTimeMs: number): SubtitleCue | null {
  for (let i = cues.length - 1; i >= 0; i -= 1) {
    if (cues[i].endMs === sourceTimeMs) {
      return cues[i];
    }
  }

  return null;
}

export function cueForNavigation(options: {
  track: { cues: readonly SubtitleCue[] };
  activeCue: SubtitleCue | null;
  currentTimeSeconds: number | null;
  timingOffsetSeconds?: number;
  direction: CueNavigationDirection;
}): SubtitleCue | null {
  const cues = options.track.cues;

  if (cues.length === 0) {
    return null;
  }

  const activeIndex = activeCueIndex(cues, options.activeCue);

  if (activeIndex >= 0) {
    if (options.direction === 'previous') {
      return cues[Math.max(0, activeIndex - 1)] ?? cues[0] ?? null;
    }

    return cues[Math.min(cues.length - 1, activeIndex + 1)] ?? cues[cues.length - 1] ?? null;
  }

  return cueForNavigationFromPlaybackTime(
    cues,
    options.currentTimeSeconds,
    options.timingOffsetSeconds ?? 0,
    options.direction,
  );
}

export function cueStartPlaybackSeconds(cue: SubtitleCue, timingOffsetSeconds = 0): number {
  return Math.max(0, (cue.startMs / 1000) + timingOffsetSeconds);
}

function activeCueIndex(cues: readonly SubtitleCue[], activeCue: SubtitleCue | null): number {
  if (!activeCue) {
    return -1;
  }

  return cues.findIndex((cue) => cue.cueId === activeCue.cueId);
}

function cueForNavigationFromPlaybackTime(
  cues: readonly SubtitleCue[],
  currentTimeSeconds: number | null,
  timingOffsetSeconds: number,
  direction: CueNavigationDirection,
): SubtitleCue | null {
  if (typeof currentTimeSeconds !== 'number' || !Number.isFinite(currentTimeSeconds)) {
    return cues[0] ?? null;
  }

  const sourceTimeMs = playbackTimeToSourceMilliseconds(currentTimeSeconds, timingOffsetSeconds);
  const matchingCueIndex = cues.findIndex((cue) => sourceTimeMs >= cue.startMs && sourceTimeMs < cue.endMs);

  if (matchingCueIndex >= 0) {
    return direction === 'previous'
      ? cues[Math.max(0, matchingCueIndex - 1)] ?? null
      : cues[Math.min(cues.length - 1, matchingCueIndex + 1)] ?? null;
  }

  if (direction === 'previous') {
    return [...cues].reverse().find((cue) => cue.endMs < sourceTimeMs) ?? cues[0] ?? null;
  }

  return cues.find((cue) => cue.startMs > sourceTimeMs) ?? cues[cues.length - 1] ?? null;
}

function playbackTimeToSourceMilliseconds(currentTimeSeconds: number, timingOffsetSeconds: number): number {
  return Math.round((currentTimeSeconds - timingOffsetSeconds) * 1000);
}
