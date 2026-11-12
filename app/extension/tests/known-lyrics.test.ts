import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { bindKnownLyrics } from '../entrypoints/sidepanel/known-lyrics';
import { setupTabs } from '../entrypoints/sidepanel/tabs';

const mocks = vi.hoisted(() => ({ get: vi.fn(), getKeys: vi.fn(), set: vi.fn(), remove: vi.fn(), copy: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: { storage: { local: {
  get: mocks.get, getKeys: mocks.getKeys, set: mocks.set, remove: mocks.remove,
} } } }));
let stored: Record<string, unknown>;
const windows: JSDOM[] = [];
beforeEach(() => {
  stored = {};
  vi.resetAllMocks();
  mocks.getKeys.mockImplementation(async () => Object.keys(stored));
  mocks.get.mockImplementation(async (keys: string[]) => Object.fromEntries(Object.entries(stored).filter(([key]) => keys.includes(key))));
  mocks.set.mockImplementation(async (value) => { Object.assign(stored, value); });
  mocks.remove.mockImplementation(async (key) => { delete stored[key]; });
  mocks.copy.mockResolvedValue(undefined);
  vi.stubGlobal('navigator', { clipboard: { writeText: mocks.copy } });
});
afterEach(() => { windows.forEach(dom => dom.window.close()); windows.length = 0; vi.useRealTimers(); vi.unstubAllGlobals(); });
async function flush() { for (let i = 0; i < 12; i++) await Promise.resolve(); }
function open() {
  const dom = new JSDOM(markup);
  windows.push(dom);
  const root = dom.window.document;
  setupTabs([...root.querySelectorAll<HTMLButtonElement>('[data-tab]')], [...root.querySelectorAll<HTMLElement>('[data-panel]')]);
  bindKnownLyrics(root);
  root.querySelector<HTMLElement>('[data-watch-ready]')!.hidden = false;
  const form = root.querySelector<HTMLFormElement>('[data-known-lyrics-form]')!;
  const title = form.elements.namedItem('title') as HTMLInputElement;
  const lyrics = form.elements.namedItem('lyrics') as HTMLTextAreaElement;
  root.querySelector<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')!.click();
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
  expect(reopened.root.querySelector('.known-lyrics-entry summary')!.textContent).toBe('<img src=x onerror=alert(1)>');
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
  view.root.querySelector<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')!.click();
  view.root.querySelector<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')!.click(); await flush();
  mocks.copy.mockRejectedValueOnce(new Error('Denied'));
  view.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button')!.click(); await flush();
  expect(view.root.querySelector('[data-known-lyrics-status]')!.textContent).toContain('copy it manually');
  mocks.remove.mockRejectedValueOnce(new Error('Unavailable'));
  view.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button:last-child')!.click(); await flush();
  expect(view.root.querySelectorAll('.known-lyrics-entry')).toHaveLength(1);
  expect(Object.keys(stored)).toHaveLength(1);
});

it('opens as a sheet from the tools menu and from lyric correction without losing drafts', async () => {
  const view = open(); await flush();
  expect([...view.root.querySelectorAll('[role="tab"]')].map(tab => tab.textContent)).toEqual(['Watch', 'History', 'Settings']);
  const toggles = [...view.root.querySelectorAll<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')];
  expect(toggles).toHaveLength(2);
  expect(toggles[0]!.closest('[data-lyrics-correction-form]')).not.toBeNull();
  expect(toggles[1]!.closest('[data-tools-menu]')).not.toBeNull();
  const sheet = view.root.querySelector<HTMLElement>('#panel-known-lyrics')!;
  expect(sheet.classList.contains('sheet')).toBe(true);
  expect(sheet.hidden).toBe(false);
  view.lyrics.value = 'Unfinished draft';
  toggles[1]!.click(); await flush();
  expect(sheet.hidden).toBe(false);
  expect(view.lyrics.value).toBe('Unfinished draft');
});

it('offers Use in correction and an undo for deletes when the panel provides them', async () => {
  stored = { 'knownLyrics:one': { title: 'Song', lyrics: 'Words' } };
  const dom = new JSDOM(markup);
  windows.push(dom);
  const root = dom.window.document;
  const onUse = vi.fn();
  let restore: (() => Promise<void>) | undefined;
  bindKnownLyrics(root, { onUse, onDeleted: (_title, undo) => { restore = undo; } });
  root.querySelector<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')!.click(); await flush();
  const buttons = [...root.querySelectorAll<HTMLButtonElement>('.known-lyrics-entry button')];
  expect(buttons.map(button => button.textContent)).toEqual(['Use in correction', 'Copy', 'Delete']);
  buttons[0]!.click();
  expect(onUse).toHaveBeenCalledWith('Words');
  buttons[2]!.click(); await flush();
  expect(stored).toEqual({});
  await restore!();
  expect(Object.values(stored)).toEqual([{ title: 'Song', lyrics: 'Words' }]);
});

it('starts with compact entries and a collapsed form, then collapses the form after saving', async () => {
  stored = { 'knownLyrics:one': { title: 'Song', lyrics: 'Words' } };
  const view = open(); await flush();
  const create = view.root.querySelector<HTMLDetailsElement>('[data-known-lyrics-create]')!;
  const entry = view.root.querySelector<HTMLDetailsElement>('.known-lyrics-entry details')!;
  expect(create.open).toBe(false);
  expect(entry.open).toBe(false);
  entry.querySelector('summary')!.click();
  expect(entry.open).toBe(true);
  create.querySelector('summary')!.click();
  view.title.value = 'New song'; view.lyrics.value = 'New words';
  await view.submit();
  expect(create.open).toBe(false);
  expect(view.root.activeElement).toBe(create.querySelector('summary'));
  expect(view.root.querySelector<HTMLDetailsElement>('.known-lyrics-entry details')!.open).toBe(true);
});

it('expires copy notices, restarts their timer, and never lets an old timer clear a new error', async () => {
  vi.useFakeTimers();
  stored = { 'knownLyrics:one': { title: 'Song', lyrics: 'Words' } };
  const view = open(); await flush();
  const copy = view.root.querySelector<HTMLButtonElement>('.known-lyrics-entry button')!;
  const status = view.root.querySelector('[data-known-lyrics-status]')!;
  copy.click(); await flush();
  expect(status.textContent).toBe('Lyrics copied.');
  await vi.advanceTimersByTimeAsync(2000);
  copy.click(); await flush();
  await vi.advanceTimersByTimeAsync(1000);
  expect(status.textContent).toBe('Lyrics copied.');
  await vi.advanceTimersByTimeAsync(1500);
  expect(status.textContent).toBe('');
  expect(status.getAttribute('data-i18n')).toBe('');
  copy.click(); await flush();
  mocks.copy.mockRejectedValueOnce(new Error('Denied'));
  copy.click(); await flush();
  await vi.advanceTimersByTimeAsync(3000);
  expect(status.textContent).toContain('copy it manually');
  expect(view.root.querySelector<HTMLDetailsElement>('.known-lyrics-entry details')!.open).toBe(true);
});
