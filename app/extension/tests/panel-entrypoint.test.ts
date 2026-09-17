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
  for (const name of ['HTMLElement', 'HTMLButtonElement', 'HTMLInputElement', 'HTMLTextAreaElement', 'HTMLSelectElement', 'HTMLDetailsElement', 'HTMLFormElement', 'HTMLOutputElement', 'HTMLDialogElement']) {
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

it('cancels generation and saves the model selected for the next generation', async () => {
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
      aiModel: 'gpt-oss-120b',
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
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string; patch?: Partial<PanelState['settings']> }) => {
    if (request?.type === 'panel.cancelSubtitleJob') return cancellationResponse;
    if (request?.type === 'panel.updateSettings') responseState = { ...responseState, settings: { ...responseState.settings, ...request.patch } };
    return structuredClone(responseState);
  });

  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);

  const cancelButton = dom.window.document.querySelector<HTMLButtonElement>('[data-action="cancel-generation"]');
  expect(cancelButton?.hidden).toBe(false);
  expect(cancelButton?.disabled).toBe(true);
  expect(cancelButton?.textContent).toBe('Cancel generation');
  expect(dom.window.document.querySelector('[data-account-model]')?.textContent).toBe('Next generation: Transcriber');
  expect(dom.window.document.querySelector<HTMLElement>('[data-account-model]')?.hidden).toBe(false);
  expect(dom.window.document.querySelector<HTMLElement>('[data-panel="watch"]')?.hidden).toBe(false);
  expect(dom.window.document.querySelector<HTMLElement>('[data-progress]')?.hidden).toBe(false);
  expect(dom.window.document.querySelector('[data-progress-activity]')?.textContent).toBe('Preparing request');
  expect(dom.window.document.querySelector<HTMLElement>('[data-progress-stages]')?.hidden).toBe(true);

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

  responseState = { ...preparingState, subtitleState: { type: 'no-track' } };
  const selector = dom.window.document.querySelector<HTMLSelectElement>('select[name="aiProvider"]')!;
  expect(selector.disabled).toBe(false);
  expect(selector.value).toBe('openai');
  selector.value = 'cerebras';
  selector.dispatchEvent(new dom.window.Event('change'));
  await vi.advanceTimersByTimeAsync(0);
  expect(mocks.sendMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'panel.updateSettings', patch: { aiProvider: 'cerebras' } }));
  expect(selector.value).toBe('cerebras');
  expect(dom.window.document.querySelector('[data-account-model]')?.textContent).toBe('Next generation: Transcriber Spark');

  const attachInput = dom.window.document.querySelector<HTMLInputElement>('input[name="overlayAttachedToVideo"]')!;
  expect(attachInput.disabled).toBe(false);
  expect(attachInput.checked).toBe(true);
  for (const checked of [false, true]) {
    attachInput.checked = checked;
    attachInput.dispatchEvent(new dom.window.Event('change'));
    await vi.advanceTimersByTimeAsync(0);
    expect(mocks.sendMessage).toHaveBeenCalledWith(expect.objectContaining({
      type: 'panel.updateSettings', patch: { overlayAttachedToVideo: checked },
    }));
    expect(attachInput.checked).toBe(checked);
  }

  dom.window.close();
});

it('resets video language on panel startup and video changes, but preserves manual choices on ordinary refreshes', async () => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup, { pretendToBeVisual: true });
  stubPanelDom(dom);
  let state: PanelState = {
    installId: 'install_test', activeTabId: 1,
    settings: { ...DEFAULT_EXTENSION_SETTINGS, sourceLanguage: 'jpn', targetLanguage: 'fra' },
    pageStatus: { supported: true, videoId: 'dQw4w9WgXcQ', url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', mediaKind: 'video' },
    accountState: { status: 'anonymous' }, subtitleState: { type: 'no-track' }, jobHistory: [],
  };
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string; patch?: Partial<PanelState['settings']> }) => {
    if (request?.type === 'panel.listGenerations') return { jobs: [] };
    if (request?.type === 'panel.updateSettings') state = { ...state, settings: { ...state.settings, ...request.patch } };
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  const selected = () => dom.window.document.querySelector('[data-source-language-selected]')?.textContent;
  const refresh = async () => {
    dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(0);
  };
  expect(state.settings.sourceLanguage).toBe('auto');
  expect(selected()).toContain('Auto');
  expect(dom.window.document.querySelector('[data-source-language-list]')?.getAttribute('aria-label')).toBe('Video language');

  dom.window.document.querySelector<HTMLButtonElement>('[data-source-language-list] [data-language-code="jpn"]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  await refresh();
  expect(state.settings.sourceLanguage).toBe('jpn');
  expect(selected()).toContain('Japanese');

  const interfaceSelector = dom.window.document.querySelector<HTMLSelectElement>('[data-interface-language]')!;
  for (const locale of ['es', 'ja', 'en']) {
    interfaceSelector.value = locale;
    interfaceSelector.dispatchEvent(new dom.window.Event('change'));
    await vi.advanceTimersByTimeAsync(0);
    await refresh();
    expect(mocks.sendMessage).toHaveBeenCalledWith(expect.objectContaining({
      type: 'panel.updateSettings', patch: { interfaceLocale: locale },
    }));
    expect(dom.window.document.documentElement.lang).toBe(locale);
    expect(interfaceSelector.value).toBe(locale);
    expect(state.settings.sourceLanguage).toBe('jpn');
    expect(state.settings.targetLanguage).toBe('fra');
    const signIn = dom.window.document.querySelector('[data-i18n="Sign in"]')?.textContent?.trim();
    if (locale === 'en') expect(signIn).toBe('Sign in');
    else expect(signIn).not.toBe('Sign in');
  }

  state.pageStatus = { supported: true, videoId: 'M7lc1UVf-VE', url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', mediaKind: 'video' };
  await refresh();
  expect(state.settings.sourceLanguage).toBe('auto');
  expect(selected()).toContain('Auto');
  expect(state.settings.targetLanguage).toBe('fra');
  dom.window.close();
});
