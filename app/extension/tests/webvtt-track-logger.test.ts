import { describe, expect, it } from 'vitest';

import type { TrackResponse } from '../utils/contracts';
import { createWebVttTrackLogger, type WebVttTrackLogger } from '../utils/webvtt-track-logger';

describe('createWebVttTrackLogger', () => {
  it('logs missing video diagnostics', () => {
    const { logger, warnings } = createCapturingLogger();

    logger.videoMissing(trackResponse());

    expect(warnings).toEqual([
      [
        'extension.webvtt_track_video_missing',
        {
          type: 'video_missing',
          trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
          youtubeVideoId: 'dQw4w9WgXcQ',
        },
      ],
    ]);
  });

  it('does not warn when the subtitle track ends before the video', () => {
    const { logger, infos, warnings } = createCapturingLogger();
    const video = { duration: 20 };
    const textTrack = {
      cues: new FakeCueList([new FakeTextCue(0, 3, 'first'), new FakeTextCue(6, 7, 'second')]),
    };

    logger.trackLoaded({
      video: video as HTMLVideoElement,
      textTrack: textTrack as unknown as TextTrack,
      track: trackResponse(),
    });

    expect(infos).toEqual([]);
    expect(warnings).toEqual([]);
  });

  it('logs track overrun diagnostics as info after track load', () => {
    const { logger, infos, warnings } = createCapturingLogger();
    const video = { duration: 20 };
    const textTrack = {
      cues: new FakeCueList([new FakeTextCue(0, 3, 'first'), new FakeTextCue(6, 30, 'second')]),
    };

    logger.trackLoaded({
      video: video as HTMLVideoElement,
      textTrack: textTrack as unknown as TextTrack,
      track: trackResponse(),
    });

    expect(warnings).toEqual([]);
    expect(infos).toEqual([
      [
        'extension.webvtt_track_duration_overrun',
        {
          type: 'duration_overrun',
          trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
          youtubeVideoId: 'dQw4w9WgXcQ',
          videoDurationSeconds: 20,
          trackDurationSeconds: 30,
          deltaSeconds: 10,
        },
      ],
    ]);
  });

  it('skips duration mismatch diagnostics when the duration delta is within tolerance', () => {
    const { logger, warnings } = createCapturingLogger();
    const video = { duration: 20 };
    const textTrack = {
      cues: new FakeCueList([new FakeTextCue(0, 3, 'first'), new FakeTextCue(6, 16, 'second')]),
    };

    logger.trackLoaded({
      video: video as HTMLVideoElement,
      textTrack: textTrack as unknown as TextTrack,
      track: trackResponse(),
    });

    expect(warnings).toEqual([]);
  });

  it('logs WebVTT track load failures', () => {
    const { logger, warnings } = createCapturingLogger();

    logger.trackLoadError(trackResponse());

    expect(warnings).toEqual([
      [
        'extension.webvtt_track_track_load_error',
        {
          type: 'track_load_error',
          trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
          youtubeVideoId: 'dQw4w9WgXcQ',
        },
      ],
    ]);
  });
});

function createCapturingLogger(): { logger: WebVttTrackLogger; infos: unknown[][]; warnings: unknown[][] } {
  const infos: unknown[][] = [];
  const warnings: unknown[][] = [];
  const logger = createWebVttTrackLogger({
    info(...args: unknown[]): void {
      infos.push(args);
    },
    warn(...args: unknown[]): void {
      warnings.push(args);
    },
  } as Pick<Console, 'info' | 'warn'>);

  return { logger, infos, warnings };
}

function trackResponse(): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'ara',
    targetLanguage: 'eng',
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
