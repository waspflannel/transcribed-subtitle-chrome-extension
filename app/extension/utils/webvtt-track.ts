import type { SubtitleCue, TrackResponse } from './contracts';
import type { WebVttTrackLogger } from './webvtt-track-logger';

export interface WebVttCueChange {
  activeCue: SubtitleCue | null;
}

export interface WebVttVideoTrackOptions {
  video: HTMLVideoElement;
  track: TrackResponse;
  onCueChange: (change: WebVttCueChange) => void;
  logger?: Pick<WebVttTrackLogger, 'trackLoaded' | 'trackLoadError'>;
  timingOffsetSeconds?: number;
}

export function bindWebVttTrackToVideo(options: WebVttVideoTrackOptions): () => void {
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

export function offsetTrackTiming(track: TrackResponse, offsetSeconds: number): TrackResponse {
  if (!Number.isFinite(offsetSeconds) || offsetSeconds === 0) {
    return track;
  }

  const offsetMs = Math.round(offsetSeconds * 1000);

  return {
    ...track,
    webVtt: offsetWebVtt(track.webVtt, offsetMs),
    cues: track.cues.map((cue) => {
      const startMs = shiftedMilliseconds(cue.startMs, offsetMs);
      const endMs = Math.max(startMs + 1, shiftedMilliseconds(cue.endMs, offsetMs));

      return {
        ...cue,
        startMs,
        endMs,
      };
    }) as TrackResponse['cues'],
  };
}

function offsetWebVtt(webVtt: string, offsetMs: number): string {
  return webVtt
    .split('\n')
    .map((line) => {
      if (!line.includes('-->')) {
        return line;
      }

      return line.replace(/\b(?:(\d+):)?(\d{2}):(\d{2})\.(\d{3})\b/g, (timestamp) =>
        formatTimestamp(shiftedMilliseconds(parseTimestampMilliseconds(timestamp), offsetMs)),
      );
    })
    .join('\n');
}

function parseTimestampMilliseconds(timestamp: string): number {
  const parts = timestamp.split(':');
  const secondsPart = parts.pop() ?? '0.000';
  const seconds = Number(secondsPart);
  const minutes = Number(parts.pop() ?? 0);
  const hours = Number(parts.pop() ?? 0);

  return Math.round(((hours * 3600) + (minutes * 60) + seconds) * 1000);
}

function shiftedMilliseconds(milliseconds: number, offsetMs: number): number {
  return Math.max(0, milliseconds + offsetMs);
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

function findTrackCue(track: TrackResponse, textCue: VTTCue): SubtitleCue | null {
  const startMs = Math.round(textCue.startTime * 1000);
  const endMs = Math.round(textCue.endTime * 1000);
  const text = textCue.text.trim();
  const timingMatch = track.cues.find(
    (cue) => Math.abs(cue.startMs - startMs) <= 25 && Math.abs(cue.endMs - endMs) <= 25,
  );

  if (timingMatch) {
    return timingMatch;
  }

  return track.cues.find((cue) => cue.sourceText.trim() === text) ?? null;
}
