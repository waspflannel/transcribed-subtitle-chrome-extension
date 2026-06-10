import { describe, expect, it } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import { anonymousAccountState } from '../utils/account-state';
import type { AccountState, PanelState } from '../utils/messages';
import { selectDefaultView } from '../utils/panel/view-state';

function baseState(overrides: Partial<PanelState> = {}): PanelState {
  return {
    installId: 'install-1',
    settings: DEFAULT_EXTENSION_SETTINGS,
    accountState: anonymousAccountState(),
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
      accountState: authenticatedAccountState(),
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
      accountState: authenticatedAccountState(),
      subtitleState: { type: 'no-track' },
    });
    expect(selectDefaultView(state)).toBe('generate');
  });
});

function authenticatedAccountState(): AccountState {
  return {
    status: 'authenticated',
    id: '1',
    email: 'learner@example.com',
    name: 'Beta Learner',
    emailVerified: true,
    planName: 'Beta Base',
    tierName: 'Base',
    tierSpeedLabel: 'Standard queue',
    monthlyMinuteLimit: 60,
    monthlyMinutesUsed: 0,
    monthlyMinutesPending: 0,
    monthlyMinutesRemaining: 60,
    resetAt: '2026-06-01T00:00:00.000Z',
    upgradeAvailable: true,
  };
}
