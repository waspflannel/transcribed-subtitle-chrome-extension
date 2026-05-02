import { describe, expect, it } from 'vitest';

import { bindSubtitleTrackToVideo, findActiveCue, type SubtitleCueChange } from '../utils/subtitle-sync';
import type { SubtitleCue, TrackResponse } from '../utils/contracts';

describe('findActiveCue', () => {
  it('selects cues with inclusive starts and exclusive ends', () => {
    const cues = subtitleCues();

    expect(findActiveCue(cues, 1.2)?.cueId).toBe('cue-0001');
    expect(findActiveCue(cues, 4.199)?.cueId).toBe('cue-0001');
    expect(findActiveCue(cues, 4.2)).toBeNull();
    expect(findActiveCue(cues, 5.0)?.cueId).toBe('cue-0002');
  });

  it('returns no cue for gaps, negative time, and invalid time', () => {
    const cues = subtitleCues();

    expect(findActiveCue(cues, -1)).toBeNull();
    expect(findActiveCue(cues, Number.NaN)).toBeNull();
    expect(findActiveCue(cues, 4.5)).toBeNull();
    expect(findActiveCue(cues, 99)).toBeNull();
  });
});

describe('bindSubtitleTrackToVideo', () => {
  it('updates only when playback events change the active cue', () => {
    const video = new FakeVideoElement();
    const changes: SubtitleCueChange[] = [];
    const cleanup = bindSubtitleTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track: trackResponse(),
      onCueChange: (change) => changes.push(change),
    });

    expect(changes).toHaveLength(1);
    expect(changes[0]).toMatchObject({ cue: null, reason: 'initial' });

    video.currentTime = 1.2;
    video.dispatchEvent(new Event('timeupdate'));
    video.currentTime = 2.0;
    video.dispatchEvent(new Event('timeupdate'));
    video.currentTime = 5.0;
    video.dispatchEvent(new Event('seeking'));
    video.paused = false;
    video.dispatchEvent(new Event('play'));

    expect(changes.map((change) => change.cue?.cueId ?? null)).toEqual([null, 'cue-0001', 'cue-0002']);
    expect(changes.map((change) => change.reason)).toEqual(['initial', 'timeupdate', 'seeking']);
    expect(video.animationFrames).toHaveLength(1);

    cleanup();
  });

  it('emits structured diagnostics for duration mismatch and significant cue gaps', () => {
    const video = new FakeVideoElement();
    video.duration = 20;
    video.currentTime = 4.75;
    const diagnostics: unknown[] = [];

    const cleanup = bindSubtitleTrackToVideo({
      video: video as unknown as HTMLVideoElement,
      track: trackResponse([
        cue('cue-0001', 0, 0, 3000),
        cue('cue-0002', 1, 6000, 7000),
      ]),
      onCueChange: () => undefined,
      onDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
    });

    expect(diagnostics).toEqual([
      expect.objectContaining({
        type: 'duration_mismatch',
        videoDurationSeconds: 20,
        trackDurationSeconds: 7,
        deltaSeconds: 13,
      }),
      expect.objectContaining({
        type: 'missing_cue_gap',
        previousCueId: 'cue-0001',
        nextCueId: 'cue-0002',
        gapMs: 3000,
      }),
    ]);

    cleanup();
  });
});

function subtitleCues(): SubtitleCue[] {
  return [
    {
      cueId: 'cue-0001',
      index: 0,
      startMs: 1200,
      endMs: 4200,
      sourceText: 'marhaban',
      translatedText: 'hello',
      tokens: [],
    },
    {
      cueId: 'cue-0002',
      index: 1,
      startMs: 5000,
      endMs: 6500,
      sourceText: 'ahlan',
      translatedText: 'welcome',
      tokens: [],
    },
  ];
}

function trackResponse(cues: [SubtitleCue, ...SubtitleCue[]] = subtitleCues() as [SubtitleCue, ...SubtitleCue[]]): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'ar',
    targetLanguage: 'en',
    generatedAt: '2026-05-02T00:00:00Z',
    expiresAt: '2026-06-01T00:00:00Z',
    cues,
  };
}

function cue(cueId: string, index: number, startMs: number, endMs: number): SubtitleCue {
  return {
    cueId,
    index,
    startMs,
    endMs,
    sourceText: `source ${index}`,
    translatedText: `source ${index}`,
    tokens: [],
  };
}

class FakeVideoElement extends EventTarget {
  public currentTime = 0;

  public duration = 10;

  public paused = true;

  public ended = false;

  public animationFrames: FrameRequestCallback[] = [];

  public ownerDocument = {
    defaultView: {
      requestAnimationFrame: (callback: FrameRequestCallback): number => {
        this.animationFrames.push(callback);

        return this.animationFrames.length;
      },
      cancelAnimationFrame: () => undefined,
    },
  };
}
