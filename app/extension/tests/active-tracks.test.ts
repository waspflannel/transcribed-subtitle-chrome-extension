import { beforeEach, describe, expect, it, vi } from 'vitest';

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

describe('account-scoped remembered tracks', () => {
  beforeEach(() => storageState.values.clear());

  it('does not return one account\'s track to another account', async () => {
    const { getRememberedTrack, rememberActiveTrack } = await import('../utils/active-tracks');
    const track = trackResponse();

    await rememberActiveTrack(track, 'account-a');

    await expect(getRememberedTrack(track.youtubeVideoId, 'account-a')).resolves.toEqual(track);
    await expect(getRememberedTrack(track.youtubeVideoId, 'account-b')).resolves.toBeNull();
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
