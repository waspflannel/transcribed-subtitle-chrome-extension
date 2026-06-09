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
    expect(html).toContain('data-transcript-action="replay"');
  });
  it('shows an empty-state when the query matches nothing', () => {
    expect(panelTranscriptListHtml({ cues, activeCueId: null, query: 'zzz', settings: DEFAULT_EXTENSION_SETTINGS })).toContain('No cues match');
  });
});
