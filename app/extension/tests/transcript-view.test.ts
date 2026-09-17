import { afterEach, assert, describe, expect, it, vi } from 'vitest';
import { setInterfaceLocale, t } from '../utils/i18n';
import { JSDOM } from 'jsdom';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { SubtitleCue } from '../utils/contracts';
import { bindTranscriptView } from '../entrypoints/sidepanel/transcript-view';
afterEach(() => setInterfaceLocale('en'));

const cues: [SubtitleCue, SubtitleCue] = [
  { cueId: 'c1', index: 0, startMs: 500, endMs: 2100, sourceText: 'hola', translatedText: 'hello', romanization: 'o-la', tokens: [{ index: 0, text: 'hola', normalizedText: 'hola' }] },
  { cueId: 'c2', index: 1, startMs: 2600, endMs: 4200, sourceText: 'adios', translatedText: 'goodbye', romanization: 'a-dios', tokens: [{ index: 0, text: 'adios', normalizedText: 'adios' }] },
];

function setupDom() {
  const dom = new JSDOM('<input id="search" /><ol id="list"></ol><p id="status"></p>', { pretendToBeVisual: true });
  const document = dom.window.document;
  const scrollSpy = { calls: 0 };
  // jsdom does not implement scrollIntoView — stub it so setActiveCue does not throw,
  // and so tests can assert the data-render path never scrolls.
  (dom.window.Element.prototype as unknown as { scrollIntoView: () => void }).scrollIntoView = function () {
    scrollSpy.calls += 1;
  };
  const view = bindTranscriptView({
    transcriptSearch: document.getElementById('search') as HTMLInputElement,
    transcriptList: document.getElementById('list') as HTMLElement,
    transcriptStatus: document.getElementById('status') as HTMLElement,
  });
  return {
    view,
    list: document.getElementById('list') as HTMLElement,
    status: document.getElementById('status') as HTMLElement,
    search: document.getElementById('search') as HTMLInputElement,
    scrollSpy,
  };
}

describe('bindTranscriptView rebuild guard', () => {
  it('does not rebuild the DOM when setData repeats identical render-affecting inputs', () => {
    const { view, list } = setupDom();
    const settings = { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true };

    view.setData('vid', cues, settings);
    const rowBefore = list.querySelector('[data-cue-id="c1"]');
    expect(rowBefore).not.toBeNull();

    view.setData('vid', cues, settings);
    const rowAfter = list.querySelector('[data-cue-id="c1"]');
    expect(rowAfter).toBe(rowBefore);
  });

  it('rebuilds when a render-affecting setting changes', () => {
    const { view, list } = setupDom();
    view.setData('vid', cues, { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: false });
    expect(list.querySelector('.cg')).toBeNull();

    view.setData('vid', cues, { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true });
    expect(list.querySelector('.cg')).not.toBeNull();
  });

  it('rebuilds when the search query changes', () => {
    const { view, list, search } = setupDom();
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    expect(list.querySelectorAll('.cue').length).toBe(2);

    search.value = 'hola';
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    // The input listener rebuilds on `input` events, but setData also reads the query.
    expect(list.querySelectorAll('.cue').length).toBe(1);
  });

  it('never auto-scrolls when data or the active cue changes', () => {
    const { view, scrollSpy } = setupDom();
    const settings = { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true };

    view.setData('vid', cues, settings);
    view.setActiveCue('c1');
    expect(scrollSpy.calls).toBe(0);

    scrollSpy.calls = 0;
    view.setData('vid', cues, settings);
    view.setData('vid', cues, settings);
    expect(scrollSpy.calls).toBe(0);
  });
});

describe('bindTranscriptView setActiveCue', () => {
  it('relays a jump through the panel owner callback after updating the highlight', () => {
    const dom = new JSDOM('<input id="search" /><ol id="list"></ol><p id="status"></p>');
    const document = dom.window.document;
    const onSeekToCue = vi.fn();
    const view = bindTranscriptView({
      transcriptSearch: document.getElementById('search') as HTMLInputElement,
      transcriptList: document.getElementById('list') as HTMLElement,
      transcriptStatus: document.getElementById('status') as HTMLElement,
      onSeekToCue,
    });
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);

    document.querySelector<HTMLButtonElement>('[data-cue-id="c2"][data-transcript-action="jump"]')!.click();

    expect(onSeekToCue).toHaveBeenCalledWith('c2', 'jump');
    expect(document.querySelector('[data-cue-id="c2"]')?.classList.contains('on')).toBe(true);
  });

  it('toggles the .on class on exactly the right row without rebuilding', () => {
    const { view, list } = setupDom();
    view.setData('vid', cues, { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true });

    const row1 = list.querySelector('[data-cue-id="c1"]');
    const row2 = list.querySelector('[data-cue-id="c2"]');
    expect(row1).not.toBeNull();
    expect(row2).not.toBeNull();

    view.setActiveCue('c2');

    const row1After = list.querySelector('[data-cue-id="c1"]');
    const row2After = list.querySelector('[data-cue-id="c2"]');
    expect(row1After).toBe(row1);
    expect(row2After).toBe(row2);

    expect(row2!.classList.contains('on')).toBe(true);
    expect(row1!.classList.contains('on')).toBe(false);
    expect(row2!.getAttribute('aria-current')).toBe('true');
    expect(row1!.getAttribute('aria-current')).toBe('false');
    expect(list.querySelectorAll('.cue.on').length).toBe(1);
  });

  it('moves .on to the new row on subsequent cue changes without rebuilding', () => {
    const { view, list } = setupDom();
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);

    view.setActiveCue('c1');
    const row1 = list.querySelector('[data-cue-id="c1"]')!;
    const row2 = list.querySelector('[data-cue-id="c2"]')!;
    expect(row1.classList.contains('on')).toBe(true);

    view.setActiveCue('c2');

    expect(list.querySelector('[data-cue-id="c1"]')).toBe(row1);
    expect(list.querySelector('[data-cue-id="c2"]')).toBe(row2);
    expect(row2.classList.contains('on')).toBe(true);
    expect(row1.classList.contains('on')).toBe(false);
    expect(list.querySelectorAll('.cue.on').length).toBe(1);
  });

  it('removes .on and does not scroll when the active cue becomes null', () => {
    const { view, list, scrollSpy } = setupDom();
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    view.setActiveCue('c1');
    const row1 = list.querySelector('[data-cue-id="c1"]')!;
    expect(row1.classList.contains('on')).toBe(true);

    scrollSpy.calls = 0;
    view.setActiveCue(null);

    expect(row1.classList.contains('on')).toBe(false);
    expect(row1.getAttribute('aria-current')).toBe('false');
    expect(list.querySelectorAll('.cue.on').length).toBe(0);
    expect(scrollSpy.calls).toBe(0);
  });

  it('no-ops when setActiveCue is called with the same cue id', () => {
    const { view, list, scrollSpy } = setupDom();
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    view.setActiveCue('c1');
    const row1 = list.querySelector('[data-cue-id="c1"]')!;
    expect(row1.classList.contains('on')).toBe(true);

    scrollSpy.calls = 0;
    view.setActiveCue('c1');
    expect(row1.classList.contains('on')).toBe(true);
    expect(scrollSpy.calls).toBe(0);
  });

  it('does not scroll when the active cue is not in the filtered view', () => {
    const { view, list, search, scrollSpy } = setupDom();
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    search.value = 'hola';
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    // Only c1 is visible; activating c2 should find no row and not scroll.
    scrollSpy.calls = 0;
    view.setActiveCue('c2');
    expect(scrollSpy.calls).toBe(0);
    expect(list.querySelectorAll('.cue.on').length).toBe(0);
  });
});

describe('bindTranscriptView quick fix editor', () => {
  function setupQuickFix() {
    const dom = new JSDOM('<input id="search" /><ol id="list"></ol><p id="status"></p>');
    const document = dom.window.document;
    (dom.window.Element.prototype as unknown as { scrollIntoView: () => void }).scrollIntoView = () => {};
    const onQuickFixSelect = vi.fn();
    const onQuickFixSave = vi.fn();
    const onQuickFixCancel = vi.fn();
    const view = bindTranscriptView({
      transcriptSearch: document.getElementById('search') as HTMLInputElement,
      transcriptList: document.getElementById('list') as HTMLElement,
      transcriptStatus: document.getElementById('status') as HTMLElement,
      onQuickFixSelect,
      onQuickFixSave,
      onQuickFixCancel,
    });
    view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
    view.setQuickFixMode(true);
    return {
      view,
      document,
      list: document.getElementById('list') as HTMLElement,
      onQuickFixSelect,
      onQuickFixSave,
      onQuickFixCancel,
    };
  }

  function openEditor(view: ReturnType<typeof setupQuickFix>['view'], list: HTMLElement) {
    view.setQuickFixEditing({ cueId: 'c1', tokenIndex: 0, text: 'hola' });
    return {
      input: list.querySelector<HTMLInputElement>('[data-quick-fix-input]')!,
      hint: list.querySelector<HTMLElement>('[data-quick-fix-hint]')!,
      save: list.querySelector<HTMLButtonElement>('[data-transcript-action="quick-fix-save"]')!,
      cancel: list.querySelector<HTMLButtonElement>('[data-transcript-action="quick-fix-cancel"]')!,
    };
  }

  function type(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new input.ownerDocument.defaultView!.Event('input', { bubbles: true }));
  }

  it('opens word selection for only the chosen line and keeps the form beneath its words', () => {
    const { view, list } = setupQuickFix();
    expect(list.querySelector('[data-transcript-action="quick-fix-token"]')).toBeNull();
    list.querySelector<HTMLButtonElement>('[data-cue-id="c1"][data-transcript-action="quick-fix-line"]')!.click();
    expect(list.querySelector('[data-cue-id="c1"][data-token-index]')).not.toBeNull();
    expect(list.querySelector('[data-cue-id="c2"][data-token-index]')).toBeNull();
    openEditor(view, list);
    const editor = list.querySelector('[data-quick-fix-editor]')!;
    expect(editor.closest('.toks')).toBeNull();
    expect(list.querySelector('[data-cue-id="c1"][data-token-index="0"]')?.textContent).toContain('hola');
  });

  it('reports token clicks with cue id and token index', () => {
    const { list, onQuickFixSelect } = setupQuickFix();
    list.querySelector<HTMLButtonElement>('[data-cue-id="c2"][data-transcript-action="quick-fix-line"]')!.click();
    list.querySelector<HTMLButtonElement>('[data-cue-id="c2"][data-token-index="0"]')!.click();
    expect(onQuickFixSelect).toHaveBeenCalledWith('c2', 0);
  });

  it('opens a prefilled focused editor and disables save while unchanged', () => {
    const { view, list, document } = setupQuickFix();
    const { input, hint, save } = openEditor(view, list);

    expect(input.value).toBe('hola');
    expect(document.activeElement).toBe(input);
    expect(hint.textContent).toBe('4 / 84');
    expect(save.disabled).toBe(true);
  });

  it('does not scroll the transcript when the editor receives focus', () => {
    const { view, list } = setupQuickFix();
    const focusSpy = vi.spyOn(list.ownerDocument.defaultView!.HTMLElement.prototype, 'focus');
    const scrollSpy = vi.spyOn(list.ownerDocument.defaultView!.Element.prototype, 'scrollIntoView');

    openEditor(view, list);

    expect(focusSpy).toHaveBeenCalledWith({ preventScroll: true });
    expect(scrollSpy).not.toHaveBeenCalled();
    focusSpy.mockRestore();
    scrollSpy.mockRestore();
  });

  it('validates the draft live and saves through Enter', () => {
    const { view, list, onQuickFixSave } = setupQuickFix();
    const { input, hint, save } = openEditor(view, list);

    type(input, 'hola!');
    expect(hint.textContent).toBe('5 / 84');
    expect(save.disabled).toBe(false);

    type(input, 'x'.repeat(85));
    expect(hint.textContent).toBe('85 / 84');
    expect(hint.classList.contains('error')).toBe(true);
    expect(save.disabled).toBe(true);

    type(input, 'hola!');
    input.dispatchEvent(new input.ownerDocument.defaultView!.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(onQuickFixSave).toHaveBeenCalledWith('c1', 0, 'hola!');
  });

  it('cancels through Escape and the cancel button', () => {
    const { view, list, onQuickFixCancel } = setupQuickFix();
    const { input, cancel } = openEditor(view, list);

    input.dispatchEvent(new input.ownerDocument.defaultView!.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(onQuickFixCancel).toHaveBeenCalledTimes(1);

    cancel.click();
    expect(onQuickFixCancel).toHaveBeenCalledTimes(2);
  });

  it('disables the editor while busy and shows errors in the hint', () => {
    const { view, list } = setupQuickFix();
    const { input, hint, save } = openEditor(view, list);

    type(input, 'hola!');
    view.setQuickFixBusy(true);
    expect(input.disabled).toBe(true);
    expect(save.disabled).toBe(true);
    expect(save.textContent).toBe('Saving…');
    expect(hint.textContent).toBe('Refreshing translation and word data…');

    view.setQuickFixBusy(false);
    view.setQuickFixError('Could not save. Try again.');
    expect(hint.textContent).toBe('Could not save. Try again.');
    expect(hint.classList.contains('error')).toBe(true);
    expect(save.disabled).toBe(false);

    /* Typing clears the error and restores the live count. */
    type(input, 'hola!!');
    expect(hint.textContent).toBe('6 / 84');
    expect(hint.classList.contains('error')).toBe(false);
  });

  it('keeps the selected word while the refresh is pending', () => {
    const { view, list, onQuickFixSelect, onQuickFixCancel } = setupQuickFix();
    const { input } = openEditor(view, list);
    view.setQuickFixBusy(true);
    list.querySelector<HTMLButtonElement>('[data-cue-id="c2"][data-transcript-action="quick-fix-line"]')!.click();
    expect(list.querySelector('[data-cue-id="c2"][data-token-index]')).toBeNull();
    input.dispatchEvent(new input.ownerDocument.defaultView!.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(onQuickFixSelect).not.toHaveBeenCalled();
    expect(onQuickFixCancel).not.toHaveBeenCalled();
    expect(input.value).toBe('hola');
  });

  it('keeps the draft when the transcript rebuilds for an unrelated reason', () => {
    const { view, list } = setupQuickFix();
    const { input } = openEditor(view, list);
    type(input, 'hola!');

    view.setActiveCue('c2');
    view.setData('vid', cues, { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true });

    expect(list.querySelector<HTMLInputElement>('[data-quick-fix-input]')?.value).toBe('hola!');
  });

  it('preserves the draft and active cue when the interface language changes', () => {
    const { view, list } = setupQuickFix();
    const { input } = openEditor(view, list);
    type(input, 'hola!');
    for (const locale of ['es', 'ja', 'zh-CN', 'en'] as const) {
      setInterfaceLocale(locale);
      view.setData('vid', cues, { ...DEFAULT_EXTENSION_SETTINGS, interfaceLocale: locale });
      view.setActiveCue('c2');
      expect(list.querySelector<HTMLInputElement>('[data-quick-fix-input]')?.value).toBe('hola!');
      expect(list.querySelector('.cue.on')?.getAttribute('data-cue-id')).toBe('c2');
      expect(list.querySelector('[data-transcript-action="quick-fix-save"]')?.textContent).toBe(t('Save correction'));
    }
  });

  it('keeps typing focus, selection, draft and scroll when another cue receives word metadata', async () => {
    const { view, list, document, onQuickFixSave } = setupQuickFix();
    const { input } = openEditor(view, list);
    type(input, 'hola! a longer correction');
    input.setSelectionRange(2, 8, 'backward');
    input.scrollLeft = 12;
    list.scrollTop = 75;

    await Promise.resolve();
    const updated = structuredClone(cues);
    updated[1].tokens[0].gloss = 'arriving word card';
    view.setData('vid', updated, DEFAULT_EXTENSION_SETTINGS);

    const restored = list.querySelector<HTMLInputElement>('[data-quick-fix-input]')!;
    expect(document.activeElement).toBe(restored);
    expect(restored.value).toBe('hola! a longer correction');
    expect([restored.selectionStart, restored.selectionEnd, restored.selectionDirection]).toEqual([2, 8, 'backward']);
    expect(restored.scrollLeft).toBe(12);
    expect(list.scrollTop).toBe(75);
    restored.dispatchEvent(new document.defaultView!.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(onQuickFixSave).toHaveBeenCalledWith('c1', 0, 'hola! a longer correction');
  });

  it('does not reclaim editor focus after the user moves to search before a metadata update', () => {
    const { view, list, document } = setupQuickFix();
    const { input } = openEditor(view, list);
    type(input, 'hola!');
    const search = document.getElementById('search')!;
    search.focus();
    const updated = structuredClone(cues);
    updated[1].tokens[0].gloss = 'arriving word card';
    view.setData('vid', updated, DEFAULT_EXTENSION_SETTINGS);
    expect(document.activeElement).toBe(search);
    expect(list.querySelector<HTMLInputElement>('[data-quick-fix-input]')!.value).toBe('hola!');
  });

  it('closing the editing state removes the editor', () => {
    const { view, list } = setupQuickFix();
    openEditor(view, list);
    view.setQuickFixEditing(null);
    expect(list.querySelector('[data-quick-fix-editor]')).toBeNull();
  });
});

it('coalesces search input and invalidates searchable metadata when the same track is patched', async () => {
  const { view, list, search } = setupDom();
  view.setData('vid', cues, DEFAULT_EXTENSION_SETTINGS);
  const initial = list.firstElementChild;
  const serialize = vi.spyOn(JSON, 'stringify');
  search.value = 'hel';
  search.dispatchEvent(new search.ownerDocument.defaultView!.Event('input'));
  await Promise.resolve();
  search.value = 'hello';
  search.dispatchEvent(new search.ownerDocument.defaultView!.Event('input'));
  expect(list.firstElementChild).toBe(initial);
  await new Promise((resolve) => search.ownerDocument.defaultView!.requestAnimationFrame(resolve));
  expect(serialize).toHaveBeenCalledTimes(1);
  const call = serialize.mock.calls[0];
  assert(call, 'Search rendering must serialize a signature');
  expect(JSON.stringify(call[0]).length).toBeLessThan(100);
  serialize.mockRestore();
  expect(list.querySelectorAll('.cue')).toHaveLength(1);
  const patched = structuredClone(cues);
  patched[1].tokens[0].gloss = 'hello';
  view.setData('vid', patched, DEFAULT_EXTENSION_SETTINGS);
  expect(list.querySelectorAll('.cue')).toHaveLength(2);
  patched[0].sourceText = 'corrected';
  search.value = 'corrected';
  view.setData('vid', patched, DEFAULT_EXTENSION_SETTINGS);
  expect(list.querySelector('[data-cue-id="c1"]')).not.toBeNull();
});
