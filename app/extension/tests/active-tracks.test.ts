import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const storageState = vi.hoisted(() => ({ values: new Map<string, unknown>(), failWrites: false }));

vi.mock('wxt/utils/storage', () => ({
  storage: {
    defineItem<T>(key: string, options: { fallback: T }) {
      return {
        async getValue(): Promise<T> {
          return storageState.values.has(key) ? storageState.values.get(key) as T : options.fallback;
        },
        async setValue(value: T): Promise<void> {
          if (storageState.failWrites) throw new Error('QUOTA_BYTES quota exceeded');
          storageState.values.set(key, value);
        },
        async removeValue(): Promise<void> {
          storageState.values.delete(key);
        },
      };
    },
  },
}));

import type { TrackResponse } from '../utils/contracts';

describe('instance-scoped remembered tracks', () => {
  beforeEach(() => {
    storageState.values.clear();
    storageState.failWrites = false;
    vi.useFakeTimers({ now: new Date('2026-05-30T00:00:00Z') });
  });

  it('evicts the least recently remembered track, not the oldest generated one', async () => {
    const { getRememberedTrack, rememberActiveTrack } = await import('../utils/active-tracks');
    // Entries written before rememberedAt existed fall back to their generation time.
    storageState.values.set('local:activeTracksByVideoId', Object.fromEntries(['a', 'b', 'c', 'd', 'e'].map((videoId, index) => [
      videoId,
      { instanceId: 'instance-a', track: { ...trackResponse(), youtubeVideoId: videoId, generatedAt: `2026-05-2${index}T00:00:00Z` } },
    ])));
    const older = { ...trackResponse(), youtubeVideoId: 'older', generatedAt: '2026-01-01T00:00:00Z' };

    await rememberActiveTrack(older, 'instance-a');
    await expect(getRememberedTrack('older', 'instance-a')).resolves.toEqual(older);
    await expect(getRememberedTrack('a', 'instance-a')).resolves.toBeNull();

    vi.advanceTimersByTime(1000);
    await rememberActiveTrack({ ...trackResponse(), youtubeVideoId: 'newest', generatedAt: '2026-01-02T00:00:00Z' }, 'instance-a');
    await expect(getRememberedTrack('older', 'instance-a')).resolves.toEqual(older);
    await expect(getRememberedTrack('b', 'instance-a')).resolves.toBeNull();
  });

  it('logs a failed cache write instead of failing the caller', async () => {
    const { getRememberedTrack, rememberActiveTrack } = await import('../utils/active-tracks');
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    storageState.failWrites = true;

    await expect(rememberActiveTrack(trackResponse(), 'instance-a')).resolves.toBeUndefined();
    expect(warn).toHaveBeenCalledWith('extension.remembered_track_write_failed', expect.objectContaining({ trackId: 'track-1' }));

    storageState.failWrites = false;
    await rememberActiveTrack(trackResponse(), 'instance-a');
    await expect(getRememberedTrack('video-1', 'instance-a')).resolves.toEqual(trackResponse());
    warn.mockRestore();
  });

  afterEach(() => vi.useRealTimers());

  it('does not return one instance\'s track to another instance', async () => {
    const { getRememberedTrack, rememberActiveTrack } = await import('../utils/active-tracks');
    const track = trackResponse();

    await rememberActiveTrack(track, 'instance-a');

    await expect(getRememberedTrack(track.youtubeVideoId, 'instance-a')).resolves.toEqual(track);
    await expect(getRememberedTrack(track.youtubeVideoId, 'instance-b')).resolves.toBeNull();
  });

  it('keeps a track forever when retention is disabled', async () => {
    const { getRememberedTrack, rememberActiveTrack } = await import('../utils/active-tracks');
    const track = { ...trackResponse(), expiresAt: null };
    await rememberActiveTrack(track, 'instance-a');
    vi.setSystemTime(new Date('2099-01-01'));
    await expect(getRememberedTrack(track.youtubeVideoId, 'instance-a')).resolves.toEqual(track);
  });

  it('does not clear or update a newer tab operation through an old claim', async () => {
    const { clearTabOperationIfMatches, getTabOperation, setTabOperation, updateTabOperationIfMatches } = await import('../utils/active-tracks');
    const first = { kind: 'generation' as const, instanceId: 'instance-a', youtubeVideoId: 'video-1', jobId: 'job-a' };
    const second = { kind: 'generation' as const, instanceId: 'instance-b', youtubeVideoId: 'video-2', jobId: 'job-b' };

    await setTabOperation(7, first);
    await setTabOperation(7, second);

    await expect(updateTabOperationIfMatches(7, first, { ...first, partialTrack: undefined })).resolves.toBe(false);
    await expect(clearTabOperationIfMatches(7, first)).resolves.toBe(false);
    await expect(getTabOperation(7)).resolves.toEqual(second);
  });
});

function trackResponse(): TrackResponse {
  return {
    trackId: 'track-1',
    jobId: 'job-1',
    youtubeVideoId: 'video-1',
    sourceLanguage: 'eng',
    targetLanguage: 'jpn',
    generatedAt: '2026-05-20T00:01:00Z',
    expiresAt: '2026-06-20T00:01:00Z',
    webVtt: 'WEBVTT',
    cues: [{
      cueId: 'cue-1',
      index: 0,
      startMs: 0,
      endMs: 1000,
      sourceText: 'hello',
      translatedText: 'こんにちは',
      tokens: [{ index: 0, text: 'hello', normalizedText: 'hello' }],
    }],
  };
}
