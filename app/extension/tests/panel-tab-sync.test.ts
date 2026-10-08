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
    expect(isRuntimeMessage({ type: 'panel.generateSubtitles', youtubeVideoId: 'dQw4w9WgXcQ', tabId: 1, windowId: 3 })).toBe(true);
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
