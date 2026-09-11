import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

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
      reads[index].resolve(value);
      reads.splice(index, 1);
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
  enrichLearningToken: vi.fn(),
  createSubtitleJob: vi.fn(),
  getSubtitleJob: vi.fn(),
  getSubtitleJobPartialTrack: vi.fn(),
  cancelSubtitleJob: vi.fn(),
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
    enrichLearningToken(...args: unknown[]) { return apiMock.enrichLearningToken(...args); }
    createSubtitleJob(...args: unknown[]) { return apiMock.createSubtitleJob(...args); }
    getSubtitleJob(...args: unknown[]) { return apiMock.getSubtitleJob(...args); }
    getSubtitleJobPartialTrack(...args: unknown[]) { return apiMock.getSubtitleJobPartialTrack(...args); }
    cancelSubtitleJob(...args: unknown[]) { return apiMock.cancelSubtitleJob(...args); }
    listSubtitleJobs(...args: unknown[]) {
      return (apiMock.listSubtitleJobs as unknown as (...parameters: unknown[]) => unknown)(...args);
    }
    getExtensionAccount(...args: unknown[]) { return apiMock.getExtensionAccount(...args); }
  },
  SubtitleApiError: class MockSubtitleApiError extends Error {
    public readonly name = 'SubtitleApiError';
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

function track(videoId: string, jobId = `job-${videoId}`, trackId = `track-${videoId}`) {
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
  vi.resetModules();
  storageMock.reset();
  browserMock.reset();
  apiMock.enrichLearningToken.mockReset();
  apiMock.createSubtitleJob.mockReset();
  apiMock.getSubtitleJob.mockReset();
  apiMock.getSubtitleJobPartialTrack.mockReset();
  apiMock.cancelSubtitleJob.mockReset();
  apiMock.listSubtitleJobs.mockReset().mockResolvedValue({ jobs: [] });
  apiMock.getExtensionAccount.mockReset().mockResolvedValue({ account: account() });
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('background entrypoint review regressions', () => {
  it.each(['transcribing', 'tokenizing'])('polls %s work quickly, backs off unchanged results, and stops on completion', async (stage) => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const runningJob = { ...job(VIDEO_A, 'job-poll'), stage };
    apiMock.createSubtitleJob.mockResolvedValue(runningJob);
    apiMock.getSubtitleJob.mockResolvedValue(runningJob);
    apiMock.getSubtitleJobPartialTrack.mockResolvedValue({
      jobId: 'job-poll', youtubeVideoId: VIDEO_A, revision: 1, cues: track(VIDEO_A).cues,
    });
    const listener = await loadBackground();

    await dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
    await waitFor(() => apiMock.getSubtitleJobPartialTrack.mock.calls.length === 1);
    await waitFor(() => vi.getTimerCount() > 0);
    const operationBeforeUnchangedPoll = storageMock.values.get('local:tabSubtitleOperations');
    // The initial panel response also reads status once to recover its view.
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(1999);
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    expect(apiMock.getSubtitleJobPartialTrack).toHaveBeenCalledTimes(2);
    expect(storageMock.values.get('local:tabSubtitleOperations')).toBe(operationBeforeUnchangedPoll);

    apiMock.getSubtitleJobPartialTrack.mockResolvedValue({
      jobId: 'job-poll', youtubeVideoId: VIDEO_A, revision: 2, cues: track(VIDEO_A).cues,
    });
    await vi.advanceTimersByTimeAsync(3999);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(2);
    expect(apiMock.getSubtitleJobPartialTrack).toHaveBeenCalledTimes(3);
    expect((storageMock.values.get('local:tabSubtitleOperations') as Record<string, any>)['1'].partialTrack.revision).toBe(2);

    apiMock.getSubtitleJob.mockResolvedValue({ ...runningJob, status: 'completed', stage: 'completed', track: track(VIDEO_A, 'job-poll') });
    await vi.advanceTimersByTimeAsync(2000);
    await vi.advanceTimersByTimeAsync(20000);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(3);
    expect(apiMock.getSubtitleJobPartialTrack).toHaveBeenCalledTimes(3);
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(1);
  });

  it('backs off failed partial fetches and stops pending polling when cancelled', async () => {
    vi.useFakeTimers();
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const runningJob = { ...job(VIDEO_A, 'job-poll'), stage: 'tokenizing' };
    apiMock.createSubtitleJob.mockResolvedValue(runningJob);
    apiMock.getSubtitleJob.mockResolvedValue(runningJob);
    apiMock.getSubtitleJobPartialTrack.mockRejectedValue(new Error('temporarily unavailable'));
    apiMock.cancelSubtitleJob.mockResolvedValue(job(VIDEO_A, 'job-poll', 'cancelled'));
    const listener = await loadBackground();

    await dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
    await waitFor(() => apiMock.getSubtitleJobPartialTrack.mock.calls.length === 1);
    await waitFor(() => vi.getTimerCount() > 0);
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(9999);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(2);
    expect(apiMock.getSubtitleJobPartialTrack).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1001);
    expect(apiMock.getSubtitleJob).toHaveBeenCalledTimes(3);
    expect(apiMock.getSubtitleJobPartialTrack).toHaveBeenCalledTimes(2);

    await dispatch(listener, {
      type: 'panel.cancelSubtitleJob', tabId: 1, youtubeVideoId: VIDEO_A, jobId: 'job-poll', windowId: 1,
    }, {});
    apiMock.getSubtitleJob.mockClear();
    await vi.advanceTimersByTimeAsync(30000);
    expect(apiMock.getSubtitleJob).not.toHaveBeenCalled();
    expect(apiMock.getSubtitleJobPartialTrack).toHaveBeenCalledTimes(2);
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
    let revision = 0;
    apiMock.getSubtitleJobPartialTrack.mockImplementation(async (_installId, _token, jobId) => ({
      jobId, youtubeVideoId: jobs.find((item) => item.jobId === jobId)!.youtubeVideoId,
      revision: ++revision, cues: track(VIDEO_A).cues,
    }));
    const listener = await loadBackground();
    await dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
    await waitFor(() => apiMock.getSubtitleJobPartialTrack.mock.calls.length === 1);
    await dispatch(listener, { type: 'panel.updateSettings', patch: { aiProvider: 'cerebras' }, windowId: 1 }, {});
    browserMock.setActiveTab(2);
    await dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
    await waitFor(() => apiMock.getSubtitleJobPartialTrack.mock.calls.length === 2);
    expect(apiMock.createSubtitleJob.mock.calls.map((call) => call[2].aiProvider)).toEqual(['openai', 'cerebras']);
    await waitFor(() => vi.getTimerCount() === 2);
    apiMock.getSubtitleJob.mockClear();
    apiMock.getSubtitleJobPartialTrack.mockClear();

    await vi.advanceTimersByTimeAsync(60000);
    const requests = apiMock.getSubtitleJob.mock.calls.length + apiMock.getSubtitleJobPartialTrack.mock.calls.length;
    expect(apiMock.getSubtitleJob.mock.calls.length).toBeGreaterThan(0);
    expect(requests).toBeLessThanOrEqual(60);
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(2);
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
    const current = await dispatch(listener, { type: 'content.getState' }, sender(1));
    expect(current.subtitleState.track.cues[0].tokens).toEqual(remembered.cues[0].tokens);
  });

  it('keeps a live generation loading while its create request is pending', async () => {
    seedBaseState();
    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const listener = await loadBackground();
    const createRequest = deferred<any>();
    apiMock.createSubtitleJob.mockReturnValue(createRequest.promise);

    const firstGeneration = dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
    await waitFor(() => apiMock.createSubtitleJob.mock.calls.length === 1);
    const firstState = await firstGeneration;
    expect(firstState.subtitleState).toMatchObject({
      type: 'loading',
      status: 'running',
      youtubeVideoId: VIDEO_A,
      message: 'Preparing request...',
    });

    await dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
    expect(apiMock.createSubtitleJob).toHaveBeenCalledTimes(1);

    createRequest.resolve({
      ...job(VIDEO_A, 'job-live'),
      status: 'completed' as const,
      track: track(VIDEO_A, 'job-live', 'track-live'),
    });
    await waitFor(() => (storageMock.values.get('local:activeTracksByVideoId') as Record<string, unknown> | undefined)?.[VIDEO_A] !== undefined);

    const finalState = await dispatch(listener, { type: 'content.getState' }, sender(1));
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

    const initialRecovery = dispatch(listener, { type: 'content.getState' }, sender(1));
    await waitFor(() => apiMock.listSubtitleJobs.mock.calls.length === 1);

    const generation = dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
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
    const stateAfterNavigation = await dispatch(listener, { type: 'content.getState' }, sender(1));
    expect(stateAfterNavigation.subtitleState).toEqual({ type: 'no-track' });

    browserMock.tabs.set(1, { id: 1, windowId: 1, active: true, url: `https://www.youtube.com/watch?v=${VIDEO_A}` });
    const stateWhileCreateWaits = await dispatch(listener, { type: 'content.getState' }, sender(1));
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

    const finalState = await dispatch(listener, { type: 'content.getState' }, sender(1));
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
    await dispatch(listener, { type: 'content.getState' }, sender(1));

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
    const stateWhileCleanupWaits = await dispatch(listener, { type: 'content.getState' }, sender(1));
    expect(stateWhileCleanupWaits.subtitleState).toMatchObject({
      type: 'ready',
      track: { youtubeVideoId: VIDEO_B, jobId: 'job-b', trackId: 'track-b' },
    });

    storageMock.resolveReadAt('local:tabSubtitleOperations', 3, { '1': newerOperation });
    await cancellation;

    expect(storageMock.values.get('local:tabSubtitleOperations')).toEqual({ '1': newerOperation });
    const finalState = await dispatch(listener, { type: 'content.getState' }, sender(1));
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

    const generation = dispatch(listener, { type: 'panel.generateSubtitles', windowId: 1 }, {});
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
    await dispatch(listener, { type: 'content.getState' }, sender(1));
    await dispatch(listener, { type: 'content.getState' }, sender(2));

    await dispatch(listener, { type: 'panel.clearLocalState', windowId: 1 }, {});

    const byType = (type: string) => browserMock.sentTabMessages
      .filter(({ message }) => (message as { type?: string }).type === type)
      .map(({ tabId }) => tabId)
      .sort((left, right) => left - right);
    expect(byType('background.settingsChanged')).toEqual([1, 2]);
    expect(byType('background.subtitleStateChanged')).toEqual([1, 2]);
  });
});
