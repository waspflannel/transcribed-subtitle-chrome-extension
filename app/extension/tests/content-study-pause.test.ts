// @vitest-environment jsdom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { SubtitleCue, TrackResponse } from '../utils/contracts';

const mocks = vi.hoisted(() => {
  const listeners: Array<(message: unknown, sender: unknown, sendResponse: (response?: unknown) => void) => boolean> = [];

  return {
    listeners,
    cueChange: null as ((change: { activeCue: SubtitleCue | null }) => void) | null,
    sendMessage: vi.fn(async (message: { type?: string }) => {
      if (message.type === 'content.getState') {
        return { settings: DEFAULT_EXTENSION_SETTINGS, subtitleState: { type: 'no-track' } };
      }

      return undefined;
    }),
  };
});

vi.mock('wxt/browser', () => ({
  browser: {
    runtime: {
      sendMessage: mocks.sendMessage,
      onMessage: {
        addListener(listener: (message: unknown, sender: unknown, sendResponse: (response?: unknown) => void) => boolean) {
          mocks.listeners.push(listener);
        },
        removeListener(listener: (message: unknown, sender: unknown, sendResponse: (response?: unknown) => void) => boolean) {
          const index = mocks.listeners.indexOf(listener);
          if (index >= 0) mocks.listeners.splice(index, 1);
        },
      },
    },
  },
}));

vi.mock('../utils/youtube', () => ({
  parseYoutubePage: () => ({
    supported: true,
    videoId: 'video-1',
    url: 'https://www.youtube.com/watch?v=video-1',
    mediaKind: 'video',
  }),
}));

vi.mock('../utils/webvtt-track', () => ({
  bindWebVttTrackToVideo: vi.fn((options: {
    track: { cues: SubtitleCue[] };
    onCueChange: (change: { activeCue: SubtitleCue | null }) => void;
  }) => {
    mocks.cueChange = options.onCueChange;
    options.onCueChange({ activeCue: options.track.cues[0] ?? null });
    return vi.fn();
  }),
  buildWebVttFromCues: vi.fn(() => 'WEBVTT\n'),
}));

describe('content study pause ownership', () => {
  beforeEach(() => {
    mocks.listeners.length = 0;
    mocks.cueChange = null;
    mocks.sendMessage.mockClear();
    vi.stubGlobal('defineContentScript', (config: unknown) => config);
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  it('keeps queued own pause events, handles hover and focus overlap, and respects manual pause', async () => {
    const { default: contentScript } = await import('../entrypoints/content');
    const video = document.createElement('video');
    setVideoRect(video);
    document.body.append(video);

    let pauseCalls = 0;
    let playCalls = 0;
    let queuedPauseEvents = 0;
    const playResolvers: Array<() => void> = [];
    setVideoPaused(video, false);
    Object.defineProperty(video, 'ended', { configurable: true, value: false });
    video.pause = () => {
      pauseCalls += 1;
      setVideoPaused(video, true);
      queuedPauseEvents += 1;
    };
    video.play = () => {
      playCalls += 1;
      setVideoPaused(video, false);
      return new Promise<void>((resolve) => playResolvers.push(resolve));
    };

    let invalidate: (() => void) | null = null;
    (contentScript as { main: (ctx: { onInvalidated: (callback: () => void) => void }) => void }).main({
      onInvalidated(callback) {
        invalidate = callback;
      },
    });
    await Promise.resolve();
    await Promise.resolve();

    const onMessage = mocks.listeners[0];
    expect(onMessage).toBeDefined();
    onMessage({ type: 'background.subtitleStateChanged', subtitleState: readySubtitleState() }, {}, () => {});
    expect(mocks.cueChange).toBeTruthy();
    mocks.cueChange!({ activeCue: null });

    const token = document
      .querySelector('#tse-overlay-host')
      ?.shadowRoot
      ?.querySelector<HTMLButtonElement>('[data-token-index]');
    expect(token).toBeTruthy();

    token!.dispatchEvent(new Event('pointerenter'));
    token!.focus();
    expect(pauseCalls).toBe(1);
    expect(queuedPauseEvents).toBe(1);
    token!.dispatchEvent(new Event('pointerleave'));
    flushPause(video, () => { queuedPauseEvents -= 1; });
    expect(playCalls).toBe(0);

    const outside = document.createElement('button');
    document.body.append(outside);
    outside.focus();
    expect(playCalls).toBe(1);
    expect(playResolvers).toHaveLength(1);

    token!.focus();
    expect(pauseCalls).toBe(2);
    flushPause(video, () => { queuedPauseEvents -= 1; });
    expect(queuedPauseEvents).toBe(0);
    playResolvers.shift()!();
    await Promise.resolve();
    vi.advanceTimersByTime(1800);
    expect(document.querySelector('#tse-overlay-host')?.shadowRoot?.querySelector('[data-token-index]')).toBeTruthy();

    setVideoPaused(video, false);
    video.dispatchEvent(new Event('play'));
    setVideoPaused(video, true);
    video.dispatchEvent(new Event('pause'));
    outside.focus();
    await Promise.resolve();
    expect(playCalls).toBe(1);
    expect(queuedPauseEvents).toBe(0);

    (invalidate as unknown as () => void)();
    outside.remove();
    video.remove();
  });
});

function flushPause(video: HTMLVideoElement, consume: () => void): void {
  consume();
  video.dispatchEvent(new Event('pause'));
}

function setVideoPaused(video: HTMLVideoElement, paused: boolean): void {
  Object.defineProperty(video, 'paused', { configurable: true, value: paused });
}

function setVideoRect(video: HTMLVideoElement): void {
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
}

function readySubtitleState(): { type: 'ready'; track: TrackResponse } {
  return {
    type: 'ready',
    track: {
      trackId: 'track-1',
      jobId: 'job-1',
      youtubeVideoId: 'video-1',
      sourceLanguage: 'spa',
      targetLanguage: 'fra',
      generatedAt: '2026-05-02T00:00:00Z',
      expiresAt: '2026-06-01T00:00:00Z',
      webVtt: 'WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nhola\n',
      cues: [
        {
          cueId: 'cue-1',
          index: 0,
          startMs: 0,
          endMs: 2000,
          sourceText: 'hola',
          translatedText: 'Bonjour',
          tokens: [{
            index: 0,
            text: 'hola',
            normalizedText: 'hola',
            lemma: 'hola',
            root: 'hol',
            partOfSpeech: 'interjection',
            gloss: 'hello',
            romanization: 'o-la',
            usageNote: 'Common greeting.',
          }],
        },
      ],
    },
  };
}
