import { describe, expect, it } from 'vitest';

import { isRuntimeMessage } from '../utils/messages';

describe('runtime message validation', () => {
  it('accepts concrete extension messages with required payload fields', () => {
    expect(isRuntimeMessage({ type: 'content.getState' })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.getState', syncBackend: false })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.updateSettings', patch: { showTranslation: true } })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.updateSettings', patch: { blurSourceWords: true } })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.login', email: 'learner@example.com', password: 'secret' })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.logout' })).toBe(true);
    expect(
      isRuntimeMessage({
        type: 'content.enrichLearningToken',
        youtubeVideoId: 'dQw4w9WgXcQ',
        trackId: 'track-1',
        cueId: 'cue-0001',
        tokenIndex: 0,
      }),
    ).toBe(true);
    expect(
      isRuntimeMessage({
        type: 'background.subtitleStateChanged',
        subtitleState: {
          type: 'loading',
          youtubeVideoId: 'dQw4w9WgXcQ',
          message: 'Optimizing audio...',
          stage: 'optimizing-audio',
          progressPercent: 35,
        },
      }),
    ).toBe(true);
  });

  it('rejects messages that only provide a type without the payload contract', () => {
    expect(isRuntimeMessage({ type: 'popup.updateSettings' })).toBe(false);
    expect(isRuntimeMessage({ type: 'content.updateSettings' })).toBe(false);
    expect(isRuntimeMessage({ type: 'popup.getState', syncBackend: 'yes' })).toBe(false);
    expect(isRuntimeMessage({ type: 'popup.login', email: 'learner@example.com' })).toBe(false);
    expect(
      isRuntimeMessage({
        type: 'content.enrichLearningToken',
        youtubeVideoId: 'dQw4w9WgXcQ',
        trackId: 'track-1',
        cueId: 'cue-0001',
        tokenIndex: -1,
      }),
    ).toBe(false);
    expect(
      isRuntimeMessage({
        type: 'background.subtitleStateChanged',
        subtitleState: {
          type: 'loading',
          youtubeVideoId: 'dQw4w9WgXcQ',
          message: 'Working',
          stage: 'old-stage',
          progressPercent: 10,
        },
      }),
    ).toBe(false);
  });
});

describe('isRuntimeMessage — phase 2 transcript relay', () => {
  it('accepts content.activeCueChanged with a cueId or null', () => {
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v' })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: null, youtubeVideoId: 'v' })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: 'cue-1' })).toBe(false);
  });

  it('accepts background.activeCueChanged', () => {
    expect(isRuntimeMessage({ type: 'background.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v' })).toBe(true);
  });

  it('accepts popup.seekToCue and background.seekToCue with a valid mode', () => {
    expect(isRuntimeMessage({ type: 'popup.seekToCue', cueId: 'cue-1', mode: 'jump' })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.seekToCue', cueId: 'cue-1', mode: 'replay' })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.seekToCue', cueId: 'cue-1', mode: 'nope' })).toBe(false);
  });

  it('accepts the transcript-focus signals', () => {
    expect(isRuntimeMessage({ type: 'content.focusPanelTranscript' })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.focusTranscript' })).toBe(true);
  });
});
