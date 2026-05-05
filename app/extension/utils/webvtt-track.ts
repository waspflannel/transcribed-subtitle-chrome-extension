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
}

export function bindWebVttTrackToVideo(options: WebVttVideoTrackOptions): () => void {
  const { video, track, onCueChange, logger } = options;
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
