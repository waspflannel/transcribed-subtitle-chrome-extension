import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';
import type { TrackResponse } from '../utils/contracts';

const mocks = vi.hoisted(() => ({ send: vi.fn(), activated: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: {
  runtime: { sendMessage: mocks.send, connect: () => ({ onDisconnect: { addListener() {} } }), onMessage: { addListener() {} } },
  windows: { getCurrent: async () => ({ id: 7 }) },
  tabs: { onActivated: { addListener: mocks.activated }, onUpdated: { addListener() {} } },
} }));

let dom: JSDOM;
let state: PanelState;
const button = (action: string) => dom.window.document.querySelector<HTMLButtonElement>(`[data-action="${action}"]`)!;
const dialog = () => dom.window.document.querySelector<HTMLDialogElement>('dialog')!;
const generationRequests = () => mocks.send.mock.calls.filter(([request]) => request.type === 'panel.generateSubtitles');
const refresh = async () => {
  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
  await vi.advanceTimersByTimeAsync(0);
};

beforeEach(async () => {
  vi.resetModules();
  vi.useFakeTimers();
  mocks.send.mockReset();
  mocks.activated.mockReset();
  dom = new JSDOM(markup, { pretendToBeVisual: true });
  vi.stubGlobal('window', dom.window);
  vi.stubGlobal('document', dom.window.document);
  for (const name of ['Element', 'HTMLElement', 'HTMLButtonElement', 'HTMLInputElement', 'HTMLTextAreaElement', 'HTMLSelectElement', 'HTMLFormElement', 'HTMLOutputElement', 'HTMLDialogElement']) {
    vi.stubGlobal(name, dom.window[name as keyof Window]);
  }
  // jsdom has no native modal implementation. Real focus/Escape behavior is checked in the browser fixture.
  dom.window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
  dom.window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
  state = {
    installId: 'install_fixture', settings: { ...DEFAULT_EXTENSION_SETTINGS }, activeTabId: 1,
    pageStatus: { supported: true, videoId: 'dQw4w9WgXcQ', url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', mediaKind: 'video' },
    pageTitle: 'A song - YouTube', pageVideoDurationSeconds: 121,
    accountState: { status: 'authenticated', id: 'account-1', email: 'test@example.test', name: 'Learner', emailVerified: true,
      planName: 'Pro', tierName: 'Pro', tierSpeedLabel: 'Fast', monthlyMinuteLimit: 100, monthlyMinutesUsed: 0,
      monthlyMinutesPending: 0, monthlyMinutesRemaining: 100, resetAt: '2099-01-01T00:00:00Z', upgradeAvailable: false },
    subtitleState: { type: 'no-track' }, jobHistory: [],
  };
  mocks.send.mockImplementation(async (request) => {
    if (request.type === 'panel.listGenerations') return { jobs: [] };
    if (request.type === 'panel.generateSubtitles') return { ok: false, error: 'Fixture failure. Try again.' };
    return structuredClone(state);
  });
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
});

afterEach(() => {
  dom.window.close();
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

it('requires an explicit confirmation, with decline and Escape making no generation request', () => {
  button('generate').click();
  expect(dialog().open).toBe(true);
  expect(dialog().textContent).toContain("minutes for the entire video will still be used and won't be refunded");
  expect(dialog().textContent).toContain('3 plan minutes for the full video');
  expect(dialog().textContent).toContain('A song');
  expect(generationRequests()).toHaveLength(0);
  button('cancel-generation-confirmation').click();
  expect(dialog().open).toBe(false);
  button('generate').click();
  dialog().dispatchEvent(new dom.window.Event('cancel', { cancelable: true }));
  button('confirm-generation').click();
  expect(dialog().open).toBe(false);
  expect(generationRequests()).toHaveLength(0);
});

it('confirms each retry and Generate again, and ignores duplicate clicks while starting', async () => {
  button('generate').click();
  button('confirm-generation').click();
  button('confirm-generation').click();
  button('generate').click();
  expect(generationRequests()).toHaveLength(1);
  expect(generationRequests()[0]?.[0]).toMatchObject({ windowId: 7, confirmationContext: expect.any(String) });
  await vi.advanceTimersByTimeAsync(0);
  button('generate').click();
  expect(dialog().open).toBe(true);
  expect(generationRequests()).toHaveLength(1);
  button('confirm-generation').click();
  await vi.advanceTimersByTimeAsync(0);
  expect(generationRequests()).toHaveLength(2);

  const track = JSON.parse(readFileSync('../../packages/contracts/fixtures/valid-track-response.json', 'utf8')) as TrackResponse;
  state.subtitleState = { type: 'ready', track: { ...track, youtubeVideoId: 'dQw4w9WgXcQ', expiresAt: '2099-01-01T00:00:00Z' } };
  await refresh();
  button('toggle-setup').click();
  button('generate').click();
  expect(dialog().open).toBe(true);
  expect(generationRequests()).toHaveLength(2);
  button('confirm-generation').click();
  await vi.advanceTimersByTimeAsync(0);
  expect(generationRequests()).toHaveLength(3);
});

it.each(['account', 'video', 'tab', 'duration', 'source', 'target', 'model', 'romanization', 'translation', 'loading'] as const)(
  'only invalidates confirmation for identity or availability changes when %s changes', async (change) => {
    button('generate').click();
    if (change === 'account') state.accountState = { status: 'anonymous' };
    if (change === 'video') state.pageStatus = { supported: true, videoId: 'M7lc1UVf-VE', url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', mediaKind: 'video' };
    if (change === 'tab') state.activeTabId = 2;
    if (change === 'duration') state.pageVideoDurationSeconds = 240;
    if (change === 'source') state.settings.sourceLanguage = 'ara';
    if (change === 'target') state.settings.targetLanguage = 'fra';
    if (change === 'model') state.settings.aiProvider = 'cerebras';
    if (change === 'romanization') state.settings.showRomanization = !state.settings.showRomanization;
    if (change === 'translation') state.settings.showTranslation = !state.settings.showTranslation;
    if (change === 'loading') state.subtitleState = { type: 'loading', youtubeVideoId: 'dQw4w9WgXcQ', message: 'Working', stage: 'preparing', progressPercent: 5 };
    await refresh();
    const invalidated = ['account', 'video', 'tab', 'loading'].includes(change);
    expect(dialog().open).toBe(!invalidated);
    button('confirm-generation').click();
    expect(generationRequests()).toHaveLength(invalidated ? 0 : 1);
  },
);

it('keeps consent open for unrelated usage updates and closes immediately on tab activation', async () => {
  button('generate').click();
  if (state.accountState.status === 'authenticated') state.accountState.monthlyMinutesUsed = 2;
  await refresh();
  expect(dialog().open).toBe(true);
  mocks.activated.mock.calls[0]?.[0]({ tabId: 2, windowId: 7 });
  expect(dialog().open).toBe(false);
  button('confirm-generation').click();
  expect(generationRequests()).toHaveLength(0);
});

it('waits for settings persistence before allowing a confirmation', async () => {
  let resolveSettings!: (state: PanelState) => void;
  mocks.send.mockImplementation((request) => request.type === 'panel.updateSettings'
    ? new Promise<PanelState>((resolve) => { resolveSettings = resolve; }) : Promise.resolve(structuredClone(state)));
  const model = dom.window.document.querySelector<HTMLSelectElement>('select[name="aiProvider"]')!;
  model.value = 'cerebras';
  model.dispatchEvent(new dom.window.Event('change'));
  button('generate').click();
  expect(button('generate').disabled).toBe(true);
  expect(dialog().open).toBe(false);
  state.settings.aiProvider = 'cerebras';
  resolveSettings(structuredClone(state));
  await vi.advanceTimersByTimeAsync(0);
  button('generate').click();
  expect(dialog().textContent).toContain('Transcriber Spark');
  expect(dialog().open).toBe(true);
  expect(generationRequests()).toHaveLength(0);
});

it('does not silently resubmit a confirmed generation when its response is missing', async () => {
  mocks.send.mockImplementation(async (request) => request.type === 'panel.generateSubtitles' ? undefined : structuredClone(state));
  button('generate').click();
  button('confirm-generation').click();
  await vi.advanceTimersByTimeAsync(200);
  expect(generationRequests()).toHaveLength(1);
  button('generate').click();
  expect(dialog().open).toBe(true);
  expect(generationRequests()).toHaveLength(1);
});
