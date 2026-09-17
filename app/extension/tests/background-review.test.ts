import { beforeEach, afterEach, assert, describe, expect, it, vi } from 'vitest';
import type { SubtitleCue, TrackResponse } from '../utils/contracts';
import { generationConfirmationContext } from '../utils/generation-confirmation';

const VIDEO_ONE = 'aBcDeFgHiJk';
const VIDEO_A = 'dQw4w9WgXcQ';
const VIDEO_B = 'M7lc1UVf-VE';

type Deferred<T> = {
  promise: Promise<T>;
  resolve: (value: T) => void;
  reject: (reason?: unknown) => void;
};

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });

  return { promise, resolve, reject };
}

const storageMock = vi.hoisted(() => {
  const values = new Map<string, unknown>();
  const readCounts = new Map<string, number>();
  const blockedReads = new Map<string, Array<{
    readNumber: number;
    promise: Promise<unknown>;
    resolve: (value: unknown) => void;
  }>>();
  const consumedBlockedReads = new Map<string, number>();

  const defineItem = vi.fn((key: string, options: { fallback: unknown }) => ({
    getValue: vi.fn(async () => {
      const readNumber = (readCounts.get(key) ?? 0) + 1;
      readCounts.set(key, readNumber);
      const pending = blockedReads.get(key)?.find((read) => read.readNumber === readNumber);
      if (pending) {
        consumedBlockedReads.set(key, (consumedBlockedReads.get(key) ?? 0) + 1);
        return pending.promise;
      }

      return values.has(key) ? values.get(key) : options.fallback;
    }),
    setValue: vi.fn(async (value: unknown) => {
      values.set(key, value);
    }),
    removeValue: vi.fn(async () => {
      values.delete(key);
    }),
  }));

  return {
    values,
    defineItem,
    blockReadAt(key: string, readNumber: number): Promise<unknown> {
      let resolve!: (value: unknown) => void;
      const promise = new Promise<unknown>((resolvePromise) => {
        resolve = resolvePromise;
      });
      const reads = blockedReads.get(key) ?? [];
      reads.push({ readNumber, promise, resolve });
      blockedReads.set(key, reads);

      return promise;
    },
    resolveReadAt(key: string, readNumber: number, value: unknown): void {
      const reads = blockedReads.get(key) ?? [];
      const index = reads.findIndex((read) => read.readNumber === readNumber);
      if (index < 0) return;
      const [read] = reads.splice(index, 1);
      assert(read, 'Blocked storage read must exist');
      read.resolve(value);
    },
    wasBlockedReadConsumed(key: string): boolean {
      return (consumedBlockedReads.get(key) ?? 0) > 0;
    },
    readCount(key: string): number {
      return readCounts.get(key) ?? 0;
    },
    reset(): void {
      values.clear();
      readCounts.clear();
      blockedReads.clear();
      consumedBlockedReads.clear();
      defineItem.mockClear();
    },
  };
});

const apiMock = vi.hoisted(() => ({
  prefetchSubtitleAudio: vi.fn(),
  enrichLearningToken: vi.fn(),
  createSubtitleJob: vi.fn(),
  getSubtitleJob: vi.fn(),
  getLyricsCorrectionStatus: vi.fn(),
  cancelSubtitleJob: vi.fn(),
  deleteSavedGeneration: vi.fn(),
  listSubtitleJobs: vi.fn(async () => ({ jobs: [] })),
  getExtensionAccount: vi.fn(),
}));

const browserMock = vi.hoisted(() => {
  const tabs = new Map<number, { id: number; windowId: number; url: string; active?: boolean }>();
  const sentTabMessages: Array<{ tabId: number; message: unknown; url?: string }> = [];
  const messageListeners: Array<(message: unknown, sender: unknown, sendResponse: (response: unknown) => void) => unknown> = [];
  const connectListeners: Array<(port: unknown) => void> = [];
  const removedListeners: Array<(tabId: number) => void> = [];
  let activeTabId = 1;

  const tabsSendMessage = vi.fn(async (tabId: number, message: unknown) => {
    sentTabMessages.push({ tabId, message, url: tabs.get(tabId)?.url });
    if ((message as { type?: string }).type === 'background.getPageSnapshot') {
      return { ok: true, videoDurationSeconds: 120 };
    }
    return { ok: true };
  });

  return {
    tabs,
    sentTabMessages,
    messageListeners,
    connectListeners,
    removedListeners,
    tabsSendMessage,
    runtimeSendMessage: vi.fn(async () => ({ ok: true })),
    reset(): void {
      tabs.clear();
      sentTabMessages.length = 0;
      messageListeners.length = 0;
      connectListeners.length = 0;
      removedListeners.length = 0;
      activeTabId = 1;
      tabsSendMessage.mockClear();
      this.runtimeSendMessage.mockClear();
    },
    setActiveTab(tabId: number): void {
      activeTabId = tabId;
      for (const tab of tabs.values()) tab.active = tab.id === tabId;
    },
    get activeTabId(): number {
      return activeTabId;
    },
  };
});

vi.mock('wxt/utils/storage', () => ({ storage: { defineItem: storageMock.defineItem } }));
vi.mock('wxt/browser', () => ({
  browser: {
    runtime: {
      onMessage: { addListener: (listener: typeof browserMock.messageListeners[number]) => browserMock.messageListeners.push(listener) },
      onConnect: { addListener: (listener: (port: unknown) => void) => browserMock.connectListeners.push(listener) },
      sendMessage: browserMock.runtimeSendMessage,
    },
    tabs: {
      get: vi.fn(async (tabId: number) => browserMock.tabs.get(tabId)),
      query: vi.fn(async () => [...browserMock.tabs.values()].filter((tab) => tab.active)),
      sendMessage: browserMock.tabsSendMessage,
      onRemoved: { addListener: (listener: (tabId: number) => void) => browserMock.removedListeners.push(listener) },
    },
  },
}));

vi.mock('../utils/api', () => ({
  SubtitleApiClient: class {
    prefetchSubtitleAudio(...args: unknown[]) { return apiMock.prefetchSubtitleAudio(...args); }
    enrichLearningToken(...args: unknown[]) { return apiMock.enrichLearningToken(...args); }
    createSubtitleJob(...args: unknown[]) { return apiMock.createSubtitleJob(...args); }
    getLyricsCorrectionStatus(...args: unknown[]) { return apiMock.getLyricsCorrectionStatus(...args); }
    getSubtitleJob(...args: unknown[]) { return apiMock.getSubtitleJob(...args); }
    cancelSubtitleJob(...args: unknown[]) { return apiMock.cancelSubtitleJob(...args); }
    deleteSavedGeneration(...args: unknown[]) { return apiMock.deleteSavedGeneration(...args); }
    listSubtitleJobs(...args: unknown[]) {
      return (apiMock.listSubtitleJobs as unknown as (...parameters: unknown[]) => unknown)(...args);
    }
    getExtensionAccount(...args: unknown[]) { return apiMock.getExtensionAccount(...args); }
  },
  SubtitleApiError: class MockSubtitleApiError extends Error {
    public override readonly name = 'SubtitleApiError';
    public constructor(
      public readonly code: string,
      message: string,
      public readonly status: number,
      public readonly details?: unknown,
    ) {
      super(message);
    }
  },
  publicSubtitleErrorMessage: vi.fn(() => 'backend error'),
  publicSubtitleJobFailureMessage: vi.fn(() => 'generation failed'),
}));

function account() {
  return {
    status: 'authenticated',
    id: 'account-1',
    email: 'learner@example.test',
    name: 'Learner',
    emailVerified: true,
    planName: 'Starter',
    tierName: 'Starter',
    tierSpeedLabel: 'Standard',
    monthlyMinuteLimit: 100,
    monthlyMinutesUsed: 0,
    monthlyMinutesPending: 0,
    monthlyMinutesRemaining: 100,
    resetAt: '2099-01-01T00:00:00.000Z',
    upgradeAvailable: false,
  };
}

function session() {
  return {
    sessionId: 'session-1',
    plainTextToken: 'token-1',
    tokenType: 'Bearer',
    expiresAt: '2099-01-01T00:00:00.000Z',
    account: account(),
  };
}

function track(videoId: string, jobId = `job-${videoId}`, trackId = `track-${videoId}`): TrackResponse & { cues: [SubtitleCue] } {
  return {
    trackId,
    jobId,
    youtubeVideoId: videoId,
    sourceLanguage: 'eng',
    targetLanguage: 'fra',
    generatedAt: '2026-09-01T00:00:00.000Z',
    expiresAt: '2099-01-01T00:00:00.000Z',
    webVtt: 'WEBVTT\n\n00:00.000 --> 00:01.000\nhello',
    cues: [{
      cueId: 'cue-1',
      index: 0,
      startMs: 0,
      endMs: 1000,
      sourceText: 'hello world',
      translatedText: 'bonjour monde',
      tokens: [
        { index: 0, text: 'hello', normalizedText: 'hello' },
        { index: 1, text: 'world', normalizedText: 'world' },
      ],
    }],
  };
}

function job(videoId: string, jobId: string, status: 'queued' | 'running' | 'cancelled' = 'running') {
  return {
    jobId,
    youtubeVideoId: videoId,
    sourceLanguage: 'eng',
    targetLanguage: 'fra',
    status,
    stage: 'transcribing',
    progressPercent: 30,
    createdAt: '2026-09-08T00:00:00.000Z',
    updatedAt: '2026-09-08T00:00:01.000Z',
    startedAt: '2026-09-08T00:00:00.000Z',
    lastUpdatedAt: '2026-09-08T00:00:01.000Z',
    youtubeUrl: `https://www.youtube.com/watch?v=${videoId}`,
    ...(status === 'cancelled' ? { errorCode: 'generation_cancelled' } : {}),
  };
}

function sender(tabId: number) {
  const tab = browserMock.tabs.get(tabId);
  return { tab };
}

function generationRequest() {
  const tabId = browserMock.activeTabId;
  const tab = browserMock.tabs.get(tabId);
  assert(tab);
  const accountId = (storageMock.values.get('local:extensionSession') as ReturnType<typeof session>).account.id;
  return { type: 'panel.generateSubtitles', windowId: 1, confirmationContext:
    generationConfirmationContext(tabId, new URL(tab.url).searchParams.get('v')!, accountId) };
}

async function loadBackground(): Promise<
  (message: unknown, sender: unknown, sendResponse: (response: unknown) => void) => unknown
> {
  vi.stubGlobal('defineBackground', (callback: () => void) => callback());
  await import('../entrypoints/background');
  const listener = browserMock.messageListeners.at(-1);
  if (!listener) throw new Error('background message listener was not registered');
  return listener;
}

function dispatch(
  listener: (message: unknown, sender: unknown, sendResponse: (response: unknown) => void) => unknown,
  message: unknown,
  messageSender: unknown,
): Promise<any> {
  return new Promise((resolve) => {
    const result = listener(message, messageSender, resolve);
    if (result === false) resolve(undefined);
  });
}

async function flushMicrotasks(): Promise<void> {
  for (let index = 0; index < 10; index += 1) await Promise.resolve();
}

async function waitFor(condition: () => boolean): Promise<void> {
  for (let index = 0; index < 100; index += 1) {
    if (condition()) return;
    await flushMicrotasks();
  }
  throw new Error('Timed out waiting for background work');
}

function seedBaseState(): void {
  storageMock.values.set('local:installId', 'install_0123456789abcdef0123456789abcdef');
  storageMock.values.set('local:extensionSession', session());
  storageMock.values.set('local:extensionSettings', {
    sourceLanguage: 'eng',
    targetLanguage: 'fra',
    overlayVisible: true,
    overlayPosition: 'bottom',
    captionFontSize: 'medium',
    captionDensity: 'comfortable',
    captionContrastTheme: 'default',
    keyboardShortcutsEnabled: true,
    showRomanization: true,
    showTranslation: false,
    showGloss: true,
    blurSourceWords: false,
    blurRomanization: false,
    blurTranslation: false,
    pauseOnWordHover: true,
    subtitleTimingOffsetSeconds: 0,
  });
}

beforeEach(() => {
  vi.stubEnv('WXT_AUDIO_METADATA_PREFETCH', 'false');
  apiMock.prefetchSubtitleAudio.mockReset().mockResolvedValue({ ok: true });
  vi.resetModules();
  storageMock.reset();
  browserMock.reset();
  apiMock.enrichLearningToken.mockReset();
  apiMock.createSubtitleJob.mockReset();
  apiMock.getSubtitleJob.mockReset();
  apiMock.getLyricsCorrectionStatus.mockReset().mockResolvedValue({ status: 'completed' });
  apiMock.cancelSubtitleJob.mockReset();
  apiMock.deleteSavedGeneration.mockReset().mockResolvedValue({ ok: true });
  apiMock.listSubtitleJobs.mockReset().mockResolvedValue({ jobs: [] });
  apiMock.getExtensionAccount.mockReset().mockResolvedValue({ account: account() });
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('background entrypoint review regressions', () => {
  it.each([false, true])('requests fresh analysis when generating from a saved track (saved: %s)', async (saved) => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const original = track(VIDEO_A);
    if (saved) storageMock.values.set('local:activeTracksByVideoId', { [VIDEO_A]: { accountId: 'account-1', track: original } });
    const fresh = track(VIDEO_A, original.jobId, 'fresh-track');
    const completed = { ...job(VIDEO_A, original.jobId), status: 'completed', track: fresh };
    apiMock.createSubtitleJob.mockResolvedValue(completed);
    apiMock.getSubtitleJob.mockResolvedValue(completed);
    const listener = await loadBackground();
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1);
    expect(apiMock.createSubtitleJob.mock.calls[0]?.[2].forceRegenerate).toBe(saved ? true : undefined);
    await waitFor(() => (storageMock.values.get('local:activeTracksByVideoId') as any)?.[VIDEO_A]?.track.trackId === 'fresh-track');
    const result = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(result.subtitleState.track.trackId).toBe('fresh-track');
  });

  it('prefetches only for an open panel, without waiting, and deduplicates per video', async () => {
    vi.stubEnv('WXT_AUDIO_METADATA_PREFETCH', 'true');
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const request = { type: 'panel.getState', syncBackend: false, windowId: 1 };
    await dispatch(listener, request, {});
    expect(apiMock.prefetchSubtitleAudio).not.toHaveBeenCalled();
    let disconnect = () => {};
    const connect = browserMock.connectListeners[0];
    assert(connect, 'Background must register a connect listener');
    connect({ name: 'panel', onDisconnect: { addListener: (fn: () => void) => { disconnect = fn; } } });
    apiMock.prefetchSubtitleAudio.mockReturnValue(new Promise(() => {}));
    await dispatch(listener, request, {});
    await dispatch(listener, request, {});
    expect(apiMock.prefetchSubtitleAudio).toHaveBeenCalledTimes(1);
    expect(apiMock.prefetchSubtitleAudio.mock.calls[0]?.[2]).toBe(VIDEO_A);
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    await dispatch(listener, request, {});
    expect(apiMock.prefetchSubtitleAudio).toHaveBeenCalledTimes(2);
    disconnect();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_ONE}` });
    await dispatch(listener, request, {});
    expect(apiMock.prefetchSubtitleAudio).toHaveBeenCalledTimes(2);
  });
  it('refreshes and publishes a correction without fetching account or history', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const original = track(VIDEO_A);
    storageMock.values.set('local:activeTracksByVideoId', { [VIDEO_A]: { accountId: 'account-1', track: original } });
    const listener = await loadBackground();
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    const replacement = { ...original, trackId: 'replacement-track' };
    apiMock.getLyricsCorrectionStatus.mockResolvedValue({ attemptId: 'attempt-1', status: 'completed', track: replacement });
    apiMock.getExtensionAccount.mockClear();
    apiMock.listSubtitleJobs.mockClear();
    const result = await dispatch(listener, { type: 'panel.getState', syncBackend: false, syncLyricsCorrection: true, windowId: 1 }, {});
    expect(apiMock.getLyricsCorrectionStatus).toHaveBeenCalledTimes(1);
    expect(apiMock.getExtensionAccount).not.toHaveBeenCalled();
    expect(apiMock.listSubtitleJobs).not.toHaveBeenCalled();
    expect(result.subtitleState.track.trackId).toBe('replacement-track');
  });

  it('does not replay a completed correction after a delayed word-card response or local refresh', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const original = track(VIDEO_A);
    storageMock.values.set('local:activeTracksByVideoId', { [VIDEO_A]: { accountId: 'account-1', track: original } });
    apiMock.getLyricsCorrectionStatus.mockResolvedValue({ attemptId: 'attempt-1', status: 'completed', track: original });
    const listener = await loadBackground();
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    await dispatch(listener, { type: 'panel.getState', syncBackend: true, windowId: 1 }, {});
    const card = deferred<any>();
    apiMock.enrichLearningToken.mockReturnValue(card.promise);
    const request = dispatch(listener, { type: 'content.enrichLearningToken', youtubeVideoId: VIDEO_A,
      trackId: original.trackId, cueId: 'cue-1', tokenIndex: 0 }, sender(1));
    await waitFor(() => apiMock.enrichLearningToken.mock.calls.length === 1);
    await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    card.resolve({ trackId: original.trackId, cueId: 'cue-1',
      token: { ...original.cues[0].tokens[0], translation: 'bonjour' } });
    await request;
    browserMock.sentTabMessages.length = 0;
    const local = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    const synced = await dispatch(listener, { type: 'panel.getState', syncBackend: true, windowId: 1 }, {});
    expect(local.subtitleState.track.cues[0].tokens[0].translation).toBe('bonjour');
    expect(synced.subtitleState.track.cues[0].tokens[0].translation).toBe('bonjour');
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged')).toHaveLength(0);
  });

  it.each(['content', 'panel'])('recovers an old saved video outside global history and the local cache through %s', async (surface) => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const oldTrack = track(VIDEO_A, 'old-job');
    const savedJob = { ...job(VIDEO_A, 'old-job'), status: 'completed', track: oldTrack };
    const recentJobs = Array.from({ length: 25 }, (_, index) => ({ ...job(VIDEO_B, `recent-${index}`), status: 'completed' }));
    storageMock.values.set('local:activeTracksByVideoId', Object.fromEntries(Array.from({ length: 5 }, (_, index) =>
      [`cached-${index}`, { accountId: 'account-1', track: track(`cached-${index}`) }])));
    apiMock.listSubtitleJobs.mockImplementation(async (...args: unknown[]) => ({ jobs: args[2] === VIDEO_A ? [savedJob] : recentJobs }) as any);
    apiMock.getSubtitleJob.mockResolvedValue(savedJob);
    const listener = await loadBackground();
    const result = await dispatch(listener, surface === 'content' ? { type: 'content.getState', revalidateSavedGeneration: false }
      : { type: 'panel.getState', syncBackend: true, windowId: 1 }, surface === 'content' ? sender(1) : {});
    expect(result.subtitleState.track.jobId).toBe('old-job');
    expect(apiMock.listSubtitleJobs).toHaveBeenCalledWith(expect.any(String), expect.any(String), VIDEO_A);
    if (surface === 'panel') expect(result.jobHistory).toHaveLength(25);
  });

  it('lets the active monitor own one minute of polling despite panel refreshes', async () => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const running = job(VIDEO_A, 'monitor-job');
    apiMock.createSubtitleJob.mockResolvedValue(running);
    apiMock.getSubtitleJob.mockResolvedValue(running);
    const listener = await loadBackground();
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() > 0);
    apiMock.getSubtitleJob.mockClear();
    for (let index = 0; index < 6; index += 1) {
      await vi.advanceTimersByTimeAsync(10000);
      await dispatch(listener, { type: 'panel.getState', syncBackend: true, windowId: 1 }, {});
      await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    }
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(60);
  });

  it.each([false, true])('keeps completion publication attempt-scoped when another status is accepted (superseded: %s)', async (superseded) => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const original = track(VIDEO_A);
    const corrected = track(VIDEO_A, original.jobId, 'corrected-track');
    storageMock.values.set('local:activeTracksByVideoId', { [VIDEO_A]: { accountId: 'account-1', track: original } });
    const completion = { attemptId: 'attempt-1', status: 'completed', track: corrected };
    apiMock.getLyricsCorrectionStatus.mockImplementation(async () => structuredClone(completion));
    const lyrics = await import('../utils/lyrics-correction');
    const sync = lyrics.syncLyricsCorrectionStatus;
    const syncSpy = vi.spyOn(lyrics, 'syncLyricsCorrectionStatus').mockImplementationOnce(async (options) => {
      const result = await sync(options);
      if (superseded) apiMock.getLyricsCorrectionStatus.mockResolvedValue({ attemptId: 'attempt-2', status: 'queued' });
      await sync(options);
      return result;
    });
    const listener = await loadBackground();
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    const result = await dispatch(listener, { type: 'panel.getState', syncBackend: true, windowId: 1 }, {});
    syncSpy.mockRestore();
    expect(result.subtitleState.track.trackId).toBe(superseded ? original.trackId : 'corrected-track');
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged')).toHaveLength(superseded ? 0 : 1);
  });

  it.each(['content', 'panel'])('ignores an unreadable completed history entry when recovering through %s', async (surface) => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const savedJob = { ...job(VIDEO_A, 'readable-job'), status: 'completed', track: track(VIDEO_A, 'readable-job') };
    const obsoleteJob = { ...job(VIDEO_A, 'obsolete-job'), status: 'completed' };
    apiMock.listSubtitleJobs.mockImplementation(async (...args: unknown[]) => ({ jobs: args[2] === VIDEO_A ? [savedJob] : [obsoleteJob] }) as any);
    apiMock.getSubtitleJob.mockImplementation(async (_installId, _token, jobId) => {
      if (jobId === 'readable-job') return savedJob;
      throw new Error('Obsolete processing version is not readable');
    });
    const listener = await loadBackground();
    const result = await dispatch(listener, surface === 'content' ? { type: 'content.getState', revalidateSavedGeneration: false }
      : { type: 'panel.getState', syncBackend: true, windowId: 1 }, surface === 'content' ? sender(1) : {});
    expect(result.subtitleState).toMatchObject({ type: 'ready', track: { jobId: 'readable-job' } });
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    if (surface === 'panel') expect(result.jobHistory).toEqual([obsoleteJob]);
  });

  it('does not retry completed-track requests during a local-only panel refresh', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: [{ ...job(VIDEO_A, 'saved-job'), status: 'completed' }] } as any);
    apiMock.getSubtitleJob.mockRejectedValue(new Error('offline'));
    const listener = await loadBackground();
    await dispatch(listener, { type: 'panel.getState', syncBackend: true, windowId: 1 }, {});
    apiMock.getSubtitleJob.mockClear();
    apiMock.listSubtitleJobs.mockClear();
    const result = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(result.subtitleState.type).toBe('no-track');
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    expect(apiMock.listSubtitleJobs).not.toHaveBeenCalled();
  });

  it('recovers a persisted operation when the worker has no monitor', async () => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    storageMock.values.set('local:tabSubtitleOperations', { '1': { kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A, jobId: 'restart-job' } });
    apiMock.getSubtitleJob.mockResolvedValue(job(VIDEO_A, 'restart-job'));
    const listener = await loadBackground();
    const state = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(state.subtitleState).toMatchObject({ type: 'loading', jobId: 'restart-job' });
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(2);
  });

  it.each(['transcribing', 'tokenizing'])('delivers %s previews with status, preserves unchanged revisions, and stops on completion', async (stage) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const partialTrack = { jobId: 'job-poll', youtubeVideoId: VIDEO_A, revision: 1, cues: track(VIDEO_A).cues };
    const runningJob = { ...job(VIDEO_A, 'job-poll'), stage, partialTrack };
    apiMock.createSubtitleJob.mockResolvedValue(runningJob);
    apiMock.getSubtitleJob.mockResolvedValue(runningJob);
    const listener = await loadBackground();

    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() > 0);
    const operationBeforeUnchangedPoll = storageMock.values.get('local:tabSubtitleOperations');
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(999);
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    expect(storageMock.values.get('local:tabSubtitleOperations')).toBe(operationBeforeUnchangedPoll);

    apiMock.getSubtitleJob.mockResolvedValue({ ...runningJob, partialTrack: { ...partialTrack, revision: 2, readyThroughMs: 10000 } });
    await vi.advanceTimersByTimeAsync(1000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(2);
    expect((storageMock.values.get('local:tabSubtitleOperations') as Record<string, any>)['1'].partialTrack.readyThroughMs).toBe(10000);

    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, 'job-poll'), status: 'completed', stage: 'finalizing', track: track(VIDEO_A, 'job-poll') });
    await vi.advanceTimersByTimeAsync(21000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(3);
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(1);
  });

  it('backs off failed status requests, retains the preview, and stops on cancellation', async () => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const runningJob = { ...job(VIDEO_A, 'job-poll'), stage: 'tokenizing', partialTrack: {
      jobId: 'job-poll', youtubeVideoId: VIDEO_A, revision: 1, cues: track(VIDEO_A).cues,
    } };
    apiMock.createSubtitleJob.mockResolvedValue(runningJob);
    apiMock.getSubtitleJob.mockResolvedValue(runningJob);
    apiMock.cancelSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-poll', 'cancelled'));
    const listener = await loadBackground();

    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() > 0);
    apiMock.getSubtitleJob.mockClear().mockRejectedValue(new Error('temporarily unavailable'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(4999);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(2);
    expect((storageMock.values.get('local:tabSubtitleOperations') as Record<string, any>)['1'].partialTrack.revision).toBe(1);

    apiMock.getSubtitleJob.mockResolvedValue(runningJob);
    await dispatch(listener, {
      type: 'panel.cancelSubtitleJob', tabId: 1, youtubeVideoId: VIDEO_A, jobId: 'job-poll', windowId: 1,
    }, {});
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(30000);
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(1);
  });

  it('switches providers between active jobs while sharing the polling budget', async () => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    browserMock.tabs.set(2, { id: 2, windowId: 1, active: false, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    const jobs = [job(VIDEO_A, 'job-a'), job(VIDEO_B, 'job-b')];
    apiMock.createSubtitleJob.mockResolvedValueOnce(jobs[0]).mockResolvedValueOnce(jobs[1]);
    apiMock.getSubtitleJob.mockImplementation(async (_installId, _token, jobId) => jobs.find((item) => item.jobId === jobId));
    const listener = await loadBackground();
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    await dispatch(listener, { type: 'panel.updateSettings', patch: { aiProvider: 'cerebras' }, windowId: 1 }, {});
    browserMock.setActiveTab(2);
    await dispatch(listener, generationRequest(), {});
    expect(apiMock.createSubtitleJob.mock.calls.map((call) => call[2].aiProvider)).toEqual(['openai', 'cerebras']);
    await waitFor(() => vi.getTimerCount() === 2);
    apiMock.getSubtitleJob.mockClear();

    await vi.advanceTimersByTimeAsync(60000);
    expect(apiMock.getSubtitleJob.mock.calls.length).toBeGreaterThan(0);
    expect(apiMock.getSubtitleJob.mock.calls.length).toBeLessThanOrEqual(60);
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(2);
  });

  it.each(['completed', 'failed', 'submission-error'])('does not count an open tab after its generation is %s in the polling budget', async (outcome) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    browserMock.tabs.set(2, { id: 2, windowId: 1, active: false, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    if (outcome === 'submission-error') {
      apiMock.createSubtitleJob.mockRejectedValueOnce(new Error('offline'));
    } else {
      apiMock.createSubtitleJob.mockResolvedValueOnce({ ...job(VIDEO_A, 'job-a'), status: outcome,
        ...(outcome === 'completed' ? { track: track(VIDEO_A, 'job-a') } : { errorCode: 'internal_error', message: 'Failed' }) });
    }
    const listener = await loadBackground();
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1
      && Object.keys((storageMock.values.get('local:tabSubtitleOperations') as object | undefined) ?? {}).length === 0);
    await vi.advanceTimersByTimeAsync(0);

    browserMock.setActiveTab(2);
    apiMock.createSubtitleJob.mockResolvedValue(job(VIDEO_B, 'job-b'));
    apiMock.getSubtitleJob.mockResolvedValue(job(VIDEO_B, 'job-b'));
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(999);
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledExactlyOnceWith(expect.any(String), 'token-1', 'job-b');
  });

  it.each([false, true])('retains interrupted work for recovery without slowing another tab (recovered: %s)', async (recovered) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    browserMock.tabs.set(2, { id: 2, windowId: 1, active: false, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    const first = job(VIDEO_A, 'job-a');
    const second = job(VIDEO_B, 'job-b');
    apiMock.createSubtitleJob.mockResolvedValue(first);
    apiMock.getSubtitleJob.mockImplementation(async (_installId, _token, jobId) => jobId === 'job-a' ? first : second);
    const listener = await loadBackground();
    if (recovered) storageMock.values.set('local:tabSubtitleOperations', {
      '1': { kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A, jobId: 'job-a' },
    });
    await dispatch(listener, recovered ? { type: 'panel.getState', syncBackend: false, windowId: 1 } : generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    storageMock.values.set('local:extensionSession', { ...session(), sessionId: 'session-2', plainTextToken: 'token-2' });
    await vi.advanceTimersByTimeAsync(1000);
    expect(vi.getTimerCount()).toBe(0);
    expect((storageMock.values.get('local:tabSubtitleOperations') as Record<string, any>)['1']).toMatchObject({ jobId: 'job-a' });

    browserMock.setActiveTab(2);
    apiMock.createSubtitleJob.mockResolvedValue(second);
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(1000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledExactlyOnceWith(expect.any(String), 'token-2', 'job-b');

    browserMock.setActiveTab(1);
    const resumed = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(resumed.subtitleState).toMatchObject({ type: 'loading', jobId: 'job-a' });
    await waitFor(() => vi.getTimerCount() === 2);
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(2000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledWith(expect.any(String), 'token-2', 'job-a');
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(recovered ? 1 : 2);
  });

  it.each([false, true])('keeps cancellation cleanup ownership after its monitor stops (recovered: %s)', async (recovered) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    apiMock.createSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-a'));
    apiMock.getSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-a'));
    const cancelled = deferred<any>();
    apiMock.cancelSubtitleJob.mockReturnValue(cancelled.promise);
    const listener = await loadBackground();
    if (recovered) storageMock.values.set('local:tabSubtitleOperations', {
      '1': { kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A, jobId: 'job-a' },
    });
    await dispatch(listener, recovered ? { type: 'panel.getState', syncBackend: false, windowId: 1 } : generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    const cancellation = dispatch(listener, { type: 'panel.cancelSubtitleJob', tabId: 1,
      youtubeVideoId: VIDEO_A, jobId: 'job-a', windowId: 1 }, {});
    await waitFor(() => apiMock.cancelSubtitleJob.mock.calls.length === 1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(vi.getTimerCount()).toBe(0);
    cancelled.resolve(job(VIDEO_A, 'job-a', 'cancelled'));
    expect(await cancellation).toMatchObject({ subtitleState: { type: 'no-track' } });
    expect(storageMock.values.get('local:tabSubtitleOperations')).toEqual({});
  });

  it.each([false, true])('releases a stopped monitor after cancellation fails and preserves recovery (recovered: %s)', async (recovered) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    browserMock.tabs.set(2, { id: 2, windowId: 1, active: false, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    const first = job(VIDEO_A, 'job-a');
    const second = job(VIDEO_B, 'job-b');
    apiMock.createSubtitleJob.mockResolvedValue(first);
    apiMock.getSubtitleJob.mockImplementation(async (_installId, _token, jobId) => jobId === 'job-a' ? first : second);
    const cancelled = deferred<any>();
    apiMock.cancelSubtitleJob.mockReturnValue(cancelled.promise);
    const listener = await loadBackground();
    if (recovered) storageMock.values.set('local:tabSubtitleOperations', {
      '1': { kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A, jobId: 'job-a' },
    });
    await dispatch(listener, recovered ? { type: 'panel.getState', syncBackend: false, windowId: 1 } : generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    const cancellation = dispatch(listener, { type: 'panel.cancelSubtitleJob', tabId: 1,
      youtubeVideoId: VIDEO_A, jobId: 'job-a', windowId: 1 }, {});
    await waitFor(() => apiMock.cancelSubtitleJob.mock.calls.length === 1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(vi.getTimerCount()).toBe(0);
    cancelled.reject(new Error('offline'));
    expect(await cancellation).toMatchObject({ ok: false, error: 'offline' });
    expect((storageMock.values.get('local:tabSubtitleOperations') as Record<string, any>)['1']).toMatchObject({ jobId: 'job-a' });

    browserMock.setActiveTab(2);
    apiMock.createSubtitleJob.mockResolvedValue(second);
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(1000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledExactlyOnceWith(expect.any(String), 'token-1', 'job-b');

    browserMock.setActiveTab(1);
    const resumed = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(resumed.subtitleState).toMatchObject({ type: 'loading', jobId: 'job-a' });
    await waitFor(() => vi.getTimerCount() === 2);
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(recovered ? 1 : 2);
  });

  it.each([false, true])('does not release a newer monitor when a cancelled poll returns late (recovered: %s)', async (recovered) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    apiMock.createSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-a'));
    apiMock.getSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-a'));
    apiMock.cancelSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-a', 'cancelled'));
    const listener = await loadBackground();
    if (recovered) storageMock.values.set('local:tabSubtitleOperations', {
      '1': { kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A, jobId: 'job-a' },
    });
    await dispatch(listener, recovered ? { type: 'panel.getState', syncBackend: false, windowId: 1 } : generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    const oldPoll = deferred<any>();
    apiMock.getSubtitleJob.mockReturnValueOnce(oldPoll.promise);
    await vi.advanceTimersByTimeAsync(1000);
    await dispatch(listener, { type: 'panel.cancelSubtitleJob', tabId: 1,
      youtubeVideoId: VIDEO_A, jobId: 'job-a', windowId: 1 }, {});

    apiMock.createSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-new'));
    apiMock.getSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-new'));
    await dispatch(listener, generationRequest(), {});
    await waitFor(() => vi.getTimerCount() === 1);
    oldPoll.resolve({ ...job(VIDEO_A, 'job-a'), status: 'completed', track: track(VIDEO_A, 'job-a') });
    await vi.advanceTimersByTimeAsync(0);
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(1000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledExactlyOnceWith(expect.any(String), 'token-1', 'job-new');
    expect((storageMock.values.get('local:tabSubtitleOperations') as Record<string, any>)['1']).toMatchObject({ jobId: 'job-new' });
    const current = await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(current.subtitleState).toMatchObject({ type: 'loading', jobId: 'job-new' });
  });

  it('keeps concurrent content.enrichLearningToken metadata updates in the remembered and current track', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_ONE}` });
    const originalTrack = track(VIDEO_ONE);
    storageMock.values.set('local:activeTracksByVideoId', {
      [VIDEO_ONE]: { accountId: 'account-1', track: originalTrack },
    });

    const listener = await loadBackground();
    const tokenResponses = new Map<number, Deferred<any>>([
      [0, deferred()],
      [1, deferred()],
    ]);
    apiMock.enrichLearningToken.mockImplementation((_installId: string, _authToken: string, payload: { tokenIndex: number }) =>
      tokenResponses.get(payload.tokenIndex)!.promise);

    const first = dispatch(listener, {
      type: 'content.enrichLearningToken',
      youtubeVideoId: VIDEO_ONE,
      trackId: `track-${VIDEO_ONE}`,
      cueId: 'cue-1',
      tokenIndex: 0,
    }, sender(1));
    const second = dispatch(listener, {
      type: 'content.enrichLearningToken',
      youtubeVideoId: VIDEO_ONE,
      trackId: `track-${VIDEO_ONE}`,
      cueId: 'cue-1',
      tokenIndex: 1,
    }, sender(1));
    await waitFor(() => apiMock.enrichLearningToken.mock.calls.length === 2);

    tokenResponses.get(1)!.resolve({
      trackId: `track-${VIDEO_ONE}`,
      cueId: 'cue-1',
      token: { index: 1, text: 'world', normalizedText: 'world', translation: 'monde' },
    });
    tokenResponses.get(0)!.resolve({
      trackId: `track-${VIDEO_ONE}`,
      cueId: 'cue-1',
      token: { index: 0, text: 'hello', normalizedText: 'hello', translation: 'bonjour' },
    });
    await Promise.all([first, second]);

    const remembered = (storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_ONE].track;
    expect(remembered.cues[0].tokens).toEqual([
      expect.objectContaining({ index: 0, translation: 'bonjour' }),
      expect.objectContaining({ index: 1, translation: 'monde' }),
    ]);
    const current = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(current.subtitleState.track.cues[0].tokens).toEqual(remembered.cues[0].tokens);
  });

  it.each(['account', 'video', 'tab'])('rejects stale confirmed %s details before creating a job', async (change) => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const request = generationRequest();
    if (change === 'account') storageMock.values.set('local:extensionSession', { ...session(), sessionId: 'session-2', account: { ...account(), id: 'account-2' } });
    if (change === 'video') browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    if (change === 'tab') {
      browserMock.tabs.set(2, { id: 2, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
      browserMock.setActiveTab(2);
    }
    const listener = await loadBackground();
    const response = await dispatch(listener, request, {});
    expect(response).toMatchObject({ ok: false, error: 'The selected video or account changed. Open generation for the current video.' });
    expect(apiMock.createSubtitleJob).not.toHaveBeenCalled();
  });

  it.each(['video', 'account', 'settings', 'duration'])('handles %s changes after asynchronous preparation', async (change) => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const snapshot = deferred<{ ok: true; videoDurationSeconds: number }>();
    browserMock.tabsSendMessage.mockImplementationOnce(() => snapshot.promise);
    const request = dispatch(listener, generationRequest(), {});
    await waitFor(() => browserMock.tabsSendMessage.mock.calls.some(([, message]) => (message as { type?: string }).type === 'background.getPageSnapshot'));
    if (change === 'video') browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    if (change === 'account') storageMock.values.set('local:extensionSession', { ...session(), sessionId: 'session-2', account: { ...account(), id: 'account-2' } });
    if (change === 'settings') storageMock.values.set('local:extensionSettings', { ...storageMock.values.get('local:extensionSettings') as object, aiProvider: 'cerebras' });
    snapshot.resolve({ ok: true, videoDurationSeconds: change === 'duration' ? 240 : 120 });
    await request;
    if (change === 'settings' || change === 'duration') {
      await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1);
      expect(apiMock.createSubtitleJob.mock.calls[0]?.[2]).toMatchObject({
        aiProvider: 'openai', videoDurationSeconds: change === 'duration' ? 240 : 120,
      });
      return;
    }
    if (change !== 'account') await waitFor(() => browserMock.sentTabMessages.some(({ message }) =>
      (message as { subtitleState?: { type?: string } }).subtitleState?.type === 'error'));
    expect(apiMock.createSubtitleJob).not.toHaveBeenCalled();
  });

  it('keeps a live generation loading while its create request is pending', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const createRequest = deferred<any>();
    apiMock.createSubtitleJob.mockReturnValue(createRequest.promise);

    const firstGeneration = dispatch(listener, generationRequest(), {});
    await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1);
    const firstState = await firstGeneration;
    expect(firstState.subtitleState).toMatchObject({
      type: 'loading',
      status: 'running',
      youtubeVideoId: VIDEO_A,
      message: 'Preparing request...',
    });

    await dispatch(listener, generationRequest(), {});
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(1);

    createRequest.resolve({
      ...job(VIDEO_A, 'job-live'),
      status: 'completed' as const,
      track: track(VIDEO_A, 'job-live', 'track-live'),
    });
    await waitFor(() => (storageMock.values.get('local:activeTracksByVideoId') as Record<string, unknown> | undefined)?.[VIDEO_A] !== undefined);

    const finalState = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(finalState.subtitleState).toMatchObject({
      type: 'ready',
      track: { youtubeVideoId: VIDEO_A, jobId: 'job-live', trackId: 'track-live' },
    });
  });

  it('does not let delayed content recovery overwrite a newer generation', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const historyRequest = deferred<any>();
    const createRequest = deferred<any>();
    const oldTrack = track(VIDEO_A, 'job-old', 'track-old');
    const newTrack = track(VIDEO_A, 'job-new', 'track-new');

    apiMock.listSubtitleJobs.mockReturnValue(historyRequest.promise);
    apiMock.getSubtitleJob.mockImplementation(async (_installId: string, _authToken: string, jobId: string) => ({
      ...job(VIDEO_A, jobId, 'running'),
      status: 'completed' as const,
      track: oldTrack,
    }));
    apiMock.createSubtitleJob.mockReturnValue(createRequest.promise);

    const initialRecovery = dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    await waitFor(() => apiMock.listSubtitleJobs.mock.calls.length === 1);

    const generation = dispatch(listener, generationRequest(), {});
    await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1);
    const loadingState = await generation;
    expect(loadingState.subtitleState).toMatchObject({
      type: 'loading',
      youtubeVideoId: VIDEO_A,
      message: 'Preparing request...',
    });

    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    historyRequest.resolve({ jobs: [{ ...job(VIDEO_A, 'job-old'), status: 'completed' as const }] });
    await initialRecovery;
    const stateAfterNavigation = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(stateAfterNavigation.subtitleState).toEqual({ type: 'no-track' });

    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const stateWhileCreateWaits = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(stateWhileCreateWaits.subtitleState).toMatchObject({
      type: 'loading',
      youtubeVideoId: VIDEO_A,
      message: 'Preparing request...',
    });
    expect(storageMock.values.get('local:tabSubtitleOperations')).toEqual({
      '1': { kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A },
    });
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(1);

    createRequest.resolve({
      ...job(VIDEO_A, 'job-new'),
      status: 'completed' as const,
      track: newTrack,
    });
    await waitFor(() => (storageMock.values.get('local:activeTracksByVideoId') as Record<string, any> | undefined)?.[VIDEO_A]?.track.trackId === 'track-new');

    const finalState = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(finalState.subtitleState).toMatchObject({
      type: 'ready',
      track: { youtubeVideoId: VIDEO_A, jobId: 'job-new', trackId: 'track-new' },
    });
  });

  it('does not let cancellation cleanup delete a newer same-tab persisted operation', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const oldTrack = track(VIDEO_A, 'job-a', 'track-a');
    storageMock.values.set('local:activeTracksByVideoId', {
      [VIDEO_A]: { accountId: 'account-1', track: oldTrack },
    });
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));

    const oldOperation = {
      kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_A, jobId: 'job-a',
    };
    storageMock.values.set('local:tabSubtitleOperations', { '1': oldOperation });
    const cancelRequest = deferred<any>();
    apiMock.cancelSubtitleJob.mockReturnValue(cancelRequest.promise);
    const cancellation = dispatch(listener, {
      type: 'panel.cancelSubtitleJob',
      tabId: 1,
      youtubeVideoId: VIDEO_A,
      jobId: 'job-a',
      windowId: 1,
    }, {});
    await waitFor(() => apiMock.cancelSubtitleJob.mock.calls.length === 1);

    const newerOperation = {
      kind: 'generation', accountId: 'account-1', youtubeVideoId: VIDEO_B,
    };
    storageMock.blockReadAt('local:tabSubtitleOperations', 3);
    cancelRequest.resolve(job(VIDEO_A, 'job-a', 'cancelled'));
    await waitFor(() => storageMock.wasBlockedReadConsumed('local:tabSubtitleOperations'));
    expect(storageMock.readCount('local:tabSubtitleOperations')).toBe(3);

    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    storageMock.values.set('local:activeTracksByVideoId', {
      [VIDEO_B]: { accountId: 'account-1', track: track(VIDEO_B, 'job-b', 'track-b') },
    });
    storageMock.values.set('local:tabSubtitleOperations', { '1': newerOperation });
    const stateWhileCleanupWaits = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(stateWhileCleanupWaits.subtitleState).toMatchObject({
      type: 'ready',
      track: { youtubeVideoId: VIDEO_B, jobId: 'job-b', trackId: 'track-b' },
    });

    storageMock.resolveReadAt('local:tabSubtitleOperations', 3, { '1': newerOperation });
    await cancellation;

    expect(storageMock.values.get('local:tabSubtitleOperations')).toEqual({ '1': newerOperation });
    const finalState = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    expect(finalState.subtitleState).toMatchObject({
      type: 'ready',
      track: { youtubeVideoId: VIDEO_B, jobId: 'job-b', trackId: 'track-b' },
    });
    expect(browserMock.sentTabMessages.filter(({ message, url }) =>
      url?.includes(`v=${VIDEO_B}`)
      && (message as { type?: string }).type === 'background.subtitleStateChanged'
      && (message as { subtitleState?: { type?: string } }).subtitleState?.type === 'no-track',
    )).toHaveLength(0);
  });

  it('returns a response when the panel cancels its active generation', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const createRequest = deferred<any>();
    const pollRequest = deferred<any>();
    apiMock.createSubtitleJob.mockReturnValue(createRequest.promise);
    apiMock.getSubtitleJob.mockReturnValue(pollRequest.promise);
    apiMock.cancelSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-cancel', 'cancelled'));

    const generation = dispatch(listener, generationRequest(), {});
    await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1);
    await generation;
    createRequest.resolve(job(VIDEO_A, 'job-cancel'));
    await waitFor(() => (storageMock.values.get('local:tabSubtitleOperations') as Record<string, any> | undefined)?.['1']?.jobId === 'job-cancel');

    const response = await dispatch(listener, {
      type: 'panel.cancelSubtitleJob',
      tabId: 1,
      youtubeVideoId: VIDEO_A,
      jobId: 'job-cancel',
      windowId: 1,
    }, {});

    expect(response).toMatchObject({
      subtitleState: { type: 'no-track' },
    });
    expect(apiMock.cancelSubtitleJob).toHaveBeenCalledWith(
      'install_0123456789abcdef0123456789abcdef',
      'token-1',
      'job-cancel',
    );
  });

  it('publishes clear-local settings and no-track state to every owned tab', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    browserMock.tabs.set(2, { id: 2, windowId: 1, active: false, url: `https://www.youtube.com/watch?v=${VIDEO_B}` });
    browserMock.setActiveTab(1);
    storageMock.values.set('local:activeTracksByVideoId', {
      [VIDEO_A]: { accountId: 'account-1', track: track(VIDEO_A) },
      [VIDEO_B]: { accountId: 'account-1', track: track(VIDEO_B) },
    });
    const listener = await loadBackground();
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(2));

    await dispatch(listener, { type: 'panel.clearLocalState', windowId: 1 }, {});

    const byType = (type: string) => browserMock.sentTabMessages
      .filter(({ message }) => (message as { type?: string }).type === type)
      .map(({ tabId }) => tabId)
      .sort((left, right) => left - right);
    expect(byType('background.settingsChanged')).toEqual([1, 2]);
    expect(byType('background.subtitleStateChanged')).toEqual([1, 2]);
  });
});


describe('saved generation selection', () => {
  async function setup() {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const original = track(VIDEO_A, 'luna-job', 'luna-track');
    storageMock.values.set('local:activeTracksByVideoId', { [VIDEO_A]: { accountId: 'account-1', track: original } });
    const listener = await loadBackground();
    await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1));
    const request = { type: 'panel.selectGeneration', jobId: 'cerebras-job', currentJobId: original.jobId,
      trackId: original.trackId, youtubeVideoId: VIDEO_A, tabId: 1, windowId: 1 };
    return { listener, request, original };
  }

  it('loads the selected job, remembers it, and switches the overlay without generating', async () => {
    const { listener, request } = await setup();
    const selected = track(VIDEO_A, 'cerebras-job', 'cerebras-track');
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, selected.jobId), status: 'completed', track: selected });
    const response = await dispatch(listener, request, {});
    expect(response).toMatchObject({ subtitleState: { type: 'ready', track: selected } });
    expect(storageMock.values.get('local:activeTracksByVideoId')).toMatchObject({ [VIDEO_A]: { track: selected } });
    expect(browserMock.sentTabMessages).toContainEqual(expect.objectContaining({ tabId: 1,
      message: { type: 'background.subtitleStateChanged', subtitleState: { type: 'ready', track: selected } } }));
    expect(apiMock.createSubtitleJob).not.toHaveBeenCalled();
    expect((await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1)))).toMatchObject({ subtitleState: { track: selected } });
  });

  it.each(['navigation', 'account', 'clear', 'failure'])('keeps a delayed selection from overwriting state after %s', async (change) => {
    const { listener, request, original } = await setup();
    const pending = deferred<unknown>();
    apiMock.getSubtitleJob.mockReturnValue(pending.promise);
    const result = dispatch(listener, request, {});
    await waitFor(() => apiMock.getSubtitleJob.mock.calls.length > 0);
    if (change === 'navigation') browserMock.tabs.get(1)!.url = `https://www.youtube.com/watch?v=${VIDEO_B}`;
    if (change === 'account') storageMock.values.set('local:extensionSession', { ...session(), sessionId: 'other-session' });
    if (change === 'clear') await dispatch(listener, { type: 'panel.clearLocalState', windowId: 1 }, {});
    if (change === 'failure') pending.reject(new Error('Unavailable'));
    else pending.resolve({ ...job(VIDEO_A, 'cerebras-job'), status: 'completed', track: track(VIDEO_A, 'cerebras-job', 'cerebras-track') });
    expect(await result).toMatchObject({ ok: false });
    expect(browserMock.sentTabMessages.some(({ message }) => JSON.stringify(message).includes('cerebras-track'))).toBe(false);
    if (change !== 'clear') expect(storageMock.values.get('local:activeTracksByVideoId')).toMatchObject({ [VIDEO_A]: { track: original } });
  });

  it('blocks switching during lyrics replacement', async () => {
    const { listener, request } = await setup();
    apiMock.getLyricsCorrectionStatus.mockResolvedValue({ status: 'running' });
    expect(await dispatch(listener, request, {})).toMatchObject({ ok: false });
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
  });
});

describe('saved generation deletion and page reload', () => {
  async function setup() {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const original = track(VIDEO_A, 'deleted-job', 'deleted-track');
    storageMock.values.set('local:activeTracksByVideoId', { [VIDEO_A]: { accountId: 'account-1', track: original } });
    const listener = await loadBackground();
    const request = { type: 'panel.deleteGeneration', jobId: original.jobId, currentJobId: original.jobId,
      trackId: original.trackId, youtubeVideoId: VIDEO_A, tabId: 1, windowId: 1 };
    return { listener, request, original };
  }

  async function missingJob() {
    const { SubtitleApiError } = await import('../utils/api');
    return new SubtitleApiError('not_found', 'Not found', 404);
  }

  it.each([false, true])('refreshing saved generations clears deleted lyrics and restores another generation when present (%s)', async (hasOther) => {
    const { listener, original } = await setup();
    const other = track(VIDEO_A, 'remaining-job', 'remaining-track');
    const saved = { ...job(VIDEO_A, other.jobId), status: 'completed', track: other };
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: hasOther ? [saved] : [] } as any);
    apiMock.getSubtitleJob.mockResolvedValue(saved);
    const result = await dispatch(listener, { type: 'panel.listGenerations', youtubeVideoId: VIDEO_A, windowId: 1 }, {});
    expect(result.panelState.subtitleState).toEqual(hasOther ? { type: 'ready', track: other } : { type: 'no-track' });
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)?.[VIDEO_A]?.track).toEqual(hasOther ? other : undefined);
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged').at(-1)?.message)
      .toEqual({ type: 'background.subtitleStateChanged', subtitleState: result.panelState.subtitleState });
    expect(apiMock.deleteSavedGeneration).not.toHaveBeenCalled();
    expect(apiMock.getSubtitleJob.mock.calls.some(call => call[2] === original.jobId)).toBe(false);
  });

  it('validates cached lyrics when an older content script sends the original page-entry request', async () => {
    const { listener } = await setup();
    apiMock.getSubtitleJob.mockRejectedValue(await missingJob());
    const result = await dispatch(listener, { type: 'content.getState' }, sender(1));
    expect(result.subtitleState).toEqual({ type: 'no-track' });
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
  });

  it('does not remove a valid selected generation or fetch its track during a list refresh', async () => {
    const { listener, original } = await setup();
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: [{ ...job(VIDEO_A, original.jobId), status: 'completed', trackId: original.trackId }] } as any);
    const result = await dispatch(listener, { type: 'panel.listGenerations', youtubeVideoId: VIDEO_A, windowId: 1 }, {});
    expect(result.panelState).toBeUndefined();
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_A].track).toEqual(original);
  });

  it.each(['page entry', 'saved list'])('publishes another device correction for the same job on %s', async (surface) => {
    const { listener, original } = await setup();
    const corrected = track(VIDEO_A, original.jobId, 'corrected-track');
    corrected.cues[0].sourceText = 'corrected words';
    corrected.cues[0].cueId = 'corrected-cue';
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, original.jobId), status: 'completed', track: corrected });
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: [{ ...job(VIDEO_A, original.jobId), status: 'completed', trackId: corrected.trackId }] } as any);

    const result = await dispatch(listener, surface === 'page entry' ? { type: 'content.getState' }
      : { type: 'panel.listGenerations', youtubeVideoId: VIDEO_A, windowId: 1 }, sender(1));

    expect(surface === 'page entry' ? result.subtitleState : result.panelState.subtitleState).toEqual({ type: 'ready', track: corrected });
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_A].track).toEqual(corrected);
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged').at(-1)?.message)
      .toEqual({ type: 'background.subtitleStateChanged', subtitleState: { type: 'ready', track: corrected } });
  });

  it.each(['page entry', 'saved list'])('keeps richer cached word cards for an unchanged track on %s', async (surface) => {
    const { listener, original } = await setup();
    const serverTrack = structuredClone(original);
    Object.assign(original.cues[0].tokens[0], { gloss: 'a greeting', usageNote: 'cached card' });
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, original.jobId), status: 'completed', track: serverTrack });
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: [{ ...job(VIDEO_A, original.jobId), status: 'completed', trackId: original.trackId }] } as any);

    await dispatch(listener, surface === 'page entry' ? { type: 'content.getState' }
      : { type: 'panel.listGenerations', youtubeVideoId: VIDEO_A, windowId: 1 }, sender(1));

    expect((storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_A].track.cues[0].tokens[0])
      .toMatchObject({ gloss: 'a greeting', usageNote: 'cached card' });
    expect(browserMock.sentTabMessages).toHaveLength(0);
    if (surface === 'saved list') expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
  });

  it.each(['page entry', 'saved list'].flatMap(surface => ['navigation', 'account', 'clear', 'closed tab', 'selection']
    .map(change => [surface, change])))('ignores a correction returned late to %s after %s', async (surface, change) => {
    const { listener, original } = await setup();
    const pending = deferred<unknown>();
    const corrected = track(VIDEO_A, original.jobId, 'late-corrected-track');
    apiMock.getSubtitleJob.mockReturnValueOnce(pending.promise);
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: [{ ...job(VIDEO_A, original.jobId), status: 'completed', trackId: corrected.trackId }] } as any);
    const validation = dispatch(listener, surface === 'page entry' ? { type: 'content.getState' }
      : { type: 'panel.listGenerations', youtubeVideoId: VIDEO_A, windowId: 1 }, sender(1));
    await waitFor(() => apiMock.getSubtitleJob.mock.calls.length > 0);
    if (change === 'navigation') browserMock.tabs.get(1)!.url = `https://www.youtube.com/watch?v=${VIDEO_B}`;
    if (change === 'account') storageMock.values.set('local:extensionSession', { ...session(), sessionId: 'other-session', account: { ...account(), id: 'other-account' } });
    if (change === 'clear') await dispatch(listener, { type: 'panel.clearLocalState', windowId: 1 }, {});
    if (change === 'closed tab') {
      browserMock.tabs.delete(1);
      browserMock.removedListeners.forEach(listener => listener(1));
    }
    if (change === 'selection') {
      const selected = track(VIDEO_A, 'selected-job', 'selected-track');
      apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, selected.jobId), status: 'completed', track: selected });
      await dispatch(listener, { type: 'panel.selectGeneration', jobId: selected.jobId, currentJobId: original.jobId,
        trackId: original.trackId, youtubeVideoId: VIDEO_A, tabId: 1, windowId: 1 }, {});
    }
    browserMock.sentTabMessages.length = 0;
    pending.resolve({ ...job(VIDEO_A, original.jobId), status: 'completed', track: corrected });
    const result = await validation;
    expect(result.subtitleState?.track?.trackId).not.toBe(corrected.trackId);
    expect(result.panelState?.subtitleState?.track?.trackId).not.toBe(corrected.trackId);
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)?.[VIDEO_A]?.track.trackId).not.toBe(corrected.trackId);
    expect(browserMock.sentTabMessages.some(({ message }) => (message as any).subtitleState?.track?.trackId === corrected.trackId)).toBe(false);
  });

  it('ignores a saved-list response after switching to a newer selection', async () => {
    const { listener, original } = await setup();
    const pending = deferred<any>();
    apiMock.listSubtitleJobs.mockReturnValue(pending.promise);
    const refresh = dispatch(listener, { type: 'panel.listGenerations', youtubeVideoId: VIDEO_A, windowId: 1 }, {});
    await waitFor(() => apiMock.listSubtitleJobs.mock.calls.length > 0);
    const selected = track(VIDEO_A, 'selected-job', 'selected-track');
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, selected.jobId), status: 'completed', track: selected });
    await dispatch(listener, { type: 'panel.selectGeneration', jobId: selected.jobId, currentJobId: original.jobId,
      trackId: original.trackId, youtubeVideoId: VIDEO_A, tabId: 1, windowId: 1 }, {});
    pending.resolve({ jobs: [] });
    expect((await refresh).panelState).toBeUndefined();
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_A].track).toEqual(selected);
  });

  it.each([false, true])('deletes a generation and restores the remaining track if present (%s)', async (hasOther) => {
    const { listener, request } = await setup();
    const other = track(VIDEO_A, 'remaining-job', 'remaining-track');
    const saved = { ...job(VIDEO_A, other.jobId), status: 'completed', track: other };
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: hasOther ? [saved] : [] } as any);
    apiMock.getSubtitleJob.mockResolvedValue(saved);
    const result = await dispatch(listener, request, {});
    expect(apiMock.deleteSavedGeneration).toHaveBeenCalledWith(expect.any(String), 'token-1', 'deleted-job');
    expect(result.subtitleState).toEqual(hasOther ? { type: 'ready', track: other } : { type: 'no-track' });
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)?.[VIDEO_A]?.track).toEqual(hasOther ? other : undefined);
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged').at(-1)?.message)
      .toEqual({ type: 'background.subtitleStateChanged', subtitleState: result.subtitleState });
    expect(apiMock.createSubtitleJob).not.toHaveBeenCalled();
  });

  it.each(['failure', 'correction'])('preserves the generation when deletion is blocked by %s', async (reason) => {
    const { listener, request, original } = await setup();
    if (reason === 'failure') apiMock.deleteSavedGeneration.mockRejectedValue(new Error('Offline'));
    else apiMock.getLyricsCorrectionStatus.mockResolvedValue({ status: 'running' });
    expect(await dispatch(listener, request, {})).toMatchObject({ ok: false });
    expect((await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {})).subtitleState)
      .toEqual({ type: 'ready', track: original });
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged')).toHaveLength(0);
  });

  it.each(['navigation', 'account', 'clear'])('does not publish a delayed deletion into another context after %s', async (change) => {
    const { listener, request } = await setup();
    const pending = deferred<unknown>();
    apiMock.deleteSavedGeneration.mockReturnValue(pending.promise);
    const result = dispatch(listener, request, {});
    await waitFor(() => apiMock.deleteSavedGeneration.mock.calls.length > 0);
    if (change === 'navigation') browserMock.tabs.get(1)!.url = `https://www.youtube.com/watch?v=${VIDEO_B}`;
    if (change === 'account') storageMock.values.set('local:extensionSession', { ...session(), sessionId: 'other-session' });
    if (change === 'clear') await dispatch(listener, { type: 'panel.clearLocalState', windowId: 1 }, {});
    browserMock.sentTabMessages.length = 0;
    pending.resolve({ ok: true });
    await result;
    expect(browserMock.sentTabMessages.filter(({ message }) => (message as any).type === 'background.subtitleStateChanged')).toHaveLength(0);
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)?.[VIDEO_A]).toBeUndefined();
  });

  it.each([false, true])('revalidates a dashboard-deleted cached track on page entry, with another generation: %s', async (hasOther) => {
    const { listener, original } = await setup();
    // An already-open tab may keep displaying the deleted lyrics until its next page entry.
    expect((await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: false }, sender(1))).subtitleState.track).toEqual(original);
    const other = track(VIDEO_A, 'remaining-job', 'remaining-track');
    const saved = { ...job(VIDEO_A, other.jobId), status: 'completed', track: other };
    const missing = await missingJob();
    apiMock.getSubtitleJob.mockImplementation(async (_installId, _token, id) => {
      if (id === original.jobId) throw missing;
      return saved;
    });
    apiMock.listSubtitleJobs.mockResolvedValue({ jobs: hasOther ? [saved] : [] } as any);
    const result = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: true }, sender(1));
    expect(result.subtitleState).toEqual(hasOther ? { type: 'ready', track: other } : { type: 'no-track' });
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)?.[VIDEO_A]?.track).toEqual(hasOther ? other : undefined);
    expect(browserMock.sentTabMessages.at(-1)?.message).toEqual({ type: 'background.subtitleStateChanged', subtitleState: result.subtitleState });
    apiMock.getSubtitleJob.mockClear();
    apiMock.listSubtitleJobs.mockClear();
    await dispatch(listener, { type: 'panel.getState', syncBackend: false, windowId: 1 }, {});
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    expect(apiMock.listSubtitleJobs).not.toHaveBeenCalled();
  });

  it('validates a remembered selection when opening a new tab and keeps it if available', async () => {
    const { listener, original } = await setup();
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, original.jobId), status: 'completed', track: original });
    const result = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: true }, sender(1));
    expect(result.subtitleState).toEqual({ type: 'ready', track: original });
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    expect(apiMock.listSubtitleJobs).not.toHaveBeenCalled();
  });

  it('keeps an unverified cache hidden on network failure and retries on the next page entry', async () => {
    const { listener, original } = await setup();
    apiMock.getSubtitleJob.mockRejectedValue(new Error('Offline'));
    const result = await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: true }, sender(1));
    expect(result.subtitleState).toMatchObject({ type: 'error', message: expect.stringContaining('Refresh') });
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_A].track).toEqual(original);
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, original.jobId), status: 'completed', track: original });
    expect((await dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: true }, sender(1))).subtitleState)
      .toEqual({ type: 'ready', track: original });
  });

  it('does not clear a newer selection when page validation returns late', async () => {
    const { listener, original } = await setup();
    const pending = deferred<unknown>();
    apiMock.getSubtitleJob.mockReturnValueOnce(pending.promise);
    const validation = dispatch(listener, { type: 'content.getState', revalidateSavedGeneration: true }, sender(1));
    await waitFor(() => apiMock.getSubtitleJob.mock.calls.length > 0);
    const selected = track(VIDEO_A, 'selected-job', 'selected-track');
    apiMock.getSubtitleJob.mockResolvedValue({ ...job(VIDEO_A, selected.jobId), status: 'completed', track: selected });
    await dispatch(listener, { type: 'panel.selectGeneration', jobId: selected.jobId, currentJobId: original.jobId,
      trackId: original.trackId, youtubeVideoId: VIDEO_A, tabId: 1, windowId: 1 }, {});
    pending.reject(await missingJob());
    expect((await validation).subtitleState).toEqual({ type: 'ready', track: selected });
    expect((storageMock.values.get('local:activeTracksByVideoId') as any)[VIDEO_A].track).toEqual(selected);
  });
});
