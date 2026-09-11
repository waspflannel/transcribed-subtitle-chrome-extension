import { afterEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import type { PanelState } from '../utils/messages';
import { bindSavedGenerations } from '../entrypoints/sidepanel/saved-generations';

const mocks = vi.hoisted(() => ({ sendMessage: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: { runtime: { sendMessage: mocks.sendMessage } } }));
afterEach(() => { vi.unstubAllGlobals(); });

function state(jobId = 'luna'): PanelState {
  return { activeTabId: 1, accountState: { status: 'authenticated', id: 'account' },
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
  const select = document.querySelector('select')!;
  const status = document.querySelector('p')!;
  const refresh = document.querySelector('button')!;
  const view = bindSavedGenerations(select, status, refresh, onSelect, () => 1);
  return { dom, select, status, refresh, view, onSelect };
}
async function flush() { for (let i = 0; i < 6; i++) await Promise.resolve(); }

it('keeps matching language pairs separate by job and selects the exact generation', async () => {
  mocks.sendMessage.mockResolvedValue({ jobs: jobs() });
  const { dom, select, view, onSelect } = setup();
  view.render(state());
  await flush();
  expect(Array.from(select.options, option => option.textContent)).toEqual([
    'Auto → English (Luna)', 'Auto → English (Cerebras)', 'Auto → Spanish (Cerebras)',
  ]);
  expect(select.value).toBe('luna');
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
  expect(select.options).toHaveLength(3);
  expect(select.disabled).toBe(false);
  view.render({ ...state(), lyricsCorrection: { status: 'running' } } as PanelState);
  expect(select.disabled).toBe(true);
  dom.window.close();
});

it('ignores a list response after the account or video changes', async () => {
  let resolve!: (response: unknown) => void;
  mocks.sendMessage.mockReturnValue(new Promise(done => { resolve = done; }));
  const { dom, select, view } = setup();
  view.render(state());
  view.render({ ...state(), accountState: { status: 'anonymous' }, subtitleState: { type: 'no-track' } });
  resolve({ jobs: jobs() });
  await flush();
  expect(select.options).toHaveLength(0);
  expect(select.disabled).toBe(true);
  dom.window.close();
});
