import { storage } from 'wxt/utils/storage';

import type { TrackResponse } from './contracts';
import type { PartialSubtitleTrack } from './messages';

const MAX_STORED_TRACKS = 5;

interface RememberedTrack {
  instanceId: string;
  track: TrackResponse;
  /** Epoch ms of the last remember. Entries saved before this field fall back to generatedAt. */
  rememberedAt?: number;
}

const activeTracksStorage = storage.defineItem<Record<string, RememberedTrack>>('local:activeTracksByVideoId', {
  fallback: {},
});

export interface StoredTabOperation {
  kind: 'generation' | 'correction';
  instanceId: string;
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

export async function rememberActiveTrack(track: TrackResponse, instanceId: string): Promise<void> {
  const epoch = rememberedTracksEpoch;
  const write = rememberedTracksQueue.then(async () => {
    if (epoch !== rememberedTracksEpoch) return;

    const storedTracks = pruneExpiredTracks(await activeTracksStorage.getValue());
    const nextTracks = {
      ...storedTracks,
      [track.youtubeVideoId]: { instanceId, track, rememberedAt: Date.now() },
    };

    await activeTracksStorage.setValue(limitStoredTracks(nextTracks));
  });
  rememberedTracksQueue = write.then(() => undefined, () => undefined);
  try {
    await write;
  } catch (error) {
    // The cache only speeds up page entry. A failed write must not stop the
    // caller from showing the track it already has.
    console.warn('extension.remembered_track_write_failed', {
      youtubeVideoId: track.youtubeVideoId,
      trackId: track.trackId,
      error: error instanceof Error ? error.message : 'Unknown storage error',
    });
  }
}

export async function getRememberedTrack(youtubeVideoId: string, instanceId: string): Promise<TrackResponse | null> {
  const epoch = rememberedTracksEpoch;
  const readOperation = rememberedTracksQueue.then(async () => {
    const storedTracks = await activeTracksStorage.getValue();
    const remembered = storedTracks[youtubeVideoId];

    if (!isRememberedTrack(remembered) || remembered.instanceId !== instanceId) {
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

export async function forgetRememberedTrack(youtubeVideoId: string, expectedTrackId: string, instanceId: string): Promise<void> {
  const write = rememberedTracksQueue.then(async () => {
    const storedTracks = await activeTracksStorage.getValue();

    if (storedTracks[youtubeVideoId]?.instanceId === instanceId && storedTracks[youtubeVideoId]?.track.trackId === expectedTrackId) {
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

export async function clearTabOperationIfMatches(tabId: number, expected: StoredTabOperation): Promise<boolean> {
  const clear = tabOperationsQueue.then(async () => {
    const operations = await tabOperationsStorage.getValue();
    const current = operations[String(tabId)];

    if (JSON.stringify(current) !== JSON.stringify(expected)) return false;

    delete operations[String(tabId)];
    await tabOperationsStorage.setValue(operations);

    return true;
  });
  tabOperationsQueue = clear.then(() => undefined, () => undefined);

  return clear;
}

export async function updateTabOperationIfMatches(
  tabId: number,
  expected: StoredTabOperation,
  next: StoredTabOperation,
): Promise<boolean> {
  const update = tabOperationsQueue.then(async () => {
    const operations = await tabOperationsStorage.getValue();
    const current = operations[String(tabId)];

    if (JSON.stringify(current) !== JSON.stringify(expected)) return false;

    operations[String(tabId)] = next;
    await tabOperationsStorage.setValue(operations);

    return true;
  });
  tabOperationsQueue = update.then(() => undefined, () => undefined);

  return update;
}

export async function clearTabOperations(): Promise<void> {
  tabOperationsEpoch += 1;
  const clear = tabOperationsQueue.then(() => tabOperationsStorage.removeValue());
  tabOperationsQueue = clear.then(() => undefined, () => undefined);
  await clear;
}

/** Keeps the most recently remembered tracks, not the most recently generated ones. */
function limitStoredTracks(tracks: Record<string, RememberedTrack>): Record<string, RememberedTrack> {
  const lastUsed = (remembered: RememberedTrack): number =>
    remembered.rememberedAt ?? (Date.parse(remembered.track.generatedAt) || 0);

  return Object.fromEntries(
    Object.entries(tracks)
      .sort(([, first], [, second]) => lastUsed(second) - lastUsed(first))
      .slice(0, MAX_STORED_TRACKS),
  );
}

function pruneExpiredTracks(tracks: Record<string, RememberedTrack>): Record<string, RememberedTrack> {
  return Object.fromEntries(Object.entries(tracks).filter(([, remembered]) => isRememberedTrack(remembered) && !isExpired(remembered.track)));
}

function isRememberedTrack(value: unknown): value is RememberedTrack {
  if (typeof value !== 'object' || value === null) return false;

  const remembered = value as Partial<RememberedTrack>;

  return typeof remembered.instanceId === 'string'
    && remembered.instanceId !== ''
    && typeof remembered.track === 'object'
    && remembered.track !== null;
}

function isExpired(track: TrackResponse): boolean {
  if (track.expiresAt === null) return false;
  const expiresAtMs = Date.parse(track.expiresAt);

  return !Number.isFinite(expiresAtMs) || expiresAtMs <= Date.now();
}
