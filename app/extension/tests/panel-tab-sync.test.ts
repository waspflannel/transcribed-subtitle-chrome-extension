import { describe, expect, it } from 'vitest';

import { isRuntimeMessage } from '../utils/messages';

describe('windowId validation on panel requests', () => {
  it('validates the correction-only refresh flag', () => {
    expect(isRuntimeMessage({ type: 'panel.getState', syncBackend: false, syncLyricsCorrection: true })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.getState', syncLyricsCorrection: 'true' })).toBe(false);
  });
  it('accepts panel.getState with a numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.getState', syncBackend: false, windowId: 7 })).toBe(true);
  });

  it('accepts panel.getState without a windowId (backward compatible)', () => {
    expect(isRuntimeMessage({ type: 'panel.getState', syncBackend: false })).toBe(true);
  });

  it('rejects panel.getState with a non-numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.getState', syncBackend: false, windowId: 'seven' })).toBe(false);
  });

  it('accepts panel.generateSubtitles with a numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.generateSubtitles', windowId: 3 })).toBe(true);
  });

  it('accepts panel.updateSettings with a numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.updateSettings', patch: { showTranslation: true }, windowId: 3 })).toBe(true);
  });

  it('accepts panel.clearLocalState with a numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.clearLocalState', windowId: 3 })).toBe(true);
  });

  it('accepts panel.seekToCue with a numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.seekToCue', tabId: 1, youtubeVideoId: 'v', trackId: 'track', cueId: 'c', mode: 'jump', windowId: 3 })).toBe(true);
  });

  it('rejects panel.seekToCue with a non-numeric windowId', () => {
    expect(isRuntimeMessage({ type: 'panel.seekToCue', tabId: 1, youtubeVideoId: 'v', trackId: 'track', cueId: 'c', mode: 'jump', windowId: 'x' })).toBe(false);
  });
});

describe('request sequencing guard', () => {
  it('drops an older response when a newer one has already been applied', () => {
    let stateSeq = 0;
    let latestAppliedSeq = 0;

    function startRequest(): number {
      return ++stateSeq;
    }

    function shouldApply(seq: number): boolean {
      if (seq < latestAppliedSeq) return false;
      latestAppliedSeq = seq;
      return true;
    }

    const seqA = startRequest();
    const seqB = startRequest();

    expect(shouldApply(seqB)).toBe(true);
    expect(shouldApply(seqA)).toBe(false);
  });

  it('applies responses in order when there are no races', () => {
    let stateSeq = 0;
    let latestAppliedSeq = 0;

    function startRequest(): number {
      return ++stateSeq;
    }

    function shouldApply(seq: number): boolean {
      if (seq < latestAppliedSeq) return false;
      latestAppliedSeq = seq;
      return true;
    }

    const seqA = startRequest();
    expect(shouldApply(seqA)).toBe(true);

    const seqB = startRequest();
    expect(shouldApply(seqB)).toBe(true);
  });
});
