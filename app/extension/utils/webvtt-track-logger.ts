/**
 * The identity a bound track can log. Finished tracks carry a trackId;
 * partial tracks from a still-running job do not.
 */
export interface WebVttTrackIdentity {
  trackId?: string;
  youtubeVideoId: string;
}

export interface WebVttTrackLoadContext {
  video: HTMLVideoElement;
  textTrack: TextTrack;
  track: WebVttTrackIdentity;
}

export interface WebVttTrackLogger {
  videoMissing: (track: WebVttTrackIdentity) => void;
  trackLoaded: (context: WebVttTrackLoadContext) => void;
  trackLoadError: (track: WebVttTrackIdentity) => void;
}

const TRACK_OVERRUN_MIN_SECONDS = 5;

export function createWebVttTrackLogger(consoleRef: Pick<Console, 'info' | 'warn'> = console): WebVttTrackLogger {
  return {
    videoMissing(track) {
      consoleRef.warn('extension.webvtt_track_video_missing', {
        type: 'video_missing',
        trackId: track.trackId,
        youtubeVideoId: track.youtubeVideoId,
      });
    },

    trackLoaded({ video, textTrack, track }) {
      if (!Number.isFinite(video.duration) || video.duration <= 0 || !textTrack.cues?.length) {
        return;
      }

      const lastCue = textTrack.cues[textTrack.cues.length - 1];
      if (!lastCue) return;
      const trackDurationSeconds = lastCue.endTime;
      const overrunSeconds = trackDurationSeconds - video.duration;

      if (overrunSeconds <= TRACK_OVERRUN_MIN_SECONDS) {
        return;
      }

      consoleRef.info('extension.webvtt_track_duration_overrun', {
        type: 'duration_overrun',
        trackId: track.trackId,
        youtubeVideoId: track.youtubeVideoId,
        videoDurationSeconds: roundSeconds(video.duration),
        trackDurationSeconds: roundSeconds(trackDurationSeconds),
        deltaSeconds: roundSeconds(overrunSeconds),
      });
    },

    trackLoadError(track) {
      consoleRef.warn('extension.webvtt_track_track_load_error', {
        type: 'track_load_error',
        trackId: track.trackId,
        youtubeVideoId: track.youtubeVideoId,
      });
    },
  };
}

export const webVttTrackLogger = createWebVttTrackLogger();

function roundSeconds(seconds: number): number {
  return Math.round(seconds * 1000) / 1000;
}
