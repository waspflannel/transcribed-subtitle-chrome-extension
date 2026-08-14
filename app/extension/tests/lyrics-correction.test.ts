import { describe, expect, it, vi } from 'vitest';

import { guardLyricsCorrectionStatus } from '../utils/api-response-guards';
import type { LyricsCorrectionStatus, TrackResponse } from '../utils/contracts';
import {
  canApplyLyricsCorrection,
  EMPTY_LYRICS_PASTE,
  lyricsCharacterCount,
  lyricsCorrectionTabState,
  lyricsPasteForVideo,
  nextLyricsCorrectionSync,
  syncLyricsCorrectionStatus,
} from '../utils/lyrics-correction';

function status(
  attemptId: string,
  correctionStatus: LyricsCorrectionStatus['status'],
  overrides: Partial<LyricsCorrectionStatus> = {},
): LyricsCorrectionStatus {
  return { attemptId, status: correctionStatus, updatedAt: '2026-08-13T00:00:00Z', ...overrides };
}

describe('lyrics correction guards', () => {
  it('counts Unicode code points rather than UTF-16 units', () => {
    expect(lyricsCharacterCount('😀ไทย')).toBe(4);
  });

  it('disables empty, oversized, and active correction submissions', () => {
    expect(canApplyLyricsCorrection('', null)).toBe(false);
    expect(canApplyLyricsCorrection('a'.repeat(25001), null)).toBe(false);
    expect(canApplyLyricsCorrection('lyrics', { status: 'running' } as never)).toBe(false);
    expect(canApplyLyricsCorrection('lyrics', null)).toBe(true);
  });
});

describe('lyrics correction per-tab synchronization state', () => {
  it('ignores a response from an older request revision', () => {
    let state = lyricsCorrectionTabState();
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 1 });
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 2 });

    const next = nextLyricsCorrectionSync(state, {
      type: 'response',
      jobId: 'job-1',
      requestId: 1,
      status: status('attempt-old', 'queued'),
    });

    expect(next.status).toBeNull();
    expect(next.latestRequestId).toBe(2);
  });

  it('accepts the latest response even when the server attempt changed', () => {
    let state = lyricsCorrectionTabState();
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 1 });
    state = nextLyricsCorrectionSync(state, {
      type: 'response',
      jobId: 'job-1',
      requestId: 1,
      status: status('attempt-a', 'running'),
    });
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 2 });

    const next = nextLyricsCorrectionSync(state, {
      type: 'response',
      jobId: 'job-1',
      requestId: 2,
      status: status('attempt-b', 'queued'),
    });

    expect(next.status?.attemptId).toBe('attempt-b');
    expect(next.status?.status).toBe('queued');
  });

  it('lets a local submit invalidate an older in-flight status response', () => {
    let state = lyricsCorrectionTabState();
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 1 });
    state = nextLyricsCorrectionSync(state, {
      type: 'response',
      jobId: 'job-1',
      requestId: 1,
      status: status('attempt-old', 'running'),
    });

    const afterSubmit = nextLyricsCorrectionSync(state, {
      type: 'submit',
      jobId: 'job-1',
      status: status('attempt-new', 'queued'),
    });
    const afterLateResponse = nextLyricsCorrectionSync(afterSubmit, {
      type: 'response',
      jobId: 'job-1',
      requestId: 1,
      status: status('attempt-stale', 'completed'),
    });

    expect(afterSubmit.status?.attemptId).toBe('attempt-new');
    expect(afterLateResponse.status?.attemptId).toBe('attempt-new');
  });
});

describe('syncLyricsCorrectionStatus', () => {
  it('makes zero status API calls when backend sync is disabled and no correction is cached', async () => {
    const fetchStatus = vi.fn(async () => status('attempt-1', 'running'));
    const states = new Map();

    const result = await syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-1',
      syncBackend: false,
      states,
      fetchStatus,
    });

    expect(result).toBeNull();
    expect(fetchStatus).not.toHaveBeenCalled();
    expect(states.get(1)?.status).toBeNull();
    expect(states.get(1)?.latestRequestId).toBe(1);
  });

  it('returns the cached value for a non-sync call when one exists', async () => {
    const states = new Map();
    const cached = status('attempt-1', 'running');
    states.set(1, nextLyricsCorrectionSync(lyricsCorrectionTabState(), {
      type: 'submit',
      jobId: 'job-1',
      status: cached,
    }));
    const fetchStatus = vi.fn(async () => status('attempt-other', 'queued'));

    const result = await syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-1',
      syncBackend: false,
      states,
      fetchStatus,
    });

    expect(result?.attemptId).toBe('attempt-1');
    expect(fetchStatus).not.toHaveBeenCalled();
  });

  it('does not apply an in-flight response that a newer submit already invalidated', async () => {
    const states = new Map();
    let resolveFetch: (value: LyricsCorrectionStatus) => void;
    const fetchStatus = vi.fn(() => new Promise<LyricsCorrectionStatus>((resolve) => {
      resolveFetch = resolve;
    }));

    const pending = syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-1',
      syncBackend: true,
      states,
      fetchStatus,
    });

    states.set(1, nextLyricsCorrectionSync(states.get(1) ?? lyricsCorrectionTabState(), {
      type: 'submit',
      jobId: 'job-1',
      status: status('attempt-new', 'queued'),
    }));

    resolveFetch!(status('attempt-stale', 'completed'));
    const result = await pending;

    expect(result?.attemptId).toBe('attempt-new');
    expect(states.get(1)?.status?.attemptId).toBe('attempt-new');
  });
});

describe('panel-only lyrics paste memory', () => {
  it('clears the paste when the active video changes', () => {
    const buffered = lyricsPasteForVideo({ videoId: 'video-a', value: 'pasted lyrics' }, 'video-b');

    expect(buffered.value).toBe('');
    expect(buffered.videoId).toBe('video-b');
  });

  it('preserves the paste when the video is unchanged', () => {
    const buffered = lyricsPasteForVideo({ videoId: 'video-a', value: 'pasted lyrics' }, 'video-a');

    expect(buffered.value).toBe('pasted lyrics');
  });

  it('starts empty and never stores lyrics before a video exists', () => {
    expect(lyricsPasteForVideo(EMPTY_LYRICS_PASTE, null)).toBe(EMPTY_LYRICS_PASTE);
  });
});

describe('guardLyricsCorrectionStatus state exclusivity', () => {
  it('rejects a queued or running response that carries a track', () => {
    expect(() => guardLyricsCorrectionStatus(status('attempt-1', 'queued', { track: trackResponse() }))).toThrow(TypeError);
    expect(() => guardLyricsCorrectionStatus(status('attempt-1', 'running', { track: trackResponse() }))).toThrow(TypeError);
  });

  it('rejects a completed response that carries error fields', () => {
    expect(() => guardLyricsCorrectionStatus(status('attempt-1', 'completed', {
      track: trackResponse(),
      errorCode: 'lyrics_correction_failed',
    }))).toThrow(TypeError);
    expect(() => guardLyricsCorrectionStatus(status('attempt-1', 'completed', {
      track: trackResponse(),
      message: 'failed',
    }))).toThrow(TypeError);
  });

  it('rejects a failed response that carries a track', () => {
    expect(() => guardLyricsCorrectionStatus(status('attempt-1', 'failed', {
      errorCode: 'lyrics_do_not_match',
      message: 'no match',
      track: trackResponse(),
    }))).toThrow(TypeError);
  });

  it('accepts the canonical queued, completed, and failed shapes', () => {
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'queued')).status).toBe('queued');
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'completed', { track: trackResponse() })).status).toBe('completed');
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'failed', {
      errorCode: 'lyrics_correction_failed',
      message: 'failed',
    })).status).toBe('failed');
  });
});

function trackResponse(): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'auto',
    detectedSourceLanguage: 'spa',
    targetLanguage: 'fra',
    generatedAt: '2026-04-30T00:00:00Z',
    expiresAt: '2026-05-30T00:00:00Z',
    webVtt: "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nhola\n",
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 0,
        endMs: 1000,
        sourceText: 'hola',
        translatedText: 'bonjour',
        tokens: [
          {
            index: 0,
            text: 'hola',
            normalizedText: 'hola',
          },
        ],
      },
    ],
  };
}
