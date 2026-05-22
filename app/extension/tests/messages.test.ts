import { describe, expect, it } from 'vitest';

import { isRuntimeMessage } from '../utils/messages';

describe('runtime message validation', () => {
  it('accepts concrete extension messages with required payload fields', () => {
    expect(isRuntimeMessage({ type: 'content.getState' })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.getState', syncBackend: false })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.updateSettings', patch: { showTranslation: true } })).toBe(true);
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
          message: 'Tokenizing subtitles...',
          stage: 'tokenizing',
          progressPercent: 65,
        },
      }),
    ).toBe(true);
  });

  it('rejects messages that only provide a type without the payload contract', () => {
    expect(isRuntimeMessage({ type: 'popup.updateSettings' })).toBe(false);
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
