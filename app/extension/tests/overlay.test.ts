// @vitest-environment jsdom

import { describe, expect, it, vi } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import { OverlayShell } from '../utils/overlay';
import { renderOverlayContent } from '../utils/overlay/overlay-render';
import { overlayStyles } from '../utils/overlay/overlay-styles';
import type { OverlayRenderState } from '../utils/overlay/types';
import type { TrackResponse } from '../utils/contracts';

vi.mock('wxt/browser', () => ({ browser: { runtime: { getURL: () => '' } } }));

describe('renderOverlayContent', () => {
  it('renders the active cue source text, translation, and token learning metadata', () => {
    const html = renderOverlayContent(readyStateWithSettings({ showTranslation: true }));

    expect(html).toContain('AI subtitles');
    expect(html).toContain('class="rail"');
    expect(html).toContain('class="token-card"');
    expect(html).toContain('lang="spa"');
    expect(html).toContain('00:00 - 00:02');
    expect(html).toContain('hola');
    expect(html).toContain('Bonjour');
    expect(html).toContain('o-la');
    expect(html).toContain('hello');
    expect(html).toContain('data-study-control="replay"');
    expect(html).toContain('data-study-control="copy"');
    expect(html).not.toContain('data-study-control="blur-source"');
    expect(html).not.toContain('data-study-control="blur-romanization"');
    expect(html).not.toContain('data-study-control="blur-translation"');
    expect(html).not.toContain('egyptian');
  });

  it('respects romanization and gloss visibility settings', () => {
    const html = renderOverlayContent(
      readyStateWithSettings({
        showRomanization: false,
        showTranslation: true,
        showGloss: false,
      }),
    );

    expect(html).toContain('hola');
    expect(html).toContain('Bonjour');
    expect(html).not.toContain('o-la');
    expect(html).not.toContain('hello');
  });

  it('hides cue translation unless translation display is enabled', () => {
    const html = renderOverlayContent(readyState());

    expect(html).toContain('hola');
    expect(html).not.toContain('Bonjour');
    expect(html).not.toContain('class="translation"');
  });

  it('stamps dir="auto" so RTL source text and translations lay out correctly', () => {
    const html = renderOverlayContent(readyStateWithSettings({ showTranslation: true }));

    expect(html).toContain('dir="auto"');
  });

  it('renders token, romanization, and translation blur scopes', () => {
    const state = readyStateWithSettings({
      showTranslation: true,
      blurSourceWords: true,
      blurRomanization: true,
      blurTranslation: true,
    });
    const html = renderOverlayContent({
      ...state,
      activeCue: {
        ...state.activeCue!,
        romanization: 'o-la',
      },
    });

    expect(html).toContain('data-study-rail');
    expect(html).toContain('class="token-text study-blur study-blur--token"');
    expect(html).toContain('class="token-extra study-token-romanization study-blur study-blur--token"');
    expect(html).toContain('class="cue-romanization study-cue-romanization study-blur study-blur--romanization"');
    expect(html).toContain('class="translation study-translation study-blur study-blur--translation"');
    expect(html).toContain('tabindex="0"');
    expect(html).not.toContain('data-study-control="blur-source"');
  });

  it('renders overlay copy feedback status', () => {
    const copiedHtml = renderOverlayContent(readyState(), {
      pinnedTokenIndex: null,
      copyStatus: 'copied',
    });
    const failedHtml = renderOverlayContent(readyState(), {
      pinnedTokenIndex: null,
      copyStatus: 'failed',
    });

    expect(copiedHtml).toContain('Copied');
    expect(copiedHtml).toContain('class="control-status copied"');
    expect(failedHtml).toContain('Copy failed');
    expect(failedHtml).toContain('class="control-status failed"');
  });

  it('renders hover preview and pinned token detail without null placeholders', () => {
    const hoverHtml = renderOverlayContent(readyState());
    const pinnedHtml = renderOverlayContent(readyState(), {
      pinnedTokenIndex: 0,
    });

    expect(hoverHtml).toContain('role="tooltip"');
    expect(hoverHtml).toContain('o-la | hello');
    expect(pinnedHtml).toContain('aria-pressed="true"');
    expect(pinnedHtml).toContain('class="token-popover"');
    expect(pinnedHtml).toContain('Root');
    expect(pinnedHtml).toContain('hol');
    expect(pinnedHtml).toContain('Usage note');
    expect(pinnedHtml).toContain('Common greeting.');
    expect(pinnedHtml).not.toContain('null');
    expect(pinnedHtml).toContain('data-focus-key="cue-0001:0"');
    expect(pinnedHtml).toContain('data-focus-key="token-detail-close"');
    expect(pinnedHtml).toContain('data-return-focus-key="cue-0001:0"');
  });

  it('keeps an action message visible during a silent gap', () => {
    const state = readyState();
    const html = renderOverlayContent({ ...state, activeCue: null }, {
      pinnedTokenIndex: null,
      actionStatus: { message: 'Open the side panel for the transcript.', tone: 'info' },
    });

    expect(html).toContain('Open the side panel for the transcript.');
    expect(html).toContain('There is no subtitle cue at the current playback position.');
  });

  it('shows one stable generating message until the first preview cue arrives', () => {
    const html = renderOverlayContent({
      ...readyState(),
      subtitleState: {
        type: 'loading',
        youtubeVideoId: 'dQw4w9WgXcQ',
        message: 'Generating subtitles...',
        stage: 'transcribing',
        progressPercent: 45,
      },
      activeCue: null,
    });

    expect(html).toContain('>Generating</div>');
    expect(html).toContain('class="rail rail--message rail--generating"');
    expect(html).not.toContain('Generating subtitles...');
    expect(html).not.toContain('Video dQw4w9WgXcQ');
  });

  it('renders partial cues without token buttons while the job is still running', () => {
    const html = renderOverlayContent(
      partialLoadingState({
        translatedText: 'Bonjour a tous',
        romanization: 'o-la a to-dos',
      }),
    );

    expect(html).toContain('still generating');
    expect(html).toContain('hola a todos');
    expect(html).toContain('Bonjour a tous');
    expect(html).toContain('o-la a to-dos');
    expect(html).toContain('lang="spa"');
    expect(html).toContain('00:00 - 00:02');
    // No interactivity until the finalized track lands.
    expect(html).not.toContain('class="token-card"');
    expect(html).not.toContain('data-study-control');
  });

  it('renders partial cues with source text only before enrichment batches land', () => {
    const html = renderOverlayContent(partialLoadingState());

    expect(html).toContain('hola a todos');
    expect(html).not.toContain('class="translation');
    expect(html).not.toContain('class="cue-romanization');
  });

  it('makes a blurred partial source layer independently revealable', () => {
    const html = renderOverlayContent({
      ...partialLoadingState(),
      settings: { ...DEFAULT_EXTENSION_SETTINGS, blurSourceWords: true },
    });

    expect(html).toContain('partial-source-layer token-text study-blur study-blur--token');
    expect(html).toContain('tabindex="0" aria-label="Partial source text, focus to reveal blurred text"');
  });

  it('renders an actionable local attachment failure', () => {
    const html = renderOverlayContent({ ...readyState(), bindingError: 'The subtitle track could not load.' });

    expect(html).toContain('Subtitle display needs a retry');
    expect(html).toContain('The subtitle track could not load.');
    expect(html).toContain('data-retry-binding');
  });

  it('keeps top popovers below their token and scrollable within the viewport', () => {
    expect(overlayStyles).toContain(':host([data-position="top"]) .token-popover');
    expect(overlayStyles).toContain('top: calc(100% + 14px)');
    expect(overlayStyles).toContain('max-height: min(60vh, 420px)');
    expect(overlayStyles).toContain('overflow-y: auto');
    expect(overlayStyles).toContain('var(--popover-shift, 0px)');
    expect(overlayStyles).toContain('var(--popover-shift-y, 0px)');
  });

  it('keeps study focus separate from pointer preview and restores focus from detail', () => {
    const video = document.createElement('video');
    video.getBoundingClientRect = () => ({
      bottom: 400,
      height: 300,
      left: 100,
      right: 700,
      toJSON: () => ({}),
      top: 100,
      width: 600,
      x: 100,
      y: 100,
    });
    document.body.append(video);

    let previewStarts = 0;
    let previewEnds = 0;
    let focusStarts = 0;
    let focusEnds = 0;
    const shell = new OverlayShell(document, {
      onTokenPreview: () => { previewStarts += 1; },
      onTokenPreviewEnd: () => { previewEnds += 1; },
      onTokenFocus: () => { focusStarts += 1; },
      onTokenBlur: () => { focusEnds += 1; },
    });

    shell.update(readyState());
    previewEnds = 0;
    focusEnds = 0;
    const shadowRoot = document.querySelector('#tse-overlay-host')?.shadowRoot;
    const token = shadowRoot?.querySelector<HTMLButtonElement>('[data-token-index]');
    expect(token).toBeTruthy();

    token!.dispatchEvent(new Event('pointerenter'));
    expect(previewStarts).toBe(1);
    token!.click();
    expect(previewStarts).toBe(1);

    const content = shadowRoot?.querySelector<HTMLElement>('[data-overlay-content]');
    const currentToken = content?.querySelector<HTMLButtonElement>('[data-token-index]');
    const close = content?.querySelector<HTMLButtonElement>('[data-close-token-detail]');
    expect(close).toBeTruthy();

    currentToken!.focus();
    close!.focus();
    currentToken!.dispatchEvent(new FocusEvent('blur', { relatedTarget: close }));
    expect(focusEnds).toBe(0);
    expect(focusStarts).toBe(1);

    const replay = content?.querySelector<HTMLButtonElement>('[data-study-control="replay"]');
    expect(replay).toBeTruthy();
    replay!.focus();
    expect(focusEnds).toBe(1);

    const outside = document.createElement('button');
    document.body.append(outside);
    outside.focus();
    expect(focusEnds).toBe(1);
    outside.remove();

    close!.click();
    expect(shadowRoot?.activeElement?.getAttribute('data-focus-key')).toBe('cue-0001:0');

    previewEnds = 0;
    focusEnds = 0;
    const nextCue = { ...readyState().activeCue!, cueId: 'cue-0002' };
    const nextState = readyState(trackResponse({ cues: [nextCue] }));
    shell.update(nextState);
    expect(previewEnds).toBe(1);
    expect(focusEnds).toBe(1);

    shell.unmount();
    expect(previewEnds).toBe(2);
    expect(focusEnds).toBe(2);
    video.remove();
  });

  it('hides the partial translation when translation display is disabled', () => {
    const state = partialLoadingState({ translatedText: 'Bonjour a tous' });
    const html = renderOverlayContent({
      ...state,
      settings: { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: false },
    });

    expect(html).toContain('hola a todos');
    expect(html).not.toContain('Bonjour a tous');
  });

  it('renders loading detail for clicked tokens that only have romanization', () => {
    const state = readyState();
    const html = renderOverlayContent(
      {
        ...state,
        activeCue: {
          ...state.activeCue!,
          translatedText: state.activeCue!.sourceText,
          tokens: [
            {
              index: 0,
              text: 'hola',
              normalizedText: 'hola',
              romanization: 'o-la',
            },
          ],
        },
      },
      {
        pinnedTokenIndex: 0,
        pendingTokenKeys: new Set(['cue-0001:0']),
      },
    );

    expect(html).toContain('Loading word card...');
    expect(html).toContain('class="token-popover"');
    expect(html).not.toContain('<div class="translation">');
  });

  it('renders failed clicked-token detail for retryable word-card failures', () => {
    const state = readyState();
    const html = renderOverlayContent(
      {
        ...state,
        activeCue: {
          ...state.activeCue!,
          tokens: [
            {
              index: 0,
              text: 'hola',
              normalizedText: 'hola',
              romanization: 'o-la',
            },
          ],
        },
      },
      {
        pinnedTokenIndex: 0,
        failedTokenKeys: new Set(['cue-0001:0']),
      },
    );

    expect(html).toContain('Word card generation failed. Select the word again to retry.');
    expect(html).toContain('class="token-popover"');
    expect(html).not.toContain('null');
  });

  it('suppresses duplicate translation for English source tracks', () => {
    const track = trackResponse({
      sourceLanguage: 'eng',
      targetLanguage: 'eng',
      sourceText: 'Hello everyone',
      translatedText: 'Hello everyone',
      tokens: [
        {
          index: 0,
          text: 'Hello',
          normalizedText: 'hello',
        },
        {
          index: 1,
          text: 'everyone',
          normalizedText: 'everyone',
        },
      ],
    });
    const html = renderOverlayContent(readyState(track));

    expect(html).toContain('lang="eng"');
    expect(html).toContain('Hello');
    expect(html).toContain('everyone');
    expect(html).not.toContain('class="translation"');
  });
});

function partialLoadingState(
  cueOverrides: { translatedText?: string; romanization?: string } = {},
): OverlayRenderState {
  const cue = {
    cueId: 'cue-0001',
    index: 0,
    startMs: 500,
    endMs: 2100,
    sourceText: 'hola a todos',
    ...cueOverrides,
  };

  return {
    page: {
      supported: true,
      videoId: 'dQw4w9WgXcQ',
      url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      mediaKind: 'video',
    },
    subtitleState: {
      type: 'loading',
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      message: 'Tokenizing subtitles...',
      stage: 'tokenizing',
      progressPercent: 65,
      partialTrack: {
        jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
        youtubeVideoId: 'dQw4w9WgXcQ',
        sourceLanguage: 'spa',
        revision: 2,
        cues: [cue],
      },
    },
    settings: { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true },
    activeCue: null,
    activePartialCue: cue,
  };
}

function readyState(track = trackResponse()): OverlayRenderState {
  return {
    page: {
      supported: true,
      videoId: 'dQw4w9WgXcQ',
      url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      mediaKind: 'video',
    },
    subtitleState: {
      type: 'ready',
      track,
    },
    settings: DEFAULT_EXTENSION_SETTINGS,
    activeCue: track.cues[0],
  };
}

function readyStateWithSettings(settings: Partial<OverlayRenderState['settings']>): OverlayRenderState {
  return {
    ...readyState(),
    settings: {
      ...DEFAULT_EXTENSION_SETTINGS,
      ...settings,
    },
  };
}

function trackResponse(
  overrides: {
    sourceLanguage?: TrackResponse['sourceLanguage'];
    targetLanguage?: TrackResponse['targetLanguage'];
    sourceText?: string;
    translatedText?: string;
    tokens?: TrackResponse['cues'][number]['tokens'];
    cues?: TrackResponse['cues'];
  } = {},
): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: overrides.sourceLanguage ?? 'spa',
    targetLanguage: overrides.targetLanguage ?? 'fra',
    generatedAt: '2026-05-02T00:00:00Z',
    expiresAt: '2026-06-01T00:00:00Z',
    webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nhola\n",
    cues: overrides.cues ?? [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 500,
        endMs: 2100,
        sourceText: overrides.sourceText ?? 'hola',
        translatedText: overrides.translatedText ?? 'Bonjour',
        tokens: overrides.tokens ?? [
          {
            index: 0,
            text: 'hola',
            normalizedText: 'hola',
            lemma: 'hola',
            root: 'hol',
            partOfSpeech: 'interjection',
            gloss: 'hello',
            romanization: 'o-la',
            usageNote: 'Common greeting.',
          },
        ],
      },
    ],
  };
}
