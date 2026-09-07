import { describe, expect, it, vi } from 'vitest';

import { guardLyricsCorrectionStatus } from '../utils/api-response-guards';
import { SubtitleApiError } from '../utils/api';
import type { LyricsCorrectionStatus, TrackResponse } from '../utils/contracts';
import {
  beginPanelRequest,
  canApplyPanelResponse,
  finishPanelRequest,
  panelRequestOrder,
} from '../utils/panel-request-order';
import {
  canApplyLyricsCorrection,
  lyricsCharacterCount,
  lyricsCorrectionProgress,
  lyricsCorrectionTabState,
  nextLyricsCorrectionSync,
  syncLyricsCorrectionStatus,
} from '../utils/lyrics-correction';

function status(
  attemptId: string,
  correctionStatus: LyricsCorrectionStatus['status'],
  overrides: {
    stage?: LyricsCorrectionStatus['stage'];
    track?: TrackResponse;
    errorCode?: 'lyrics_incomplete' | 'lyrics_do_not_match' | 'lyrics_correction_failed';
    message?: string;
  } = {},
): LyricsCorrectionStatus {
  const common = { attemptId, updatedAt: '2026-08-13T00:00:00Z' };

  switch (correctionStatus) {
    case 'queued':
      return { ...common, status: 'queued', stage: 'queued', ...overrides } as LyricsCorrectionStatus;
    case 'running':
      return { ...common, status: 'running', stage: 'aligning', ...overrides } as LyricsCorrectionStatus;
    case 'completed':
      return { ...common, status: 'completed', stage: 'completed', track: overrides.track, ...overrides } as LyricsCorrectionStatus;
    case 'failed':
      return { ...common, status: 'failed', stage: 'failed', errorCode: overrides.errorCode, message: overrides.message, ...overrides } as LyricsCorrectionStatus;
    case 'cancelled':
      return { ...common, status: 'cancelled', stage: 'cancelled', ...overrides } as LyricsCorrectionStatus;
  }
}

describe('lyrics correction guards', () => {
  it('maps safe correction stages to fixed progress percentages', () => {
    expect(lyricsCorrectionProgress('queued').percent).toBe(0);
    expect(lyricsCorrectionProgress('aligning').percent).toBe(15);
    expect(lyricsCorrectionProgress('rebuilding').percent).toBe(45);
    expect(lyricsCorrectionProgress('romanizing').percent).toBe(70);
    expect(lyricsCorrectionProgress('enriching').percent).toBe(85);
    expect(lyricsCorrectionProgress('finalizing').percent).toBe(95);
  });

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

describe('panel request ordering', () => {
  it('keeps a newer mutation valid when an older mutation finishes first', () => {
    let state = panelRequestOrder();
    const first = beginPanelRequest(state, 'mutation');
    state = first.state;
    const second = beginPanelRequest(state, 'mutation');
    state = second.state;

    expect(canApplyPanelResponse(state, 'mutation', first.version, first.startedDuringMutation)).toBe(false);
    expect(canApplyPanelResponse(state, 'mutation', second.version, second.startedDuringMutation)).toBe(true);
    state = finishPanelRequest(state);
    expect(state.mutationVersion).toBe(2);
  });

  it('rejects polls started before or during a mutation', () => {
    let state = panelRequestOrder();
    const before = beginPanelRequest(state, 'normal');
    const mutation = beginPanelRequest(state, 'mutation');
    state = mutation.state;
    const during = beginPanelRequest(state, 'normal');

    expect(canApplyPanelResponse(state, 'normal', before.version, before.startedDuringMutation)).toBe(false);
    state = finishPanelRequest(state);
    expect(canApplyPanelResponse(state, 'normal', during.version, during.startedDuringMutation)).toBe(false);
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

  it('requires the response request revision to equal the current revision', () => {
    const state = nextLyricsCorrectionSync(lyricsCorrectionTabState(), {
      type: 'sync-started',
      jobId: 'job-1',
      requestId: 2,
    });

    const next = nextLyricsCorrectionSync(state, {
      type: 'response',
      jobId: 'job-1',
      requestId: 3,
      status: status('attempt-future', 'completed'),
    });

    expect(next).toBe(state);
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

  it('does not apply a delayed cancellation for an older attempt', () => {
    let state = nextLyricsCorrectionSync(lyricsCorrectionTabState(), {
      type: 'submit',
      jobId: 'job-1',
      status: status('attempt-a', 'running'),
    });
    state = nextLyricsCorrectionSync(state, {
      type: 'submit',
      jobId: 'job-1',
      status: status('attempt-b', 'queued'),
    });

    const delayed = nextLyricsCorrectionSync(state, {
      type: 'cancelled',
      jobId: 'job-1',
      attemptId: 'attempt-a',
      status: status('attempt-a', 'cancelled'),
    });

    expect(delayed.status?.attemptId).toBe('attempt-b');
    expect(delayed.status?.status).toBe('queued');
  });

  it('rejects an in-flight response after the state was cleared', () => {
    let state = lyricsCorrectionTabState();
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 1 });
    const cleared = nextLyricsCorrectionSync(state, { type: 'cleared' });

    const next = nextLyricsCorrectionSync(cleared, {
      type: 'response',
      jobId: 'job-1',
      requestId: 1,
      status: status('attempt-1', 'completed'),
    });

    expect(cleared.jobId).toBeNull();
    expect(next.status).toBeNull();
    expect(next.latestRequestId).toBe(2);
  });

  it('rejects a response for a job that is no longer active', () => {
    let state = lyricsCorrectionTabState();
    state = nextLyricsCorrectionSync(state, { type: 'sync-started', jobId: 'job-1', requestId: 1 });

    const next = nextLyricsCorrectionSync(state, {
      type: 'response',
      jobId: 'job-other',
      requestId: 1,
      status: status('attempt-1', 'queued'),
    });

    expect(next.status).toBeNull();
  });
});

describe('syncLyricsCorrectionStatus', () => {
  it('makes zero status API calls and mutates no state when backend sync is disabled', async () => {
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
    expect(states.has(1)).toBe(false);
  });

  it('returns the job-scoped cached value for a non-sync call without touching the revision', async () => {
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
    expect(states.get(1)?.latestRequestId).toBe(1);
  });

  it('returns null for a non-sync call when the cached value belongs to another job', async () => {
    const states = new Map();
    states.set(1, nextLyricsCorrectionSync(lyricsCorrectionTabState(), {
      type: 'submit',
      jobId: 'job-1',
      status: status('attempt-1', 'running'),
    }));
    const fetchStatus = vi.fn(async () => status('attempt-2', 'queued'));

    const result = await syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-2',
      syncBackend: false,
      states,
      fetchStatus,
    });

    expect(result).toBeNull();
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

  it('does not resurrect an old in-flight response after the state was cleared', async () => {
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

    states.set(1, nextLyricsCorrectionSync(states.get(1) ?? lyricsCorrectionTabState(), { type: 'cleared' }));

    resolveFetch!(status('attempt-stale', 'completed'));
    const result = await pending;

    expect(result).toBeNull();
    expect(states.get(1)?.status).toBeNull();
    expect(states.get(1)?.latestRequestId).toBe(2);
  });

  it('does not recreate state when the tab map entry was deleted while a request was in flight', async () => {
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

    states.delete(1);
    resolveFetch!(status('attempt-stale', 'completed'));

    expect(await pending).toBeNull();
    expect(states.has(1)).toBe(false);
  });

  it('keeps replacement map state when an old request resolves', async () => {
    const states = new Map();
    let resolveFetch: (value: LyricsCorrectionStatus) => void;
    const fetchStatus = vi.fn(() => new Promise<LyricsCorrectionStatus>((resolve) => {
      resolveFetch = resolve;
    }));

    const pending = syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-a',
      syncBackend: true,
      states,
      fetchStatus,
    });

    const currentJobB = nextLyricsCorrectionSync(lyricsCorrectionTabState(), {
      type: 'submit',
      jobId: 'job-b',
      status: status('attempt-b', 'queued'),
    });
    states.set(1, currentJobB);
    resolveFetch!(status('attempt-a', 'completed'));

    const result = await pending;

    expect(result?.attemptId).toBe('attempt-b');
    expect(result?.status).toBe('queued');
    expect(states.get(1)).toBe(currentJobB);
  });

  it('does not apply a stale job-A 404 after job B replaces the state', async () => {
    const states = new Map();
    let rejectFetch: (reason?: unknown) => void;
    const onCurrentRequestError = vi.fn();
    const fetchStatus = vi.fn(() => new Promise<LyricsCorrectionStatus>((_resolve, reject) => {
      rejectFetch = reject;
    }));

    const pending = syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-a',
      syncBackend: true,
      states,
      fetchStatus,
      onCurrentRequestError,
    });

    const currentJobB = nextLyricsCorrectionSync(lyricsCorrectionTabState(), {
      type: 'submit',
      jobId: 'job-b',
      status: status('attempt-b', 'queued'),
    });
    states.set(1, currentJobB);
    rejectFetch!(new SubtitleApiError('not_found', 'Correction not found.', 404));

    await expect(pending).rejects.toMatchObject({ code: 'not_found', status: 404 });
    expect(onCurrentRequestError).not.toHaveBeenCalled();
    expect(states.get(1)).toBe(currentJobB);
  });

  it('notifies the caller when the current request fails', async () => {
    const states = new Map();
    let rejectFetch: (reason?: unknown) => void;
    const onCurrentRequestError = vi.fn();
    const fetchStatus = vi.fn(() => new Promise<LyricsCorrectionStatus>((_resolve, reject) => {
      rejectFetch = reject;
    }));

    const pending = syncLyricsCorrectionStatus({
      tabId: 1,
      jobId: 'job-a',
      syncBackend: true,
      states,
      fetchStatus,
      onCurrentRequestError,
    });

    const error = new SubtitleApiError('not_found', 'Correction not found.', 404);
    rejectFetch!(error);

    await expect(pending).rejects.toBe(error);
    expect(onCurrentRequestError).toHaveBeenCalledWith(error);
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

  it('rejects a cancelled response that carries an error field', () => {
    expect(() => guardLyricsCorrectionStatus(status('attempt-1', 'cancelled', {
      stage: 'cancelled',
      message: 'cancelled',
    }))).toThrow(TypeError);
  });

  it('rejects private or unknown fields on correction status responses', () => {
    expect(() => guardLyricsCorrectionStatus({ ...status('attempt-1', 'queued'), lyrics: 'private' } as never)).toThrow(TypeError);
    expect(() => guardLyricsCorrectionStatus({ ...status('attempt-1', 'cancelled'), workState: {} } as never)).toThrow(TypeError);
  });

  it('accepts the canonical queued, running, completed, failed, and cancelled shapes', () => {
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'queued')).status).toBe('queued');
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'running')).status).toBe('running');
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'completed', { track: trackResponse() })).status).toBe('completed');
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'failed', {
      errorCode: 'lyrics_correction_failed',
      message: 'failed',
    })).status).toBe('failed');
    expect(guardLyricsCorrectionStatus(status('attempt-1', 'cancelled', { stage: 'cancelled' })).status).toBe('cancelled');
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
