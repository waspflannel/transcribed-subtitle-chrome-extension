import type { LyricsCorrectionStatus, TrackResponse } from './contracts';

export const LYRICS_CHARACTER_LIMIT = 25000;

export function lyricsCharacterCount(value: string): number {
  return Array.from(value).length;
}

export function canApplyLyricsCorrection(
  value: string,
  status: LyricsCorrectionStatus | null | undefined,
): boolean {
  const count = lyricsCharacterCount(value);

  return count > 0 && count <= LYRICS_CHARACTER_LIMIT && status?.status !== 'queued' && status?.status !== 'running';
}

export function acceptLyricsCorrectionTrack(
  currentTrack: TrackResponse,
  status: LyricsCorrectionStatus,
  activeAttemptId: string | null,
): TrackResponse | null {
  if (status.status !== 'completed' || status.attemptId !== activeAttemptId || !status.track) {
    return null;
  }

  return status.track.trackId === currentTrack.trackId ? null : status.track;
}
