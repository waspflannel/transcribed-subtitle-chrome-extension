import { describe, expect, it } from 'vitest';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { SubtitleCue } from '../utils/contracts';
import { filterTranscriptCues, panelTranscriptListHtml } from '../utils/panel/transcript';

const cues: SubtitleCue[] = [
  { cueId: 'c1', index: 0, startMs: 500, endMs: 2100, sourceText: 'hola', translatedText: 'hello', romanization: 'o-la', tokens: [{ index: 0, text: 'hola', normalizedText: 'hola' }] },
  { cueId: 'c2', index: 1, startMs: 2600, endMs: 4200, sourceText: 'adios', translatedText: 'goodbye', romanization: 'a-dios', tokens: [{ index: 0, text: 'adios', normalizedText: 'adios' }] },
];

describe('filterTranscriptCues', () => {
  it('returns all cues for an empty query', () => {
    expect(filterTranscriptCues(cues, '').length).toBe(2);
  });
  it('matches source, romanization, and translation text', () => {
    expect(filterTranscriptCues(cues, 'goodbye').map((c) => c.cueId)).toEqual(['c2']);
    expect(filterTranscriptCues(cues, 'o-la').map((c) => c.cueId)).toEqual(['c1']);
  });
});

describe('panelTranscriptListHtml', () => {
  it('renders rows with timecode, source, and the active-cue marker', () => {
    const html = panelTranscriptListHtml({ cues, activeCueId: 'c2', query: '', settings: { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true } });
    expect(html).toContain('data-cue-id="c1"');
    expect(html).toContain('data-cue-id="c2"');
    expect(html).toContain('hola');
    expect(html).toContain('goodbye');
    expect(html).toContain('aria-current="true"');
    expect(html).toContain('data-transcript-action="jump"');
    expect(html).toContain('data-transcript-action="copy"');
    expect(html).not.toContain('data-transcript-action="replay"');
  });
  it('shows an empty-state when the query matches nothing', () => {
    expect(panelTranscriptListHtml({ cues, activeCueId: null, query: 'zzz', settings: DEFAULT_EXTENSION_SETTINGS })).toContain('No cues match');
  });
});

describe('panelTranscriptListHtml quick fix mode', () => {
  const settings = { ...DEFAULT_EXTENSION_SETTINGS, showRomanization: true };
  const cuesWithReadings: SubtitleCue[] = [
    {
      cueId: 'c1',
      index: 0,
      startMs: 500,
      endMs: 2100,
      sourceText: 'hola tu',
      translatedText: 'hello you',
      tokens: [
        { index: 0, text: 'hola', normalizedText: 'hola', romanization: 'o-la' },
        { index: 1, text: 'tu', normalizedText: 'tu', romanization: 'too' },
      ],
    },
  ];

  it('keeps token romanization and spacing identical to normal token rendering', () => {
    const normal = panelTranscriptListHtml({ cues: cuesWithReadings, activeCueId: null, query: '', settings });
    const quickFix = panelTranscriptListHtml({ cues: cuesWithReadings, activeCueId: null, query: '', settings, editingCueId: 'c1', quickFixMode: true });

    expect(normal).toContain('<span class="tok">hola<small>o-la</small></span>');
    expect(quickFix).toContain('data-transcript-action="quick-fix-token"');
    expect(quickFix).toContain('<small>o-la</small>');
    /* Buttons join like normal tokens — no injected spaces that reflow the line. */
    expect(quickFix).toContain('</button><button');
    expect(quickFix).not.toContain('</button> <button');
  });

  it('renders tokens as buttons even when normal mode would show a plain line', () => {
    const html = panelTranscriptListHtml({ cues, activeCueId: null, query: '', settings: DEFAULT_EXTENSION_SETTINGS, editingCueId: 'c1', quickFixMode: true });

    expect(html).toContain('class="tok tok-edit"');
    expect(html).toContain('aria-label="Edit source token hola"');
  });

  it('keeps the source word and places an escaped editor below the line', () => {
    const editing: SubtitleCue[] = [
      { cueId: 'c1', index: 0, startMs: 0, endMs: 1000, sourceText: 'a "b"', translatedText: 'x', tokens: [{ index: 0, text: 'a "b"', normalizedText: 'a b' }] },
    ];
    const html = panelTranscriptListHtml({
      cues: editing,
      activeCueId: null,
      query: '',
      settings: DEFAULT_EXTENSION_SETTINGS,
      editingCueId: 'c1', quickFixMode: true,
      quickFixEditing: { cueId: 'c1', tokenIndex: 0, value: 'a "b"' },
    });

    expect(html).toContain('data-quick-fix-editor');
    expect(html).toContain('value="a &quot;b&quot;"');
    expect(html).toContain('data-transcript-action="quick-fix-save"');
    expect(html).toContain('data-transcript-action="quick-fix-cancel"');
    expect(html).toContain('data-transcript-action="quick-fix-token"');
  });

  it('does not render token buttons outside quick fix mode', () => {
    const html = panelTranscriptListHtml({ cues, activeCueId: null, query: '', settings: DEFAULT_EXTENSION_SETTINGS });

    expect(html).not.toContain('tok-edit');
    expect(html).not.toContain('data-quick-fix-editor');
  });
});
