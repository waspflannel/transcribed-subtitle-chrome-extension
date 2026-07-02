import { describe, expect, it } from 'vitest';

import { cueForNavigation, cueForPlaybackTime, cueStartPlaybackSeconds } from '../utils/cue-navigation';
import type { SubtitleCue } from '../utils/contracts';

describe('cue navigation', () => {
  it('navigates from the active cue without falling off track bounds', () => {
    const cues = cueList();

    expect(
      cueForNavigation({
        track: { cues },
        activeCue: cues[1],
        currentTimeSeconds: null,
        direction: 'previous',
      })?.cueId,
    ).toBe('cue-1');
    expect(
      cueForNavigation({
        track: { cues },
        activeCue: cues[1],
        currentTimeSeconds: null,
        direction: 'next',
      })?.cueId,
    ).toBe('cue-3');
    expect(
      cueForNavigation({
        track: { cues },
        activeCue: cues[0],
        currentTimeSeconds: null,
        direction: 'previous',
      })?.cueId,
    ).toBe('cue-1');
  });

  it('falls back to playback time when no text cue is active', () => {
    const cues = cueList();

    expect(
      cueForNavigation({
        track: { cues },
        activeCue: null,
        currentTimeSeconds: 0,
        direction: 'next',
      })?.cueId,
    ).toBe('cue-1');
    expect(
      cueForNavigation({
        track: { cues },
        activeCue: null,
        currentTimeSeconds: 2.6,
        direction: 'next',
      })?.cueId,
    ).toBe('cue-3');
    expect(
      cueForNavigation({
        track: { cues },
        activeCue: null,
        currentTimeSeconds: 8,
        direction: 'previous',
      })?.cueId,
    ).toBe('cue-3');
  });

  it('uses the local timing offset for active-cue lookup and cue starts', () => {
    const cues = cueList();

    expect(cueForPlaybackTime({ cues }, 3.5, 1)?.cueId).toBe('cue-2');
    expect(cueStartPlaybackSeconds(cues[1], 1)).toBe(3.5);
    expect(cueStartPlaybackSeconds(cues[0], -2)).toBe(0);
  });

  it('treats the cue interval as half-open so the next cue wins at the shared millisecond', () => {
    const cues: SubtitleCue[] = [
      cue('cue-a', 0, 0, 1500),
      cue('cue-b', 1, 1500, 3000),
    ];

    // At exactly 1500ms the cue-a interval is closed and cue-b is open.
    expect(cueForPlaybackTime({ cues }, 1.5)?.cueId).toBe('cue-b');
  });

  it('still matches the final cue at its exact end boundary', () => {
    const cues: SubtitleCue[] = [cue('cue-final', 0, 0, 1500)];

    expect(cueForPlaybackTime({ cues }, 1.5)?.cueId).toBe('cue-final');
  });
});

function cueList(): SubtitleCue[] {
  return [
    cue('cue-1', 0, 500, 1500),
    cue('cue-2', 1, 2500, 4200),
    cue('cue-3', 2, 6000, 7500),
  ];
}

function cue(cueId: string, index: number, startMs: number, endMs: number): SubtitleCue {
  return {
    cueId,
    index,
    startMs,
    endMs,
    sourceText: `Source ${index + 1}`,
    translatedText: `Translation ${index + 1}`,
    tokens: [
      {
        index: 0,
        text: `word-${index + 1}`,
        normalizedText: `word-${index + 1}`,
      },
    ],
  };
}
