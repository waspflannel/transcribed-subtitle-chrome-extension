import { describe, expect, it } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import { renderOverlayContent, type OverlayRenderState } from '../utils/overlay';
import type { TrackResponse } from '../utils/contracts';

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

  it('renders scoped blur states without paused rail reveal attributes', () => {
    const html = renderOverlayContent(
      readyStateWithSettings({
        showTranslation: true,
        blurSourceWords: true,
        blurRomanization: true,
        blurTranslation: true,
      }),
    );

    expect(html).toContain('data-study-rail');
    expect(html).not.toContain('data-reveal-on-pause');
    expect(html).not.toContain('data-video-paused');
    expect(html).toContain('class="token-text study-blur study-blur--source"');
    expect(html).toContain('class="token-extra study-romanization study-blur study-blur--romanization"');
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
  });

  it('renders generation progress while the background job is running', () => {
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

    expect(html).toContain('Generating subtitles');
    expect(html).toContain('Video dQw4w9WgXcQ');
    expect(html).toContain('class="rail rail--message"');
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

function readyState(track = trackResponse()): OverlayRenderState {
  return {
    page: {
      supported: true,
      videoId: 'dQw4w9WgXcQ',
      url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
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
    cues: [
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
