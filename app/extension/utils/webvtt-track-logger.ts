import type { TrackResponse } from './contracts';

export interface WebVttTrackLoadContext {
  video: HTMLVideoElement;
  textTrack: TextTrack;
  track: TrackResponse;
}

export interface WebVttTrackLogger {
  videoMissing: (track: TrackResponse) => void;
  trackLoaded: (context: WebVttTrackLoadContext) => void;
  trackLoadError: (track: TrackResponse) => void;
}

const DURATION_MISMATCH_MIN_SECONDS = 5;
const DURATION_MISMATCH_RATIO = 0.05;

export function createWebVttTrackLogger(consoleRef: Pick<Console, 'warn'> = console): WebVttTrackLogger {
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
      const trackDurationSeconds = lastCue.endTime;
      const deltaSeconds = Math.abs(video.duration - trackDurationSeconds);

      if (deltaSeconds <= Math.max(DURATION_MISMATCH_MIN_SECONDS, video.duration * DURATION_MISMATCH_RATIO)) {
        return;
      }

      consoleRef.warn('extension.webvtt_track_duration_mismatch', {
        type: 'duration_mismatch',
        trackId: track.trackId,
        youtubeVideoId: track.youtubeVideoId,
        videoDurationSeconds: roundSeconds(video.duration),
        trackDurationSeconds: roundSeconds(trackDurationSeconds),
        deltaSeconds: roundSeconds(deltaSeconds),
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
