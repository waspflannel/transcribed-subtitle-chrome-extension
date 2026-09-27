import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { bindKnownLyrics } from '../entrypoints/sidepanel/known-lyrics';
import { setupTabs } from '../entrypoints/sidepanel/tabs';

const mocks = vi.hoisted(() => ({ get: vi.fn(), set: vi.fn(), remove: vi.fn(), copy: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: { storage: { local: {
  get: mocks.get, set: mocks.set, remove: mocks.remove,
} } } }));
let stored: Record<string, unknown>;
const windows: JSDOM[] = [];
beforeEach(() => {
  stored = {};
  vi.resetAllMocks();
  mocks.get.mockImplementation(async () => ({ ...stored }));
  mocks.set.mockImplementation(async (value) => { Object.assign(stored, value); });
  mocks.remove.mockImplementation(async (key) => { delete stored[key]; });
  mocks.copy.mockResolvedValue(undefined);
  vi.stubGlobal('navigator', { clipboard: { writeText: mocks.copy } });
});
afterEach(() => { windows.forEach(dom => dom.window.close()); windows.length = 0; vi.unstubAllGlobals(); });
async function flush() { for (let i = 0; i < 12; i++) await Promise.resolve(); }
function open() {
  const dom = new JSDOM(markup);
  windows.push(dom);
  const root = dom.window.document;
  setupTabs([...root.querySelectorAll<HTMLButtonElement>('[data-tab]')], [...root.querySelectorAll<HTMLElement>('[data-panel]')]);
  bindKnownLyrics(root);
  const form = root.querySelector<HTMLFormElement>('[data-known-lyrics-form]')!;
  const title = form.elements.namedItem('title') as HTMLInputElement;
  const lyrics = form.elements.namedItem('lyrics') as HTMLTextAreaElement;
  root.querySelector<HTMLButtonElement>('[data-tab="known-lyrics"]')!.click();
  return { root, title, lyrics, submit: async () => {
    form.dispatchEvent(new dom.window.Event('submit', { cancelable: true })); await flush();
  } };
}

it('saves, reopens, safely previews, copies exact text, and deletes only the chosen entry', async () => {
  const first = open();
  await flush();
  expect(first.root.querySelector<HTMLElement>('#panel-known-lyrics')!.hidden).toBe(false);
  first.title.value = '<img src=x onerror=alert(1)>';
  first.lyrics.value = '  First line\n第二行\n';
  await first.submit();
  first.title.value = 'Another song'; first.lyrics.value = 'Keep this'; await first.submit();
  const reopened = open(); await flush();
  expect(reopened.root.querySelectorAll('.known-lyrics-entry')).toHaveLength(2);
  expect(reopened.root.querySelector('.known-lyrics-entry h3')!.textContent).toBe('<img src=x onerror=alert(1)>');
  expect(reopened.root.querySelector('.known-lyrics-entry img')).toBeNull();
  reopened.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button')!.click(); await flush();
  expect(mocks.copy).toHaveBeenCalledWith('  First line\n第二行\n');
  reopened.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button:last-child')!.click(); await flush();
  expect(Object.values(stored)).toEqual([{ title: 'Another song', lyrics: 'Keep this' }]);
});

it('rejects blank and oversized entries, ignores malformed storage, and retains drafts on save failure', async () => {
  stored = { unrelated: { title: 'Other', lyrics: 'Other' }, 'knownLyrics:bad': { title: 42, lyrics: 'Bad' } };
  const view = open(); await flush();
  expect(view.root.querySelectorAll('.known-lyrics-entry')).toHaveLength(0);
  view.title.value = 'Song'; view.lyrics.value = '   '; await view.submit();
  view.lyrics.value = 'x'.repeat(25001); await view.submit();
  expect(mocks.set).not.toHaveBeenCalled();
  view.lyrics.value = 'My draft';
  mocks.set.mockRejectedValueOnce(new Error('Quota exceeded'));
  await view.submit();
  expect(view.title.value).toBe('Song'); expect(view.lyrics.value).toBe('My draft');
  expect(view.root.querySelector('[data-known-lyrics-status]')!.textContent).toContain('Your draft is still here');
  await view.submit();
  expect(view.lyrics.value).toBe('');
});

it('keeps saved lyrics on copy/delete failures and allows retry after load failure', async () => {
  stored = { 'knownLyrics:one': { title: 'Song', lyrics: 'Words' } };
  mocks.get.mockRejectedValueOnce(new Error('Unavailable'));
  const view = open(); await flush();
  expect(view.root.querySelector('[data-known-lyrics-status]')!.textContent).toContain('Could not load');
  view.root.querySelector<HTMLButtonElement>('[data-tab="known-lyrics"]')!.click(); await flush();
  mocks.copy.mockRejectedValueOnce(new Error('Denied'));
  view.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button')!.click(); await flush();
  expect(view.root.querySelector('[data-known-lyrics-status]')!.textContent).toContain('copy it manually');
  mocks.remove.mockRejectedValueOnce(new Error('Unavailable'));
  view.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button:last-child')!.click(); await flush();
  expect(view.root.querySelectorAll('.known-lyrics-entry')).toHaveLength(1);
  expect(Object.keys(stored)).toHaveLength(1);
});
