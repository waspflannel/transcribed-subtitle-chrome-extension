import { storage } from 'wxt/utils/storage';

import type { TrackResponse } from './contracts';

const MAX_STORED_TRACKS = 5;

const activeTracksStorage = storage.defineItem<Record<string, TrackResponse>>('local:activeTracksByVideoId', {
  fallback: {},
});

export interface StoredTabOperation {
  kind: 'generation' | 'correction';
  youtubeVideoId: string;
  jobId?: string;
  trackId?: string;
  attemptId?: string;
}

const tabOperationsStorage = storage.defineItem<Record<string, StoredTabOperation>>('local:tabSubtitleOperations', {
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

export async function forgetRememberedTrack(youtubeVideoId: string, expectedTrackId: string): Promise<void> {
  const storedTracks = await activeTracksStorage.getValue();

  if (storedTracks[youtubeVideoId]?.trackId === expectedTrackId) {
    delete storedTracks[youtubeVideoId];
    await activeTracksStorage.setValue(storedTracks);
  }
}

export async function clearRememberedTracks(): Promise<void> {
  await activeTracksStorage.removeValue();
}

export async function getTabOperation(tabId: number): Promise<StoredTabOperation | null> {
  return (await tabOperationsStorage.getValue())[String(tabId)] ?? null;
}

export async function setTabOperation(tabId: number, operation: StoredTabOperation): Promise<void> {
  const operations = await tabOperationsStorage.getValue();
  operations[String(tabId)] = operation;
  await tabOperationsStorage.setValue(operations);
}

export async function clearTabOperation(tabId: number): Promise<void> {
  const operations = await tabOperationsStorage.getValue();

  if (operations[String(tabId)]) {
    delete operations[String(tabId)];
    await tabOperationsStorage.setValue(operations);
  }
}

export async function clearTabOperations(): Promise<void> {
  await tabOperationsStorage.removeValue();
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
