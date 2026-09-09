import { afterEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';

const mocks = vi.hoisted(() => ({
  sendMessage: vi.fn(() => new Promise(() => {})),
  connect: vi.fn(() => ({ onDisconnect: { addListener: vi.fn() } })),
  messages: vi.fn(),
  activated: vi.fn(),
  updated: vi.fn(),
}));
vi.mock('wxt/browser', () => ({ browser: {
  runtime: { sendMessage: mocks.sendMessage, connect: mocks.connect, onMessage: { addListener: mocks.messages } },
  windows: { getCurrent: vi.fn(async () => ({ id: 7 })) },
  tabs: { onActivated: { addListener: mocks.activated }, onUpdated: { addListener: mocks.updated } },
} }));

afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

function stubPanelDom(dom: JSDOM): void {
  vi.stubGlobal('window', dom.window);
  vi.stubGlobal('document', dom.window.document);
  vi.stubGlobal('Element', dom.window.Element);
  for (const name of ['HTMLElement', 'HTMLButtonElement', 'HTMLInputElement', 'HTMLTextAreaElement', 'HTMLSelectElement', 'HTMLDetailsElement', 'HTMLFormElement', 'HTMLOutputElement']) {
    vi.stubGlobal(name, dom.window[name as keyof Window]);
  }
}

it('imports the real panel entrypoint and attaches synchronization without a lexical startup error', async () => {
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  await import('../entrypoints/sidepanel/main');
  await Promise.resolve();
  expect(mocks.connect).toHaveBeenCalledOnce();
  expect(mocks.messages).toHaveBeenCalledOnce();
  expect(mocks.activated).toHaveBeenCalledOnce();
  expect(mocks.updated).toHaveBeenCalledOnce();
  expect(vi.getTimerCount()).toBe(1);

  const panelMessageListener = mocks.messages.mock.calls[0]?.[0] as ((message: unknown) => unknown) | undefined;
  expect(panelMessageListener?.({
    type: 'panel.cancelSubtitleJob',
    jobId: 'job-1',
    youtubeVideoId: 'dQw4w9WgXcQ',
  })).toBe(false);

  dom.window.close();
});

it('shows the main generation cancel action while the job id is still being created', async () => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  const preparingState: PanelState = {
    installId: 'install_test',
    settings: DEFAULT_EXTENSION_SETTINGS,
    activeTabId: 1,
    pageStatus: {
      supported: true,
      videoId: 'dQw4w9WgXcQ',
      url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      mediaKind: 'video',
    },
    accountState: {
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
    },
    subtitleState: {
      type: 'loading',
      status: 'running',
      youtubeVideoId: 'dQw4w9WgXcQ',
      message: 'Preparing request...',
      stage: 'preparing',
      progressPercent: 5,
    },
    jobHistory: [],
    lyricsCorrection: null,
  };
  const runningState: PanelState = {
    ...preparingState,
    subtitleState: {
      type: 'loading',
      status: 'running',
      jobId: 'job-1',
      youtubeVideoId: 'dQw4w9WgXcQ',
      message: 'Generating subtitles...',
      stage: 'preparing',
      progressPercent: 5,
    },
  };
  let responseState = preparingState;
  let resolveCancellation!: (state: PanelState) => void;
  const cancellationResponse = new Promise<PanelState>((resolve) => {
    resolveCancellation = resolve;
  });
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string }) => {
    if (request?.type === 'panel.cancelSubtitleJob') return cancellationResponse;
    return structuredClone(responseState);
  });

  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);

  const cancelButton = dom.window.document.querySelector<HTMLButtonElement>('[data-action="cancel-generation"]');
  expect(cancelButton?.hidden).toBe(false);
  expect(cancelButton?.disabled).toBe(true);
  expect(cancelButton?.textContent).toBe('Cancel generation');
  expect(dom.window.document.querySelector<HTMLElement>('[data-panel="watch"]')?.hidden).toBe(false);
  expect(dom.window.document.querySelector<HTMLElement>('[data-progress]')?.hidden).toBe(false);

  responseState = runningState;
  Object.defineProperty(dom.window.document, 'visibilityState', { configurable: true, value: 'visible' });
  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
  await vi.advanceTimersByTimeAsync(0);

  expect(cancelButton?.hidden).toBe(false);
  expect(cancelButton?.disabled).toBe(false);
  expect(cancelButton?.dataset.jobId).toBe('job-1');
  expect(cancelButton?.dataset.youtubeVideoId).toBe('dQw4w9WgXcQ');
  expect(cancelButton?.dataset.tabId).toBe('1');

  cancelButton?.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(cancelButton?.disabled).toBe(true);
  expect(cancelButton?.textContent).toBe('Cancelling…');
  expect(mocks.sendMessage).toHaveBeenCalledWith(expect.objectContaining({
    type: 'panel.cancelSubtitleJob',
    jobId: 'job-1',
    youtubeVideoId: 'dQw4w9WgXcQ',
    tabId: 1,
    windowId: 7,
  }));

  resolveCancellation({
    ...preparingState,
    subtitleState: { type: 'no-track' },
  });
  await vi.advanceTimersByTimeAsync(0);
  expect(cancelButton?.hidden).toBe(true);
  expect(dom.window.document.querySelector<HTMLElement>('[data-progress]')?.hidden).toBe(true);

  dom.window.close();
});
