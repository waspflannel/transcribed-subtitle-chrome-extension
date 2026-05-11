import { describe, expect, it } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import { renderOverlayContent, type OverlayRenderState } from '../utils/overlay';
import type { TrackResponse } from '../utils/contracts';

describe('renderOverlayContent', () => {
  it('renders the active cue source text, translation, and token learning metadata', () => {
    const html = renderOverlayContent(readyState());

    expect(html).toContain('AI subtitles');
    expect(html).toContain('class="rail"');
    expect(html).toContain('class="token-card"');
    expect(html).toContain('lang="ar"');
    expect(html).toContain('00:00 - 00:02');
    expect(html).toContain('salam');
    expect(html).toContain('Hello');
    expect(html).toContain('sa-laam');
    expect(html).toContain('peace greeting');
    expect(html).not.toContain('egyptian');
    expect(html).not.toContain('rail-control');
  });

  it('respects romanization and gloss visibility settings', () => {
    const html = renderOverlayContent({
      ...readyState(),
      settings: {
        ...DEFAULT_EXTENSION_SETTINGS,
        showRomanization: false,
        showGloss: false,
      },
    });

    expect(html).toContain('salam');
    expect(html).toContain('Hello');
    expect(html).not.toContain('sa-laam');
    expect(html).not.toContain('peace greeting');
  });

  it('renders hover preview and pinned token detail without null placeholders', () => {
    const hoverHtml = renderOverlayContent(readyState());
    const pinnedHtml = renderOverlayContent(readyState(), {
      pinnedTokenIndex: 0,
    });

    expect(hoverHtml).toContain('role="tooltip"');
    expect(hoverHtml).toContain('sa-laam | peace greeting');
    expect(pinnedHtml).toContain('aria-pressed="true"');
    expect(pinnedHtml).toContain('class="token-popover"');
    expect(pinnedHtml).toContain('Root');
    expect(pinnedHtml).toContain('s-l-m');
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
              text: 'salam',
              romanization: 'sa-laam',
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
              text: 'salam',
              romanization: 'sa-laam',
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
      sourceLanguage: 'en',
      targetLanguage: 'en',
      sourceText: 'Hello everyone',
      translatedText: 'Hello everyone',
      tokens: [],
    });
    const html = renderOverlayContent(readyState(track));

    expect(html).toContain('lang="en"');
    expect(html).toContain('Hello everyone');
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
    sourceLanguage: overrides.sourceLanguage ?? 'ar',
    targetLanguage: overrides.targetLanguage ?? 'en',
    generatedAt: '2026-05-02T00:00:00Z',
    expiresAt: '2026-06-01T00:00:00Z',
    webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nsalam\n",
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 500,
        endMs: 2100,
        sourceText: overrides.sourceText ?? 'salam',
        translatedText: overrides.translatedText ?? 'Hello',
        tokens: overrides.tokens ?? [
          {
            index: 0,
            text: 'salam',
            lemma: 'salam',
            root: 's-l-m',
            partOfSpeech: 'noun',
            gloss: 'peace greeting',
            romanization: 'sa-laam',
            usageNote: 'Common greeting.',
          },
        ],
      },
    ],
  };
}
