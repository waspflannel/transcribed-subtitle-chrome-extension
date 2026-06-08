import { describe, expect, it } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import { accountStateFromJobHistory } from '../utils/popup-saas-state';
import type { PopupState } from '../utils/messages';
import { selectDefaultView } from '../utils/panel/view-state';

function baseState(overrides: Partial<PopupState> = {}): PopupState {
  return {
    installId: 'install-1',
    settings: DEFAULT_EXTENSION_SETTINGS,
    accountState: accountStateFromJobHistory([]),
    subtitleState: { type: 'no-track' },
    jobHistory: [],
    ...overrides,
  };
}

describe('selectDefaultView', () => {
  it('opens Account when the user is anonymous', () => {
    expect(selectDefaultView(baseState())).toBe('account');
  });

  it('opens Study when signed in and a track is ready', () => {
    const state = baseState({
      accountState: { ...accountStateFromJobHistory([]), status: 'authenticated', planName: 'Local beta' },
      subtitleState: {
        type: 'ready',
        track: {
          trackId: 't',
          jobId: 'j',
          youtubeVideoId: 'v',
          sourceLanguage: 'spa',
          targetLanguage: 'eng',
          generatedAt: '',
          expiresAt: '',
          webVtt: 'WEBVTT',
          cues: [
            {
              cueId: 'c1',
              index: 0,
              startMs: 0,
              endMs: 1000,
              sourceText: 'hola',
              translatedText: 'hello',
              tokens: [{ index: 0, text: 'hola', normalizedText: 'hola' }],
            },
          ],
        },
      },
    });
    expect(selectDefaultView(state)).toBe('study');
  });

  it('opens Generate when signed in without a ready track', () => {
    const state = baseState({
      accountState: { ...accountStateFromJobHistory([]), status: 'authenticated' },
      subtitleState: { type: 'no-track' },
    });
    expect(selectDefaultView(state)).toBe('generate');
  });
});
