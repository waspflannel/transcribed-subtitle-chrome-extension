import { storage } from 'wxt/utils/storage';

import type { TrackResponse } from './contracts';
import type { PartialSubtitleTrack } from './messages';

const MAX_STORED_TRACKS = 5;

interface RememberedTrack {
  accountId: string;
  track: TrackResponse;
}

const activeTracksStorage = storage.defineItem<Record<string, RememberedTrack>>('local:activeTracksByVideoId', {
  fallback: {},
});

export interface StoredTabOperation {
  kind: 'generation' | 'correction';
  accountId: string;
  youtubeVideoId: string;
  jobId?: string;
  trackId?: string;
  attemptId?: string;
  partialTrack?: PartialSubtitleTrack;
}

const tabOperationsStorage = storage.defineItem<Record<string, StoredTabOperation>>('local:tabSubtitleOperations', {
  fallback: {},
});

export async function rememberActiveTrack(track: TrackResponse, accountId: string): Promise<void> {
  const storedTracks = pruneExpiredTracks(await activeTracksStorage.getValue());
  const nextTracks = {
    ...storedTracks,
    [track.youtubeVideoId]: { accountId, track },
  };

  await activeTracksStorage.setValue(limitStoredTracks(nextTracks));
}

export async function getRememberedTrack(youtubeVideoId: string, accountId: string): Promise<TrackResponse | null> {
  const storedTracks = await activeTracksStorage.getValue();
  const remembered = storedTracks[youtubeVideoId];

  if (!isRememberedTrack(remembered) || remembered.accountId !== accountId) {
    return null;
  }

  if (isExpired(remembered.track)) {
    delete storedTracks[youtubeVideoId];
    await activeTracksStorage.setValue(storedTracks);

    return null;
  }

  return remembered.track;
}

export async function forgetRememberedTrack(youtubeVideoId: string, expectedTrackId: string, accountId: string): Promise<void> {
  const storedTracks = await activeTracksStorage.getValue();

  if (storedTracks[youtubeVideoId]?.accountId === accountId && storedTracks[youtubeVideoId]?.track.trackId === expectedTrackId) {
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

function limitStoredTracks(tracks: Record<string, RememberedTrack>): Record<string, RememberedTrack> {
  return Object.fromEntries(
    Object.entries(tracks)
      .sort(([, first], [, second]) => Date.parse(second.track.generatedAt) - Date.parse(first.track.generatedAt))
      .slice(0, MAX_STORED_TRACKS),
  );
}

function pruneExpiredTracks(tracks: Record<string, RememberedTrack>): Record<string, RememberedTrack> {
  return Object.fromEntries(Object.entries(tracks).filter(([, remembered]) => isRememberedTrack(remembered) && !isExpired(remembered.track)));
}

function isRememberedTrack(value: unknown): value is RememberedTrack {
  if (typeof value !== 'object' || value === null) return false;

  const remembered = value as Partial<RememberedTrack>;

  return typeof remembered.accountId === 'string'
    && remembered.accountId !== ''
    && typeof remembered.track === 'object'
    && remembered.track !== null;
}

function isExpired(track: TrackResponse): boolean {
  const expiresAtMs = Date.parse(track.expiresAt);

  return !Number.isFinite(expiresAtMs) || expiresAtMs <= Date.now();
}
