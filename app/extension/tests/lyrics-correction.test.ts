import { describe, expect, it } from 'vitest';

import { acceptLyricsCorrectionTrack, canApplyLyricsCorrection, lyricsCharacterCount } from '../utils/lyrics-correction';

describe('lyrics correction guards', () => {
  it('counts Unicode code points rather than UTF-16 units', () => {
    expect(lyricsCharacterCount('😀ไทย')).toBe(4);
  });

  it('disables empty, oversized, and active correction submissions', () => {
    expect(canApplyLyricsCorrection('', null)).toBe(false);
    expect(canApplyLyricsCorrection('a'.repeat(25001), null)).toBe(false);
    expect(canApplyLyricsCorrection('lyrics', { status: 'running' } as never)).toBe(false);
    expect(canApplyLyricsCorrection('lyrics', null)).toBe(true);
  });

  it('accepts only the completed current attempt track', () => {
    const currentTrack = { trackId: 'old' } as never;
    const correctedTrack = { trackId: 'new' } as never;

    expect(acceptLyricsCorrectionTrack(currentTrack, { attemptId: 'new-attempt', status: 'completed', track: correctedTrack } as never, 'old-attempt')).toBeNull();
    expect(acceptLyricsCorrectionTrack(currentTrack, { attemptId: 'new-attempt', status: 'completed', track: correctedTrack } as never, 'new-attempt')).toEqual(correctedTrack);
  });
});
