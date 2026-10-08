// @vitest-environment jsdom

import { afterEach, assert, beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { SubtitleCue, TrackResponse } from '../utils/contracts';

const mocks = vi.hoisted(() => {
  const listeners: Array<(message: unknown, sender: unknown, sendResponse: (response?: unknown) => void) => boolean> = [];

  return {
    listeners,
    settingsWatch: null as ((settings: typeof DEFAULT_EXTENSION_SETTINGS) => void) | null,
    cueChange: null as ((change: { activeCue: SubtitleCue | null }) => void) | null,
    sendMessage: vi.fn(async (message: { type?: string }) => {
      if (message.type === 'content.getState') {
        return { settings: DEFAULT_EXTENSION_SETTINGS, subtitleState: { type: 'no-track' } };
      }

      return undefined;
    }),
  };
});

function messageListener() {
  const listener = mocks.listeners[0];
  assert(listener, 'Content script must register a message listener');
  return listener;
}

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

vi.mock('../utils/settings', () => ({
  watchExtensionSettings(callback: (settings: typeof DEFAULT_EXTENSION_SETTINGS) => void) {
    mocks.settingsWatch = callback;
    return () => { mocks.settingsWatch = null; };
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
  it('keeps native timing and the active cue during annotation-only partial revisions', async () => {
    const { default: contentScript } = await import('../entrypoints/content');
    const { bindWebVttTrackToVideo } = await import('../utils/webvtt-track');
    const bind = vi.mocked(bindWebVttTrackToVideo);
    bind.mockClear();
    const video = document.createElement('video');
    setVideoRect(video);
    document.body.append(video);
    let invalidate!: () => void;
    (contentScript as any).main({ onInvalidated: (callback: () => void) => { invalidate = callback; } });
    await Promise.resolve();
    await Promise.resolve();
    const track = readySubtitleState().track;
    const partial = { jobId: track.jobId, youtubeVideoId: track.youtubeVideoId, sourceLanguage: 'spa', targetLanguage: 'fra',
      revision: 1, cues: track.cues.map(({ tokens, ...cue }) => cue), readyThroughMs: 0 };
    const state = { type: 'loading', status: 'running', youtubeVideoId: 'video-1', jobId: 'job-1', message: 'Generating', stage: 'tokenizing', progressPercent: 50, partialTrack: partial };
    const onMessage = messageListener();
    const publish = (value: unknown) => onMessage({ type: 'background.subtitleStateChanged', subtitleState: value }, {}, () => {});
    onMessage({ type: 'background.settingsChanged', settings: { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true } }, {}, () => {});
    publish(state);
    const initialBind = bind.mock.calls[0]?.[0];
    assert(initialBind, 'Partial track must bind to the video');
    const annotation = { ...state, partialTrack: { ...partial, revision: 2, cues: [{ ...partial.cues[0], translatedText: 'Salut' }] } };
    publish(annotation);
    expect(bind).toHaveBeenCalledTimes(1);
    expect(document.getElementById('tse-overlay-host')?.shadowRoot?.textContent).toContain('Salut');
    initialBind.onCueChange({ activeCue: partial.cues[0] } as any);
    expect(document.getElementById('tse-overlay-host')?.shadowRoot?.textContent).toContain('Salut');
    publish({ ...annotation, partialTrack: { ...annotation.partialTrack, revision: 3,
      cues: [...annotation.partialTrack.cues, { ...partial.cues[0], cueId: 'cue-2', startMs: 2000, endMs: 3000 }] } });
    expect(bind).toHaveBeenCalledTimes(2);
    invalidate();
  });

  it('coalesces scroll bursts without rebuilding content and skips hidden overlay positioning', async () => {
    const { default: contentScript } = await import('../entrypoints/content');
    const { OverlayShell } = await import('../utils/overlay');
    const video = document.createElement('video');
    setVideoRect(video);
    document.body.append(video);
    let invalidate!: () => void;
    (contentScript as any).main({ onInvalidated: (callback: () => void) => { invalidate = callback; } });
    await Promise.resolve();
    await Promise.resolve();
    const onMessage = messageListener();
    onMessage({ type: 'background.subtitleStateChanged', subtitleState: readySubtitleState() }, {}, () => {});
    const geometry = vi.spyOn(video, 'getBoundingClientRect');
    const update = vi.spyOn(OverlayShell.prototype, 'update');
    const position = vi.spyOn(OverlayShell.prototype, 'position');
    for (let index = 0; index < 20; index += 1) window.dispatchEvent(new Event('scroll'));
    expect(position).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(17);
    expect(position).toHaveBeenCalledTimes(1);
    expect(update).not.toHaveBeenCalled();
    expect(geometry).toHaveBeenCalledTimes(1);
    onMessage({ type: 'background.settingsChanged', settings: { ...DEFAULT_EXTENSION_SETTINGS, overlayVisible: false } }, {}, () => {});
    const host = document.getElementById('tse-overlay-host')!;
    const left = host.style.left;
    video.getBoundingClientRect = () => ({ left: 300, right: 900, top: 100, bottom: 400, width: 600, height: 300 } as DOMRect);
    window.dispatchEvent(new Event('scroll'));
    await vi.advanceTimersByTimeAsync(17);
    expect(host.style.left).toBe(left);
    expect(host.style.display).toBe('none');
    update.mockRestore();
    position.mockRestore();
    invalidate();
  });

  it('applies saved settings from page entry even when a subtitle push arrives first', async () => {
    const { default: contentScript } = await import('../entrypoints/content');
    const video = document.createElement('video');
    setVideoRect(video);
    document.body.append(video);
    let resolveState!: (state: any) => void;
    mocks.sendMessage.mockImplementationOnce(() => new Promise((resolve) => { resolveState = resolve; }));
    let invalidate!: () => void;
    (contentScript as any).main({ onInvalidated: (callback: () => void) => { invalidate = callback; } });
    const state = readySubtitleState();
    messageListener()({ type: 'background.subtitleStateChanged', subtitleState: state }, {}, () => {});
    resolveState({ settings: { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true }, subtitleState: state });
    await Promise.resolve();
    await Promise.resolve();
    expect(document.getElementById('tse-overlay-host')?.shadowRoot?.textContent).toContain('Bonjour');
    invalidate();
  });

  it('follows settings saved from another tab or the panel', async () => {
    const { default: contentScript } = await import('../entrypoints/content');
    const video = document.createElement('video');
    setVideoRect(video);
    document.body.append(video);
    let invalidate!: () => void;
    (contentScript as any).main({ onInvalidated: (callback: () => void) => { invalidate = callback; } });
    await Promise.resolve();
    await Promise.resolve();
    messageListener()({ type: 'background.subtitleStateChanged', subtitleState: readySubtitleState() }, {}, () => {});
    expect(document.getElementById('tse-overlay-host')?.shadowRoot?.textContent).not.toContain('Bonjour');
    mocks.settingsWatch!({ ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true });
    expect(document.getElementById('tse-overlay-host')?.shadowRoot?.textContent).toContain('Bonjour');
    invalidate();
    expect(mocks.settingsWatch).toBeNull();
  });

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

    const onMessage = messageListener();
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

  it('binds a player that mounts after the track before handling a transcript jump', async () => {
    const { default: contentScript } = await import('../entrypoints/content');
    let invalidate: (() => void) | null = null;

    (contentScript as { main: (ctx: { onInvalidated: (callback: () => void) => void }) => void }).main({
      onInvalidated(callback) {
        invalidate = callback;
      },
    });
    await Promise.resolve();
    await Promise.resolve();

    const onMessage = messageListener();
    expect(onMessage).toBeDefined();
    onMessage({ type: 'background.subtitleStateChanged', subtitleState: readySubtitleState(500) }, {}, () => {});

    const video = document.createElement('video');
    setVideoRect(video);
    let currentTime = 0;
    Object.defineProperty(video, 'currentTime', {
      configurable: true,
      get: () => currentTime,
      set: (value: number) => { currentTime = value; },
    });
    video.play = vi.fn(() => Promise.resolve());
    document.body.append(video);

    onMessage({
      type: 'background.seekToCue',
      youtubeVideoId: 'video-1',
      trackId: 'stale-track',
      cueId: 'cue-1',
      mode: 'jump',
    }, {}, () => {});
    expect(currentTime).toBe(0);
    expect(video.play).not.toHaveBeenCalled();

    const response = vi.fn();
    onMessage({
      type: 'background.seekToCue',
      youtubeVideoId: 'video-1',
      trackId: 'track-1',
      cueId: 'cue-1',
      mode: 'jump',
    }, {}, response);

    expect(currentTime).toBe(0.5);
    expect(video.play).toHaveBeenCalledTimes(1);
    expect(response).toHaveBeenCalledWith({ ok: true });

    (invalidate as unknown as () => void)();
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

function readySubtitleState(startMs = 0): { type: 'ready'; track: TrackResponse } {
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
          startMs,
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
