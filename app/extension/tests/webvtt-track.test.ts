import { afterEach, describe, expect, it, vi } from 'vitest';

import { bindWebVttTrackToVideo } from '../utils/webvtt-track';
import type { TrackResponse } from '../utils/contracts';

describe('bindWebVttTrackToVideo', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('attaches a hidden WebVTT track and updates from cuechange events', () => {
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-track');
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const video = new FakeVideoElement();
    const changes: (string | null)[] = [];

    const cleanup = bindWebVttTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track: trackResponse(),
      onCueChange: (change) => changes.push(change.activeSourceText),
    });

    const trackElement = video.appendedTrack!;
    expect(trackElement.kind).toBe('subtitles');
    expect(trackElement.label).toBe('AI subtitles');
    expect(trackElement.srclang).toBe('ar');
    expect(trackElement.src).toBe('blob:test-track');
    expect(trackElement.track.mode).toBe('hidden');
    expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
    expect(changes).toEqual([null]);

    trackElement.track.activeCues = new FakeCueList([new FakeTextCue(0.5, 2.1, 'first transcript segment')]);
    trackElement.track.dispatchEvent(new Event('cuechange'));

    expect(changes).toEqual([null, 'first transcript segment']);

    cleanup();
    trackElement.track.activeCues = new FakeCueList([new FakeTextCue(2.4, 4.0, 'second transcript segment')]);
    trackElement.track.dispatchEvent(new Event('cuechange'));

    expect(changes).toEqual([null, 'first transcript segment']);
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
});

function trackResponse(): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'ar',
    targetLanguage: 'en',
    generatedAt: '2026-05-02T00:00:00Z',
    expiresAt: '2026-06-01T00:00:00Z',
    webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n",
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 500,
        endMs: 2100,
        sourceText: 'first transcript segment',
        translatedText: 'first transcript segment',
        tokens: [],
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
