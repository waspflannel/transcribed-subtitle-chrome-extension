import type { SubtitleCue } from './contracts';
import type { WebVttTrackLogger } from './webvtt-track-logger';

/**
 * Minimum cue shape the video binding needs. Both finished track cues and
 * partial cues from a still-running job satisfy it.
 */
export interface WebVttBindableCue {
  cueId: string;
  startMs: number;
  endMs: number;
  sourceText: string;
}

/**
 * Minimum track shape the video binding needs. TrackResponse satisfies it;
 * partial tracks are composed locally from partial-track responses.
 */
export interface WebVttBindableTrack<TCue extends WebVttBindableCue = SubtitleCue> {
  youtubeVideoId: string;
  sourceLanguage: string;
  webVtt: string;
  cues: readonly TCue[];
}

export interface WebVttCueChange<TCue extends WebVttBindableCue = SubtitleCue> {
  activeCue: TCue | null;
}

export interface WebVttVideoTrackOptions<TCue extends WebVttBindableCue> {
  video: HTMLVideoElement;
  track: WebVttBindableTrack<TCue>;
  onCueChange: (change: WebVttCueChange<TCue>) => void;
  logger?: Pick<WebVttTrackLogger, 'trackLoaded' | 'trackLoadError'>;
  timingOffsetSeconds?: number;
}

export function bindWebVttTrackToVideo<TCue extends WebVttBindableCue>(
  options: WebVttVideoTrackOptions<TCue>,
): () => void {
  const { video, onCueChange, logger } = options;
  const track = offsetTrackTiming(options.track, options.timingOffsetSeconds ?? 0);
  const trackElement = video.ownerDocument.createElement('track');
  const objectUrl = URL.createObjectURL(new Blob([track.webVtt], { type: 'text/vtt' }));
  let disposed = false;

  trackElement.kind = 'subtitles';
  trackElement.label = 'AI subtitles';
  trackElement.srclang = track.sourceLanguage === 'auto' ? 'und' : track.sourceLanguage;
  trackElement.src = objectUrl;

  const textTrack = trackElement.track;

  const emitCueChange = (): void => {
    if (disposed) {
      return;
    }

    const activeTextCue = findActiveTextCue(textTrack);

    onCueChange({
      activeCue: activeTextCue ? findTrackCue(track, activeTextCue) : null,
    });
  };

  const handleLoad = (): void => {
    logger?.trackLoaded({ video, textTrack, track });
    emitCueChange();
  };

  const handleError = (): void => {
    logger?.trackLoadError(track);
  };

  textTrack.mode = 'hidden';
  textTrack.addEventListener('cuechange', emitCueChange);
  trackElement.addEventListener('load', handleLoad);
  trackElement.addEventListener('error', handleError);
  video.append(trackElement);
  emitCueChange();

  return () => {
    disposed = true;
    textTrack.removeEventListener('cuechange', emitCueChange);
    trackElement.removeEventListener('load', handleLoad);
    trackElement.removeEventListener('error', handleError);
    trackElement.remove();
    URL.revokeObjectURL(objectUrl);
  };
}

export function offsetTrackTiming<TTrack extends WebVttBindableTrack<WebVttBindableCue>>(
  track: TTrack,
  offsetSeconds: number,
): TTrack {
  if (!Number.isFinite(offsetSeconds) || offsetSeconds === 0) {
    return track;
  }

  const offsetMs = Math.round(offsetSeconds * 1000);
  const shifted: WebVttBindableCue[] = [];
  let previousEnd = Number.NEGATIVE_INFINITY;

  for (const cue of track.cues) {
    let startMs = cue.startMs + offsetMs;
    let endMs = cue.endMs + offsetMs;

    if (endMs <= 0) {
      continue; // entirely before the timeline start: drop
    }

    if (startMs < 0) {
      startMs = 0; // partially visible: clamp the leading edge
    }

    if (startMs < previousEnd) {
      startMs = previousEnd; // repair overlaps introduced by clamping/rounding
    }

    if (endMs <= startMs) {
      endMs = startMs + 1;
    }

    shifted.push({ ...cue, startMs, endMs });
    previousEnd = endMs;
  }

  return {
    ...track,
    cues: shifted as unknown as TTrack['cues'],
    webVtt: buildWebVttFromCues(shifted),
  };
}

export function buildWebVttFromCues(cues: readonly WebVttBindableCue[]): string {
  const blocks = ['WEBVTT'];

  for (const cue of cues) {
    blocks.push(`${cue.cueId}\n${formatTimestamp(cue.startMs)} --> ${formatTimestamp(cue.endMs)}\n${cue.sourceText}`);
  }

  return `${blocks.join('\n\n')}\n`;
}

function formatTimestamp(milliseconds: number): string {
  const hours = Math.floor(milliseconds / 3_600_000);
  const minutes = Math.floor((milliseconds % 3_600_000) / 60_000);
  const seconds = Math.floor((milliseconds % 60_000) / 1000);
  const ms = milliseconds % 1000;

  return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}.${String(ms).padStart(3, '0')}`;
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

function findActiveTextCue(textTrack: TextTrack): VTTCue | null {
  const cue = textTrack.activeCues?.[0] ?? null;

  if (!cue || !('text' in cue)) {
    return null;
  }

  const text = String((cue as VTTCue).text ?? '').trim();

  return text === '' ? null : (cue as VTTCue);
}

function findTrackCue<TCue extends WebVttBindableCue>(
  track: WebVttBindableTrack<TCue>,
  textCue: VTTCue,
): TCue | null {
  const id = typeof textCue.id === 'string' ? textCue.id : '';

  if (id !== '') {
    const byId = track.cues.find((cue) => cue.cueId === id);

    if (byId) {
      return byId;
    }
  }

  // Fallback for tracks whose VTT cues carry no stable ids.
  const startMs = Math.round(textCue.startTime * 1000);
  const endMs = Math.round(textCue.endTime * 1000);

  return track.cues.find(
    (cue) => Math.abs(cue.startMs - startMs) <= 25 && Math.abs(cue.endMs - endMs) <= 25,
  ) ?? null;
}
