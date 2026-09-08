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
let rememberedTracksQueue = Promise.resolve();
let tabOperationsQueue = Promise.resolve();
let rememberedTracksEpoch = 0;
let tabOperationsEpoch = 0;

export async function rememberActiveTrack(track: TrackResponse, accountId: string): Promise<void> {
  const epoch = rememberedTracksEpoch;
  const write = rememberedTracksQueue.then(async () => {
    if (epoch !== rememberedTracksEpoch) return;

    const storedTracks = pruneExpiredTracks(await activeTracksStorage.getValue());
    const nextTracks = {
      ...storedTracks,
      [track.youtubeVideoId]: { accountId, track },
    };

    await activeTracksStorage.setValue(limitStoredTracks(nextTracks));
  });
  rememberedTracksQueue = write.then(() => undefined, () => undefined);
  await write;
}

export async function getRememberedTrack(youtubeVideoId: string, accountId: string): Promise<TrackResponse | null> {
  const epoch = rememberedTracksEpoch;
  const readOperation = rememberedTracksQueue.then(async () => {
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
  });
  rememberedTracksQueue = readOperation.then(() => undefined, () => undefined);

  return readOperation.then((track) => epoch === rememberedTracksEpoch ? track : null);
}

export async function forgetRememberedTrack(youtubeVideoId: string, expectedTrackId: string, accountId: string): Promise<void> {
  const write = rememberedTracksQueue.then(async () => {
    const storedTracks = await activeTracksStorage.getValue();

    if (storedTracks[youtubeVideoId]?.accountId === accountId && storedTracks[youtubeVideoId]?.track.trackId === expectedTrackId) {
      delete storedTracks[youtubeVideoId];
      await activeTracksStorage.setValue(storedTracks);
    }
  });
  rememberedTracksQueue = write.then(() => undefined, () => undefined);
  await write;
}

export async function clearRememberedTracks(): Promise<void> {
  rememberedTracksEpoch += 1;
  const clear = rememberedTracksQueue.then(() => activeTracksStorage.removeValue());
  rememberedTracksQueue = clear.then(() => undefined, () => undefined);
  await clear;
}

export async function getTabOperation(tabId: number): Promise<StoredTabOperation | null> {
  const epoch = tabOperationsEpoch;
  const readOperation = tabOperationsQueue.then(async () => (await tabOperationsStorage.getValue())[String(tabId)] ?? null);
  tabOperationsQueue = readOperation.then(() => undefined, () => undefined);

  return readOperation.then((operation) => epoch === tabOperationsEpoch ? operation : null);
}

export async function setTabOperation(tabId: number, operation: StoredTabOperation): Promise<void> {
  const epoch = tabOperationsEpoch;
  const write = tabOperationsQueue.then(async () => {
    if (epoch !== tabOperationsEpoch) return;

    const operations = await tabOperationsStorage.getValue();
    operations[String(tabId)] = operation;
    await tabOperationsStorage.setValue(operations);
  });
  tabOperationsQueue = write.then(() => undefined, () => undefined);
  await write;
}

export async function clearTabOperation(tabId: number): Promise<void> {
  const clear = tabOperationsQueue.then(async () => {
    const operations = await tabOperationsStorage.getValue();

    if (operations[String(tabId)]) {
      delete operations[String(tabId)];
      await tabOperationsStorage.setValue(operations);
    }
  });
  tabOperationsQueue = clear.then(() => undefined, () => undefined);
  await clear;
}

export async function clearTabOperations(): Promise<void> {
  tabOperationsEpoch += 1;
  const clear = tabOperationsQueue.then(() => tabOperationsStorage.removeValue());
  tabOperationsQueue = clear.then(() => undefined, () => undefined);
  await clear;
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
