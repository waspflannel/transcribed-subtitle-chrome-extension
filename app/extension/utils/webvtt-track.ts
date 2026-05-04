import type { TrackResponse } from './contracts';

export interface WebVttCueChange {
  activeSourceText: string | null;
}

export type WebVttTrackDiagnostic =
  | {
      type: 'duration_mismatch';
      trackId: string;
      youtubeVideoId: string;
      videoDurationSeconds: number;
      trackDurationSeconds: number;
      deltaSeconds: number;
    }
  | {
      type: 'track_load_error';
      trackId: string;
      youtubeVideoId: string;
    };

export interface WebVttVideoTrackOptions {
  video: HTMLVideoElement;
  track: TrackResponse;
  onCueChange: (change: WebVttCueChange) => void;
  onDiagnostic?: (diagnostic: WebVttTrackDiagnostic) => void;
}

const DURATION_MISMATCH_MIN_SECONDS = 5;
const DURATION_MISMATCH_RATIO = 0.05;

export function bindWebVttTrackToVideo(options: WebVttVideoTrackOptions): () => void {
  const { video, track, onCueChange, onDiagnostic } = options;
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

    onCueChange({
      activeSourceText: activeCueText(textTrack),
    });
  };

  const handleLoad = (): void => {
    emitDurationDiagnostic(video, textTrack, track, onDiagnostic);
    emitCueChange();
  };

  const handleError = (): void => {
    onDiagnostic?.({
      type: 'track_load_error',
      trackId: track.trackId,
      youtubeVideoId: track.youtubeVideoId,
    });
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

function activeCueText(textTrack: TextTrack): string | null {
  const cue = textTrack.activeCues?.[0] ?? null;

  if (!cue || !('text' in cue)) {
    return null;
  }

  const text = String((cue as VTTCue).text ?? '').trim();

  return text === '' ? null : text;
}

function emitDurationDiagnostic(
  video: HTMLVideoElement,
  textTrack: TextTrack,
  track: TrackResponse,
  onDiagnostic?: (diagnostic: WebVttTrackDiagnostic) => void,
): void {
  if (!onDiagnostic || !Number.isFinite(video.duration) || video.duration <= 0 || !textTrack.cues?.length) {
    return;
  }

  const lastCue = textTrack.cues[textTrack.cues.length - 1];
  const trackDurationSeconds = lastCue.endTime;
  const deltaSeconds = Math.abs(video.duration - trackDurationSeconds);

  if (deltaSeconds <= Math.max(DURATION_MISMATCH_MIN_SECONDS, video.duration * DURATION_MISMATCH_RATIO)) {
    return;
  }

  onDiagnostic({
    type: 'duration_mismatch',
    trackId: track.trackId,
    youtubeVideoId: track.youtubeVideoId,
    videoDurationSeconds: roundSeconds(video.duration),
    trackDurationSeconds: roundSeconds(trackDurationSeconds),
    deltaSeconds: roundSeconds(deltaSeconds),
  });
}

function roundSeconds(seconds: number): number {
  return Math.round(seconds * 1000) / 1000;
}
