import { afterEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import type { PanelState } from '../utils/messages';
import { bindSavedGenerations } from '../entrypoints/sidepanel/saved-generations';
import { setInterfaceLocale, t } from '../utils/i18n';

const mocks = vi.hoisted(() => ({ sendMessage: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: { runtime: { sendMessage: mocks.sendMessage } } }));
afterEach(() => { vi.unstubAllGlobals(); setInterfaceLocale('en'); });

function state(jobId = 'luna'): PanelState {
  return { activeTabId: 1, backendUrl: 'http://127.0.0.1:8001/v1',
    pageStatus: { supported: true, videoId: 'dQw4w9WgXcQ' },
    subtitleState: { type: 'ready', track: { jobId, trackId: `track-${jobId}`, youtubeVideoId: 'dQw4w9WgXcQ' } },
  } as PanelState;
}
function jobs() {
  return ['luna', 'cerebras', 'spanish'].map(jobId => ({ jobId, youtubeVideoId: 'dQw4w9WgXcQ', status: 'completed',
    sourceLanguage: 'auto', targetLanguage: jobId === 'spanish' ? 'spa' : 'eng', aiProvider: jobId === 'luna' ? 'openai' : 'cerebras' }));
}
function setup(onSelect = vi.fn(async () => true)) {
  const dom = new JSDOM('<select></select><p></p><button></button>');
  vi.stubGlobal('document', dom.window.document);
  vi.stubGlobal('window', dom.window);
  const select = document.querySelector('select')!;
  const status = document.querySelector('p')!;
  const refresh = document.querySelector('button')!;
  const applyPanelState = vi.fn((state: PanelState) => view.render(state));
  const view = bindSavedGenerations(select, status, refresh, onSelect, () => 1, applyPanelState);
  return { dom, select, status, refresh, view, onSelect, applyPanelState };
}
async function flush() { for (let i = 0; i < 6; i++) await Promise.resolve(); }

it('applies the cleared panel state when refreshing an empty saved-generation list', async () => {
  mocks.sendMessage.mockResolvedValueOnce({ jobs: jobs() });
  const { dom, select, view, refresh, applyPanelState } = setup();
  view.render(state());
  await flush();
  const empty = { ...state(), subtitleState: { type: 'no-track' as const } };
  mocks.sendMessage.mockResolvedValue({ jobs: [], panelState: empty });
  refresh.click();
  await flush();
  expect(applyPanelState).toHaveBeenCalledWith(empty);
  expect(select.options).toHaveLength(0);
  dom.window.close();
});

it('keeps matching language pairs separate by job and selects the exact generation', async () => {
  mocks.sendMessage.mockResolvedValue({ jobs: jobs() });
  const { dom, select, view, onSelect } = setup();
  view.render(state());
  await flush();
  expect(Array.from(select.options, option => option.textContent)).toEqual([
    'Auto → English (OpenAI)', 'Auto → English (Cerebras)', 'Auto → Spanish (Cerebras)',
    'Delete selected generation…',
  ]);
  expect(select.value).toBe('luna');
  for (const locale of ['es', 'ja', 'en']) {
    setInterfaceLocale(locale);
    view.render(state());
    expect(select.value).toBe('luna');
    expect(select.options[3]!.textContent).toBe(t('Delete selected generation…'));
  }
  select.value = 'cerebras';
  select.dispatchEvent(new dom.window.Event('change'));
  expect(onSelect).toHaveBeenCalledWith({ type: 'panel.selectGeneration', jobId: 'cerebras', currentJobId: 'luna',
    trackId: 'track-luna', youtubeVideoId: 'dQw4w9WgXcQ', tabId: 1 });
  view.render(state('cerebras'));
  await flush();
  expect(select.value).toBe('cerebras');
  dom.window.close();
});

it('keeps the current selection on failure and allows retrying the list', async () => {
  mocks.sendMessage.mockRejectedValue(new Error('Offline'));
  const { dom, select, status, refresh, view } = setup();
  view.render(state());
  await flush();
  expect(select.value).toBe('luna');
  expect(status.textContent).toBe('Offline');
  mocks.sendMessage.mockResolvedValue({ jobs: jobs() });
  refresh.click();
  await flush();
  expect(select.options).toHaveLength(4);
  expect(select.disabled).toBe(false);
  view.render({ ...state(), lyricsCorrection: { status: 'running' } } as PanelState);
  expect(select.disabled).toBe(true);
  dom.window.close();
});

it('allows deleting the only generation and returns to an empty selector', async () => {
  mocks.sendMessage.mockResolvedValue({ jobs: jobs().slice(0, 1) });
  const { dom, select, view, onSelect } = setup();
  vi.spyOn(dom.window, 'confirm').mockReturnValue(true);
  view.render(state());
  await flush();
  expect(select.disabled).toBe(false);
  select.value = 'delete-current-generation';
  select.dispatchEvent(new dom.window.Event('change'));
  expect(onSelect).toHaveBeenCalledWith({ type: 'panel.deleteGeneration', jobId: 'luna', currentJobId: 'luna',
    trackId: 'track-luna', youtubeVideoId: 'dQw4w9WgXcQ', tabId: 1 });
  view.render({ ...state(), subtitleState: { type: 'no-track' } });
  await flush();
  expect(select.options).toHaveLength(0);
  expect(select.disabled).toBe(true);
  dom.window.close();
});

it('keeps the selected generation when deletion is cancelled or fails', async () => {
  mocks.sendMessage.mockResolvedValue({ jobs: jobs().slice(0, 1) });
  const { dom, select, view, onSelect, status } = setup();
  const confirm = vi.spyOn(dom.window, 'confirm').mockReturnValue(false);
  view.render(state());
  await flush();
  const remove = () => { select.value = 'delete-current-generation'; select.dispatchEvent(new dom.window.Event('change')); };
  remove();
  expect(onSelect).not.toHaveBeenCalled();
  expect(select.value).toBe('luna');
  confirm.mockReturnValue(true);
  onSelect.mockImplementation(async () => { view.showError('Offline'); return false; });
  remove();
  await flush();
  expect(select.value).toBe('luna');
  expect(select.disabled).toBe(false);
  expect(status.textContent).toBe('Offline');
  dom.window.close();
});

it('ignores a list response after the account or video changes', async () => {
  let resolve!: (response: unknown) => void;
  mocks.sendMessage.mockReturnValue(new Promise(done => { resolve = done; }));
  const { dom, select, view } = setup();
  view.render(state());
  view.render({ ...state(), backendUrl: 'http://127.0.0.1:8001/v1', subtitleState: { type: 'no-track' } });
  resolve({ jobs: jobs() });
  await flush();
  expect(select.options).toHaveLength(0);
  expect(select.disabled).toBe(true);
  dom.window.close();
});
