import { afterEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import type { PanelState } from '../utils/messages';
import { bindSavedGenerations } from '../entrypoints/sidepanel/saved-generations';
import { setInterfaceLocale, t } from '../utils/i18n';

const mocks = vi.hoisted(() => ({ sendMessage: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: { runtime: { sendMessage: mocks.sendMessage } } }));
afterEach(() => { vi.unstubAllGlobals(); vi.useRealTimers(); setInterfaceLocale('en'); });

function state(jobId = 'luna'): PanelState {
  return { activeTabId: 1, backendUrl: 'http://127.0.0.1:8001/v1', jobHistory: [],
    pageStatus: { supported: true, videoId: 'dQw4w9WgXcQ' },
    subtitleState: { type: 'ready', track: { jobId, trackId: `track-${jobId}`, youtubeVideoId: 'dQw4w9WgXcQ', sourceLanguage: 'auto', targetLanguage: 'eng' } },
  } as unknown as PanelState;
}
function jobs() {
  return ['luna', 'cerebras', 'spanish'].map(jobId => ({ jobId, youtubeVideoId: 'dQw4w9WgXcQ', status: 'completed',
    sourceLanguage: 'auto', targetLanguage: jobId === 'spanish' ? 'spa' : 'eng', aiProvider: jobId === 'luna' ? 'openai' : 'cerebras', aiModel: jobId === 'luna' ? 'gpt-6-luna' : 'gpt-oss-120b' }));
}
function setup(onSelect = vi.fn(async () => true), hidden = new Set<string>()) {
  const dom = new JSDOM('<button id="b"><b id="pair"></b><span id="model"></span></button><div id="menu"></div><p id="status"></p>');
  vi.stubGlobal('document', dom.window.document);
  vi.stubGlobal('window', dom.window);
  const $ = (id: string) => dom.window.document.getElementById(id)!;
  const onDelete = vi.fn();
  const applyPanelState = vi.fn((next: PanelState) => view.render(next));
  const view = bindSavedGenerations({
    button: $('b') as HTMLButtonElement, pairLabel: $('pair'), modelLabel: $('model'), menu: $('menu'), status: $('status'),
    selectGeneration: onSelect, windowId: () => 1, applyPanelState, onDelete, hiddenJobIds: () => hidden,
  });
  const rows = () => [...$('menu').querySelectorAll('[data-gen-row]')];
  return { dom, view, onSelect, onDelete, applyPanelState, rows, button: $('b') as HTMLButtonElement, menu: $('menu'), status: $('status'), pair: $('pair'), model: $('model') };
}
async function flush() { for (let i = 0; i < 6; i++) await Promise.resolve(); }

it('applies the cleared panel state when refreshing an empty saved-generation list', async () => {
  mocks.sendMessage.mockResolvedValueOnce({ jobs: jobs() });
  const { dom, view, menu, rows, applyPanelState } = setup();
  view.render(state());
  await flush();
  expect(rows()).toHaveLength(3);
  const empty = { ...state(), subtitleState: { type: 'no-track' as const } };
  mocks.sendMessage.mockResolvedValue({ jobs: [], panelState: empty });
  menu.querySelector<HTMLButtonElement>('[data-gen-refresh]')!.click();
  await flush();
  expect(applyPanelState).toHaveBeenCalledWith(empty);
  expect(rows()).toHaveLength(0);
  dom.window.close();
});

it('keeps matching language pairs separate by job, labels the current track and selects the exact generation', async () => {
  mocks.sendMessage.mockResolvedValue({ jobs: jobs() });
  const { dom, view, onSelect, rows, pair, model, menu } = setup();
  view.render(state());
  await flush();
  expect(rows().map(row => row.querySelector('.mi-text')!.textContent)).toEqual([
    'Auto → EnglishOpenAI API · gpt-6-luna', 'Auto → EnglishCerebras API · gpt-oss-120b', 'Auto → SpanishCerebras API · gpt-oss-120b',
  ]);
  expect(pair.textContent).toBe('Auto → English');
  expect(model.textContent).toBe('OpenAI API · gpt-6-luna');
  expect(menu.querySelector('[data-gen-pick="luna"]')!.getAttribute('aria-checked')).toBe('true');
  setInterfaceLocale('es');
  view.render(state());
  expect(menu.querySelector('.micro')!.textContent).toBe(t('Saved for this video'));
  setInterfaceLocale('en');
  menu.querySelector<HTMLButtonElement>('[data-gen-pick="cerebras"]')!.click();
  expect(onSelect).toHaveBeenCalledWith({ type: 'panel.selectGeneration', jobId: 'cerebras', currentJobId: 'luna',
    trackId: 'track-luna', youtubeVideoId: 'dQw4w9WgXcQ', tabId: 1 });
  view.render(state('cerebras'));
  await flush();
  expect(model.textContent).toBe('Cerebras API · gpt-oss-120b');
  dom.window.close();
});

it('keeps the current track on failure, allows retrying, and locks while lyrics are replaced', async () => {
  mocks.sendMessage.mockRejectedValue(new Error('Offline'));
  const { dom, view, status, menu, rows, button } = setup();
  view.render(state());
  await flush();
  expect(status.textContent).toBe('Offline');
  expect(menu.textContent).toContain('Offline');
  mocks.sendMessage.mockResolvedValue({ jobs: jobs() });
  menu.querySelector<HTMLButtonElement>('[data-gen-refresh]')!.click();
  await flush();
  expect(rows()).toHaveLength(3);
  expect(button.disabled).toBe(false);
  view.render({ ...state(), lyricsCorrection: { status: 'running' } } as PanelState);
  expect(button.disabled).toBe(true);
  dom.window.close();
});

it('deletes with one click after the row collapses, and hides pending deletes', async () => {
  vi.useFakeTimers();
  mocks.sendMessage.mockResolvedValue({ jobs: jobs() });
  const hidden = new Set<string>();
  const { dom, view, onDelete, onSelect, rows, menu } = setup(undefined, hidden);
  view.render(state());
  await vi.advanceTimersByTimeAsync(0);
  menu.querySelector<HTMLButtonElement>('[data-gen-delete="cerebras"]')!.click();
  expect(rows()[1]!.classList.contains('leaving')).toBe(true);
  expect(onDelete).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(240);
  expect(onDelete).toHaveBeenCalledWith(expect.objectContaining({ jobId: 'cerebras' }));
  expect(onSelect).not.toHaveBeenCalled();
  hidden.add('cerebras');
  view.render(state());
  expect(rows().map(row => row.getAttribute('data-gen-row'))).toEqual(['luna', 'spanish']);
  dom.window.close();
});

it('ignores a list response after the account or video changes', async () => {
  let resolve!: (response: unknown) => void;
  mocks.sendMessage.mockReturnValue(new Promise(done => { resolve = done; }));
  const { dom, view, rows, button } = setup();
  view.render(state());
  view.render({ ...state(), backendUrl: 'http://127.0.0.1:8001/v1', subtitleState: { type: 'no-track' } });
  resolve({ jobs: jobs() });
  await flush();
  expect(rows()).toHaveLength(0);
  expect(button.disabled).toBe(true);
  dom.window.close();
});
