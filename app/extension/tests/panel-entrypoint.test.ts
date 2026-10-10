import { afterEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';
import type { TrackResponse } from '../utils/contracts';
import trackFixture from '../../../packages/contracts/fixtures/valid-track-response.json';

const mocks = vi.hoisted(() => ({
  sendMessage: vi.fn(() => new Promise(() => {})),
  connect: vi.fn(() => ({ onDisconnect: { addListener: vi.fn() } })),
  messages: vi.fn(),
  activated: vi.fn(),
  updated: vi.fn(),
  createTab: vi.fn(async (_options: { url: string }) => ({})),
}));
vi.mock('wxt/browser', () => ({ browser: {
  runtime: { sendMessage: mocks.sendMessage, connect: mocks.connect, onMessage: { addListener: mocks.messages } },
  windows: { getCurrent: vi.fn(async () => ({ id: 7 })) },
  tabs: { create: mocks.createTab, onActivated: { addListener: mocks.activated }, onUpdated: { addListener: mocks.updated } },
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
    backendUrl: 'http://127.0.0.1:8001/v1',
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
  expect([...selector.options].map(option => option.value)).toEqual(['openai', 'cerebras']);

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
    backendUrl: 'http://127.0.0.1:8001/v1', subtitleState: { type: 'no-track' }, jobHistory: [],
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
    const settingsTab = dom.window.document.querySelector('[data-i18n="Settings"]')?.textContent?.trim();
    if (locale === 'en') expect(settingsTab).toBe('Settings');
    else expect(settingsTab).not.toBe('Settings');
  }

  state.pageStatus = { supported: true, videoId: 'M7lc1UVf-VE', url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', mediaKind: 'video' };
  await refresh();
  expect(state.settings.sourceLanguage).toBe('auto');
  expect(selected()).toContain('Auto');
  expect(state.settings.targetLanguage).toBe('fra');
  dom.window.close();
});

it('switches API and Codex billing and stores a discovered model with capability-aware fast mode', async () => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  let state: PanelState = {
    installId: 'install_test', settings: DEFAULT_EXTENSION_SETTINGS, backendUrl: 'http://localhost/v1',
    subtitleState: { type: 'no-track' }, jobHistory: [],
    codexAccount: { available: true, connected: true, login: null, models: [
      { id: 'fast-model', name: 'Fast model', supportsFastMode: true },
      { id: 'standard-model', name: 'Standard model', supportsFastMode: false },
    ] },
  };
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string; patch?: Partial<PanelState['settings']> }) => {
    if (request?.type === 'panel.updateSettings') state = { ...state, settings: { ...state.settings, ...request.patch } };
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  const source = dom.window.document.querySelector<HTMLInputElement>('input[name="aiSource"][value="codex"]')!;
  source.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings).toMatchObject({ aiProvider: 'codex', codexModel: 'fast-model' });
  expect(dom.window.document.querySelector<HTMLElement>('[data-api-options]')!.hidden).toBe(true);
  const model = dom.window.document.querySelector<HTMLSelectElement>('select[name="codexModel"]')!;
  expect([...model.options].map(option => option.value)).toEqual(['', 'fast-model', 'standard-model']);
  const fast = dom.window.document.querySelector<HTMLInputElement>('input[name="codexFastMode"]')!;
  expect(fast.disabled).toBe(false);
  fast.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings.codexFastMode).toBe(true);
  model.value = 'standard-model';
  model.dispatchEvent(new dom.window.Event('change'));
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings).toMatchObject({ codexModel: 'standard-model', codexFastMode: false });
  expect(fast.disabled).toBe(true);
  state = { ...state, settings: { ...state.settings, codexFastMode: true } };
  Object.defineProperty(dom.window.document, 'visibilityState', { configurable: true, value: 'visible' });
  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
  await vi.advanceTimersByTimeAsync(0);
  expect(fast.disabled).toBe(false); // A saved unsupported preference must still be removable.
  fast.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings.codexFastMode).toBe(false);
  dom.window.document.querySelector<HTMLInputElement>('input[name="aiSource"][value="api"]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings.aiProvider).toBe('openai');
  expect(dom.window.document.querySelector<HTMLElement>('[data-codex-options]')!.hidden).toBe(true);
  dom.window.close();
});

it('shows the Claude model picker for Claude billing and stores the chosen model', async () => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  let state: PanelState = {
    installId: 'install_test', settings: DEFAULT_EXTENSION_SETTINGS, backendUrl: 'http://localhost/v1',
    subtitleState: { type: 'no-track' }, jobHistory: [],
  };
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string; patch?: Partial<PanelState['settings']> }) => {
    if (request?.type === 'panel.updateSettings') state = { ...state, settings: { ...state.settings, ...request.patch } };
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  const claudeOptions = dom.window.document.querySelector<HTMLElement>('[data-claude-options]')!;
  expect(claudeOptions.hidden).toBe(true);
  dom.window.document.querySelector<HTMLInputElement>('input[name="aiSource"][value="claude"]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings.aiProvider).toBe('claude');
  expect(claudeOptions.hidden).toBe(false);
  const model = dom.window.document.querySelector<HTMLSelectElement>('select[name="claudeModel"]')!;
  expect([...model.options].map(option => option.value)).toEqual(['opus', 'sonnet', 'haiku']);
  expect(model.value).toBe('sonnet');
  model.value = 'haiku';
  model.dispatchEvent(new dom.window.Event('change'));
  await vi.advanceTimersByTimeAsync(0);
  expect(state.settings.claudeModel).toBe('haiku');
  dom.window.document.querySelector<HTMLInputElement>('input[name="aiSource"][value="codex"]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(claudeOptions.hidden).toBe(true);
  dom.window.close();
});

it('opens OAuth with the browser API and refreshes connection state when the user returns', async () => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  mocks.createTab.mockClear();
  const authUrl = 'https://auth.openai.com/oauth/authorize?state=fixture';
  let state: PanelState = { installId: 'install_test', settings: DEFAULT_EXTENSION_SETTINGS, backendUrl: 'http://localhost/v1',
    subtitleState: { type: 'no-track' }, jobHistory: [], codexAccount: { available: true, connected: false, models: [], login: null } };
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string }) => {
    if (request?.type === 'panel.loginCodex') {
      state = { ...state, codexAccount: { ...state.codexAccount!, login: { status: 'awaiting_authorization', authUrl } } };
      return structuredClone(state.codexAccount);
    }
    if (request?.type === 'panel.getCodexAccount') return structuredClone(state.codexAccount);
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  dom.window.document.querySelector<HTMLButtonElement>('[data-codex-connect]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(mocks.createTab).toHaveBeenCalledExactlyOnceWith({ url: authUrl });
  state = { ...state, codexAccount: { ...state.codexAccount!, connected: true, login: null } };
  Object.defineProperty(dom.window.document, 'visibilityState', { configurable: true, value: 'visible' });
  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
  await vi.advanceTimersByTimeAsync(6000);
  expect(dom.window.document.querySelector('[data-codex-status]')?.textContent).toBe('Codex connected.');
  expect(mocks.createTab).toHaveBeenCalledTimes(1);
  dom.window.close();
});

it.each(['read', 'mutation'])('keeps the newer %s rendered when an earlier backend poll returns last', async (kind) => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  mocks.activated.mockClear();
  const original: PanelState = { installId: 'install_test', settings: DEFAULT_EXTENSION_SETTINGS,
    backendUrl: 'http://localhost/v1', subtitleState: { type: 'no-track' }, jobHistory: [] };
  let state = original;
  let resolvePoll!: (state: PanelState) => void;
  const oldPoll = new Promise<PanelState>(resolve => { resolvePoll = resolve; });
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string; syncBackend?: boolean; patch?: Partial<PanelState['settings']> }) => {
    if (request?.type === 'panel.getState' && request.syncBackend) return oldPoll;
    if (request?.type === 'panel.updateSettings') state = { ...state, settings: { ...state.settings, ...request.patch } };
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  const attach = dom.window.document.querySelector<HTMLInputElement>('input[name="overlayAttachedToVideo"]')!;
  expect(attach.checked).toBe(true);
  if (kind === 'mutation') {
    attach.checked = false;
    attach.dispatchEvent(new dom.window.Event('change'));
  } else {
    state = { ...original, settings: { ...original.settings, overlayAttachedToVideo: false } };
    const onActivated = mocks.activated.mock.calls[0]?.[0] as (info: { windowId: number }) => void;
    onActivated({ windowId: 7 });
  }
  await vi.advanceTimersByTimeAsync(60);
  expect(attach.checked).toBe(false);
  resolvePoll(original);
  await vi.advanceTimersByTimeAsync(0);
  expect(attach.checked).toBe(false);
  dom.window.close();
});

it('does not repeat a completed deletion whose reply is missing', async () => {
  vi.resetModules();
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  stubPanelDom(dom);
  vi.spyOn(dom.window, 'confirm').mockReturnValue(true);
  const track = { ...trackFixture, expiresAt: null } as TrackResponse;
  const state: PanelState = { installId: 'install_test', settings: DEFAULT_EXTENSION_SETTINGS,
    activeTabId: 1, backendUrl: 'http://localhost/v1', jobHistory: [],
    pageStatus: { supported: true, videoId: track.youtubeVideoId,
      url: `https://www.youtube.com/watch?v=${track.youtubeVideoId}`, mediaKind: 'video' },
    subtitleState: { type: 'ready', track } };
  let deletes = 0;
  mocks.sendMessage.mockReset().mockImplementation(async (request?: { type?: string }) => {
    if (request?.type === 'panel.listGenerations') return { jobs: [] };
    if (request?.type === 'panel.getActiveCue') return { ok: false };
    if (request?.type === 'panel.deleteGeneration') {
      deletes += 1;
      return undefined; // The deletion completed, but its acknowledgement did not arrive.
    }
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  const select = dom.window.document.querySelector<HTMLSelectElement>('[data-generation-select]')!;
  expect(select.disabled).toBe(false);
  select.value = 'delete-current-generation';
  select.dispatchEvent(new dom.window.Event('change'));
  await vi.advanceTimersByTimeAsync(200);
  expect(deletes).toBe(1);
  expect(dom.window.document.querySelector('[data-generation-status]')?.textContent).toContain('background did not respond');
  dom.window.close();
});
