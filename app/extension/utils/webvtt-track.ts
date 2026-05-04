import type { TrackResponse } from './contracts';
import type { WebVttTrackLogger } from './webvtt-track-logger';

export interface WebVttCueChange {
  activeSourceText: string | null;
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

    onCueChange({
      activeSourceText: activeCueText(textTrack),
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

function activeCueText(textTrack: TextTrack): string | null {
  const cue = textTrack.activeCues?.[0] ?? null;

  if (!cue || !('text' in cue)) {
    return null;
  }

  const text = String((cue as VTTCue).text ?? '').trim();

  return text === '' ? null : text;
}
