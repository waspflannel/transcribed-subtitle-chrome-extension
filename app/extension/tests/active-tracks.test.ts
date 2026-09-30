import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const storageState = vi.hoisted(() => ({ values: new Map<string, unknown>() }));

vi.mock('wxt/utils/storage', () => ({
  storage: {
    defineItem<T>(key: string, options: { fallback: T }) {
      return {
        async getValue(): Promise<T> {
          return storageState.values.has(key) ? storageState.values.get(key) as T : options.fallback;
        },
        async setValue(value: T): Promise<void> {
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
    vi.useFakeTimers({ now: new Date('2026-05-30T00:00:00Z') });
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
