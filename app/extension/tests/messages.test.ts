import { describe, expect, it } from 'vitest';

import { isBackgroundRequest, isRuntimeMessage } from '../utils/messages';

describe('runtime message validation', () => {
  it('accepts concrete extension messages with required payload fields', () => {
    expect(isRuntimeMessage({ type: 'content.getState' })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.getState', syncBackend: false })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.generateSubtitles', confirmationContext: 'confirmed-details' })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.updateSettings', patch: { showTranslation: true } })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.updateSettings', patch: { blurSourceWords: true } })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.login', email: 'learner@example.com', password: 'secret' })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.logout' })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.cancelSubtitleJob', jobId: 'job', youtubeVideoId: 'video', tabId: 12, windowId: 4 })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.cancelSubtitleJob', jobId: 'old-job', youtubeVideoId: 'video' })).toBe(true);
    expect(isRuntimeMessage({
      type: 'panel.submitLyricsCorrection', jobId: 'job', trackId: 'track', youtubeVideoId: 'video', lyrics: 'lyrics',
    })).toBe(true);
    expect(isRuntimeMessage({
      type: 'panel.submitLyricsCorrection', jobId: 'job', trackId: 'track', youtubeVideoId: 'video', lyrics: 'lyrics', allowPartial: true,
    })).toBe(true);
    expect(isRuntimeMessage({
      type: 'content.enrichLearningToken', youtubeVideoId: 'dQw4w9WgXcQ', trackId: 'track-1', cueId: 'cue-0001', tokenIndex: 0,
    })).toBe(true);
    expect(isRuntimeMessage({
      type: 'background.subtitleStateChanged', subtitleState: {
        type: 'loading', youtubeVideoId: 'dQw4w9WgXcQ', message: 'Optimizing audio...', stage: 'optimizing-audio', progressPercent: 35,
      },
    })).toBe(true);
  });

  it('rejects messages that only provide a type without the payload contract', () => {
    expect(isRuntimeMessage({ type: 'panel.updateSettings' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.generateSubtitles' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.generateSubtitles', confirmationContext: '' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.cancelSubtitleJob', jobId: 'job', youtubeVideoId: 'video', tabId: '12' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.submitLyricsCorrection', lyrics: 'lyrics' })).toBe(false);
    expect(isRuntimeMessage({
      type: 'panel.submitLyricsCorrection', jobId: 'job', trackId: 'track', youtubeVideoId: 'video', lyrics: 'lyrics', allowPartial: 'yes',
    })).toBe(false);
    expect(isRuntimeMessage({ type: 'content.updateSettings' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.getState', syncBackend: 'yes' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.login', email: 'learner@example.com' })).toBe(false);
    expect(isRuntimeMessage({
      type: 'content.enrichLearningToken', youtubeVideoId: 'dQw4w9WgXcQ', trackId: 'track-1', cueId: 'cue-0001', tokenIndex: -1,
    })).toBe(false);
    expect(isRuntimeMessage({
      type: 'background.subtitleStateChanged', subtitleState: {
        type: 'loading', youtubeVideoId: 'dQw4w9WgXcQ', message: 'Working', stage: 'old-stage', progressPercent: 10,
      },
    })).toBe(false);
  });

  it('accepts loading states carrying a partial track and rejects malformed ones', () => {
    const loadingState = { type: 'loading', youtubeVideoId: 'dQw4w9WgXcQ', message: 'Tokenizing subtitles...', stage: 'tokenizing', progressPercent: 65 };
    const partialTrack = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001', youtubeVideoId: 'dQw4w9WgXcQ', sourceLanguage: 'spa', revision: 2,
      cues: [{ cueId: 'cue-0001', index: 0, startMs: 500, endMs: 2100, sourceText: 'hola a todos' }],
    };
    expect(isRuntimeMessage({ type: 'background.subtitleStateChanged', subtitleState: { ...loadingState, partialTrack } })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.subtitleStateChanged', subtitleState: { ...loadingState, partialTrack: { ...partialTrack, revision: 'two' } } })).toBe(false);
    expect(isRuntimeMessage({ type: 'background.subtitleStateChanged', subtitleState: { ...loadingState, partialTrack: { ...partialTrack, cues: 'nope' } } })).toBe(false);
  });
});

describe('phase 2 transcript relay', () => {
  it('requires track and tab identity on cue changes', () => {
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v', trackId: 'track-1' })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: null, youtubeVideoId: 'v', trackId: null })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v', trackId: 'track-1', tabId: 12 })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v' })).toBe(false);
    expect(isRuntimeMessage({ type: 'background.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v' })).toBe(false);
    expect(isRuntimeMessage({ type: 'background.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v', trackId: 'track-1', tabId: '12' })).toBe(false);
  });

  it('accepts exact-tab seek requests with valid modes', () => {
    expect(isRuntimeMessage({ type: 'panel.seekToCue', tabId: 12, youtubeVideoId: 'v', trackId: 'track-1', cueId: 'cue-1', mode: 'jump' })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.seekToCue', youtubeVideoId: 'v', trackId: 'track-1', cueId: 'cue-1', mode: 'jump' })).toBe(false);
    expect(isRuntimeMessage({ type: 'background.seekToCue', youtubeVideoId: 'v', trackId: 'track-1', cueId: 'cue-1', mode: 'replay', tabId: 12 })).toBe(true);
    expect(isRuntimeMessage({ type: 'panel.seekToCue', cueId: 'cue-1', mode: 'nope' })).toBe(false);
    expect(isRuntimeMessage({ type: 'panel.seekToCue', cueId: 'cue-1', mode: 'jump' })).toBe(false);
  });

  it('accepts transcript-focus signals scoped to a window', () => {
    expect(isRuntimeMessage({ type: 'content.focusPanelTranscript' })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.focusTranscript', windowId: 4 })).toBe(true);
  });
});

describe('background-bound message narrowing', () => {
  it('accepts sender requests and rejects background notices', () => {
    const panelRequest = { type: 'panel.getState' as const, syncBackend: false };
    const backgroundNotice = { type: 'background.focusTranscript' as const };
    expect(isRuntimeMessage(panelRequest)).toBe(true);
    expect(isBackgroundRequest(panelRequest)).toBe(true);
    expect(isRuntimeMessage(backgroundNotice)).toBe(true);
    expect(isBackgroundRequest(backgroundNotice)).toBe(false);
  });
});
