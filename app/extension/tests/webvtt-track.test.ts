import { afterEach, describe, expect, it, vi } from 'vitest';

import { bindWebVttTrackToVideo, buildWebVttFromCues, offsetTrackTiming } from '../utils/webvtt-track';
import type { TrackResponse } from '../utils/contracts';

describe('bindWebVttTrackToVideo', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('attaches a hidden WebVTT track and updates from cuechange events', () => {
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-track');
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const video = new FakeVideoElement();
    const changes: { cueId: string | null }[] = [];

    const cleanup = bindWebVttTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track: trackResponse(),
      onCueChange: (change) =>
        changes.push({
          cueId: change.activeCue?.cueId ?? null,
        }),
    });

    const trackElement = video.appendedTrack!;
    expect(trackElement.kind).toBe('subtitles');
    expect(trackElement.label).toBe('AI subtitles');
    expect(trackElement.srclang).toBe('spa');
    expect(trackElement.src).toBe('blob:test-track');
    expect(trackElement.track.mode).toBe('hidden');
    expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
    expect(changes).toEqual([{ cueId: null }]);

    trackElement.track.activeCues = new FakeCueList([new FakeTextCue(0.5, 2.1, 'first transcript segment', 'cue-0001')]);
    trackElement.track.dispatchEvent(new Event('cuechange'));

    expect(changes).toEqual([
      { cueId: null },
      { cueId: 'cue-0001' },
    ]);

    cleanup();
    trackElement.track.activeCues = new FakeCueList([new FakeTextCue(2.4, 4.0, 'second transcript segment')]);
    trackElement.track.dispatchEvent(new Event('cuechange'));

    expect(changes).toEqual([
      { cueId: null },
      { cueId: 'cue-0001' },
    ]);
    expect(trackElement.removed).toBe(true);
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:test-track');
  });

  it('notifies the logger after the track loads', () => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-track');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const video = new FakeVideoElement();
    const track = trackResponse();
    const logger = {
      trackLoaded: vi.fn(),
      trackLoadError: vi.fn(),
    };

    const cleanup = bindWebVttTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track,
      onCueChange: () => undefined,
      logger,
    });

    video.appendedTrack!.dispatchEvent(new Event('load'));

    expect(logger.trackLoaded).toHaveBeenCalledWith({
      video,
      textTrack: video.appendedTrack!.track,
      track,
    });

    cleanup();
  });

  it('offsets WebVTT and cue timings for manual sync adjustment', () => {
    const shifted = offsetTrackTiming(trackResponse(), 4.5);

    expect(shifted.webVtt).toContain('00:00:05.000 --> 00:00:06.600');
    expect(shifted.cues[0].startMs).toBe(5000);
    expect(shifted.cues[0].endMs).toBe(6600);
  });

  it('clamps negative offsets at zero while preserving positive cue duration', () => {
    const shifted = offsetTrackTiming(trackResponse(), -2);

    expect(shifted.webVtt).toContain('00:00:00.000 --> 00:00:00.100');
    expect(shifted.cues[0].startMs).toBe(0);
    expect(shifted.cues[0].endMs).toBe(100);
  });

  it('matches the active VTT cue by its stable id, not timestamp proximity', () => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-track');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const video = new FakeVideoElement();
    const changes: { cueId: string | null }[] = [];

    bindWebVttTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track: trackWithTwoAdjacentCues(),
      onCueChange: (change) => changes.push({ cueId: change.activeCue?.cueId ?? null }),
    });

    // Two cues 20ms apart — within the old ±25ms tolerance. Id mapping must
    // pick the right one instead of always the first.
    video.appendedTrack!.track.activeCues = new FakeCueList([new FakeTextCue(0.52, 1.8, 'b', 'cue-0002')]);
    video.appendedTrack!.track.dispatchEvent(new Event('cuechange'));

    expect(changes).toContainEqual({ cueId: 'cue-0002' });
  });

  it('drops cues shifted before the timeline and keeps the remainder disjoint and ordered', () => {
    const shifted = offsetTrackTiming(trackWithEarlyAndLateCue(), -32);

    // First cue (500-2100ms) is dropped entirely; second (60000-62000ms)
    // shifts to 28000-30000ms and keeps the cue id and positive duration.
    expect(shifted.cues).toHaveLength(1);
    expect(shifted.cues[0].cueId).toBe('cue-0002');
    expect(shifted.cues[0].startMs).toBe(28_000);
    expect(shifted.cues[0].endMs).toBe(30_000);
    expect(shifted.webVtt).toContain('00:00:28.000 --> 00:00:30.000');
    expect(shifted.webVtt).toContain('cue-0002');
  });

  it('notifies the logger when the WebVTT track fails', () => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-track');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const video = new FakeVideoElement();
    const track = trackResponse();
    const logger = {
      trackLoaded: vi.fn(),
      trackLoadError: vi.fn(),
    };

    const cleanup = bindWebVttTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track,
      onCueChange: () => undefined,
      logger,
    });

    video.appendedTrack!.dispatchEvent(new Event('error'));

    expect(logger.trackLoadError).toHaveBeenCalledWith(track);

    cleanup();
  });

  it('binds partial cues from a still-running job and builds their WebVTT locally', () => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-track');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const video = new FakeVideoElement();
    const partialCues = [
      { cueId: 'cue-0001', index: 0, startMs: 500, endMs: 2100, sourceText: 'hola a todos' },
      { cueId: 'cue-0002', index: 1, startMs: 2400, endMs: 4000, sourceText: 'bienvenidos', translatedText: 'welcome' },
    ];
    const webVtt = buildWebVttFromCues(partialCues);
    const changes: { translatedText?: string }[] = [];

    expect(webVtt).toContain('WEBVTT');
    expect(webVtt).toContain('cue-0001\n00:00:00.500 --> 00:00:02.100\nhola a todos');

    const cleanup = bindWebVttTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track: {
        youtubeVideoId: 'dQw4w9WgXcQ',
        sourceLanguage: 'spa',
        webVtt,
        cues: partialCues,
      },
      onCueChange: (change) => changes.push({ translatedText: change.activeCue?.translatedText }),
    });

    video.appendedTrack!.track.activeCues = new FakeCueList([new FakeTextCue(2.4, 4.0, 'bienvenidos', 'cue-0002')]);
    video.appendedTrack!.track.dispatchEvent(new Event('cuechange'));

    expect(changes).toContainEqual({ translatedText: 'welcome' });

    cleanup();
  });
});

function trackResponse(): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'spa',
    targetLanguage: 'fra',
    generatedAt: '2026-05-02T00:00:00Z',
    expiresAt: '2026-06-01T00:00:00Z',
    webVtt: "WEBVTT\n\ncue-0001\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n",
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 500,
        endMs: 2100,
        sourceText: 'first transcript segment',
        translatedText: 'first transcript segment',
        tokens: [
          {
            index: 0,
            text: 'first',
            normalizedText: 'first',
          },
        ],
      },
    ],
  };
}

function trackWithTwoAdjacentCues(): TrackResponse {
  return {
    ...trackResponse(),
    webVtt:
      'WEBVTT\n\ncue-0001\n00:00:00.500 --> 00:00:01.800\na\n\ncue-0002\n00:00:00.520 --> 00:00:02.000\nb\n',
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 500,
        endMs: 1800,
        sourceText: 'a',
        translatedText: 'a',
        tokens: [{ index: 0, text: 'a', normalizedText: 'a' }],
      },
      {
        cueId: 'cue-0002',
        index: 1,
        startMs: 520,
        endMs: 2000,
        sourceText: 'b',
        translatedText: 'b',
        tokens: [{ index: 0, text: 'b', normalizedText: 'b' }],
      },
    ],
  };
}

function trackWithEarlyAndLateCue(): TrackResponse {
  return {
    ...trackResponse(),
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 500,
        endMs: 2100,
        sourceText: 'early',
        translatedText: 'early',
        tokens: [{ index: 0, text: 'early', normalizedText: 'early' }],
      },
      {
        cueId: 'cue-0002',
        index: 1,
        startMs: 60000,
        endMs: 62000,
        sourceText: 'late',
        translatedText: 'late',
        tokens: [{ index: 0, text: 'late', normalizedText: 'late' }],
      },
    ],
  };
}

class FakeVideoElement extends EventTarget {
  public duration = 10;

  public appendedTrack: FakeTrackElement | null = null;

  public ownerDocument = {
    createElement: (tagName: string): FakeTrackElement => {
      if (tagName !== 'track') {
        throw new Error(`Unsupported element: ${tagName}`);
      }

      return new FakeTrackElement();
    },
  };

  public append(trackElement: FakeTrackElement): void {
    this.appendedTrack = trackElement;
  }
}

class FakeTrackElement extends EventTarget {
  public kind = '';

  public label = '';

  public srclang = '';

  public src = '';

  public removed = false;

  public track = new FakeTextTrack();

  public remove(): void {
    this.removed = true;
  }
}

class FakeTextTrack extends EventTarget {
  public mode: TextTrackMode = 'disabled';

  public activeCues: FakeCueList | null = null;

  public cues: FakeCueList | null = null;
}

class FakeTextCue {
  public constructor(
    public readonly startTime: number,
    public readonly endTime: number,
    public readonly text: string,
    public readonly id: string = '',
  ) {}
}

class FakeCueList {
  [index: number]: FakeTextCue;

  public readonly length: number;

  public constructor(cues: FakeTextCue[]) {
    this.length = cues.length;
    cues.forEach((cue, index) => {
      this[index] = cue;
    });
  }

  public item(index: number): FakeTextCue | null {
    return this[index] ?? null;
  }
}
