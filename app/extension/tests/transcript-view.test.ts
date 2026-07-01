import { describe, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { SubtitleCue } from '../utils/contracts';
import { bindTranscriptView } from '../entrypoints/sidepanel/transcript-view';

const cues: SubtitleCue[] = [
  { cueId: 'c1', index: 0, startMs: 500, endMs: 2100, sourceText: 'hola', translatedText: 'hello', romanization: 'o-la', tokens: [{ index: 0, text: 'hola', normalizedText: 'hola' }] },
  { cueId: 'c2', index: 1, startMs: 2600, endMs: 4200, sourceText: 'adios', translatedText: 'goodbye', romanization: 'a-dios', tokens: [{ index: 0, text: 'adios', normalizedText: 'adios' }] },
];

function setupDom() {
  const dom = new JSDOM('<input id="search" /><ol id="list"></ol><p id="status"></p>');
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

  it('never auto-scrolls on the data-render path', () => {
    const { view, scrollSpy } = setupDom();
    const settings = { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true };

    view.setData('vid', cues, settings);
    view.setActiveCue('c1');
    expect(scrollSpy.calls).toBe(1);

    scrollSpy.calls = 0;
    view.setData('vid', cues, settings);
    view.setData('vid', cues, settings);
    expect(scrollSpy.calls).toBe(0);
  });
});

describe('bindTranscriptView setActiveCue', () => {
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
