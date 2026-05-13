import { storage } from 'wxt/utils/storage';

import type { TrackResponse } from './contracts';

const MAX_STORED_TRACKS = 5;

const activeTracksStorage = storage.defineItem<Record<string, TrackResponse>>('local:activeTracksByVideoId', {
  fallback: {},
});

export async function rememberActiveTrack(track: TrackResponse): Promise<void> {
  const storedTracks = pruneExpiredTracks(await activeTracksStorage.getValue());
  const nextTracks = {
    ...storedTracks,
    [track.youtubeVideoId]: track,
  };

  await activeTracksStorage.setValue(limitStoredTracks(nextTracks));
}

export async function getRememberedTrack(youtubeVideoId: string): Promise<TrackResponse | null> {
  const storedTracks = await activeTracksStorage.getValue();
  const track = storedTracks[youtubeVideoId];

  if (!track) {
    return null;
  }

  if (isExpired(track)) {
    delete storedTracks[youtubeVideoId];
    await activeTracksStorage.setValue(storedTracks);

    return null;
  }

  return track;
}

export async function clearRememberedTracks(): Promise<void> {
  await activeTracksStorage.removeValue();
}

function limitStoredTracks(tracks: Record<string, TrackResponse>): Record<string, TrackResponse> {
  return Object.fromEntries(
    Object.entries(tracks)
      .sort(([, first], [, second]) => Date.parse(second.generatedAt) - Date.parse(first.generatedAt))
      .slice(0, MAX_STORED_TRACKS),
  );
}

function pruneExpiredTracks(tracks: Record<string, TrackResponse>): Record<string, TrackResponse> {
  return Object.fromEntries(Object.entries(tracks).filter(([, track]) => !isExpired(track)));
}

function isExpired(track: TrackResponse): boolean {
  const expiresAtMs = Date.parse(track.expiresAt);

  return !Number.isFinite(expiresAtMs) || expiresAtMs <= Date.now();
}
