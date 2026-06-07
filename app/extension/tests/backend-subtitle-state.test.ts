import { describe, expect, it, vi } from 'vitest';

import { stateWithBackendProgress } from '../utils/backend-subtitle-state';
import type { JobResponse, SubtitleJobHistoryItem, TrackResponse } from '../utils/contracts';
import type { SubtitleState } from '../utils/messages';
import type { YoutubePageInfo } from '../utils/youtube';

const pageStatus: YoutubePageInfo = {
  supported: true,
  videoId: 'dQw4w9WgXcQ',
  url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
  mediaKind: 'video',
};

describe('backend subtitle state helpers', () => {
  it('recovers a ready subtitle state from completed job history by loading job details', async () => {
    const historyJob = jobHistory({ status: 'completed' });
    const track = trackResponse();
    const resolver = vi.fn(async (): Promise<JobResponse> => completedJobResponse(track));

    await expect(
      stateWithBackendProgress({ type: 'no-track' }, pageStatus, [historyJob], resolver),
    ).resolves.toEqual({
      type: 'ready',
      track,
    });
    expect(resolver).toHaveBeenCalledWith(historyJob);
  });

  it('keeps local ready state instead of reloading completed history', async () => {
    const track = trackResponse();
    const resolver = vi.fn(async (): Promise<JobResponse> => completedJobResponse(track));
    const localState: SubtitleState = { type: 'ready', track };

    await expect(
      stateWithBackendProgress(localState, pageStatus, [jobHistory({ status: 'completed' })], resolver),
    ).resolves.toBe(localState);
    expect(resolver).not.toHaveBeenCalled();
  });

  it('shows backend running progress while generation is still active', async () => {
    await expect(
      stateWithBackendProgress({ type: 'no-track' }, pageStatus, [
        jobHistory({ status: 'running', stage: 'tokenizing', progressPercent: 65 }),
      ]),
    ).resolves.toMatchObject({
      type: 'loading',
      message: 'Tokenizing subtitles...',
      stage: 'tokenizing',
      progressPercent: 65,
    });
  });

  it('shows the audio optimization stage while prepared audio is being improved', async () => {
    await expect(
      stateWithBackendProgress({ type: 'no-track' }, pageStatus, [
        jobHistory({ status: 'running', stage: 'optimizing-audio', progressPercent: 35 }),
      ]),
    ).resolves.toMatchObject({
      type: 'loading',
      message: 'Optimizing audio...',
      stage: 'optimizing-audio',
      progressPercent: 35,
    });
  });

  it('prefers an active backend job over older completed history for the same video', async () => {
    const resolver = vi.fn(async (): Promise<JobResponse> => completedJobResponse(trackResponse()));

    await expect(
      stateWithBackendProgress(
        { type: 'no-track' },
        pageStatus,
        [
          jobHistory({ status: 'completed' }),
          jobHistory({ status: 'running', stage: 'romanizing', progressPercent: 82 }),
        ],
        resolver,
      ),
    ).resolves.toMatchObject({
      type: 'loading',
      stage: 'romanizing',
      progressPercent: 82,
    });
    expect(resolver).not.toHaveBeenCalled();
  });

  it('shows failed backend jobs as public extension errors', async () => {
    await expect(
      stateWithBackendProgress({ type: 'no-track' }, pageStatus, [
        jobHistory({
          status: 'failed',
          errorCode: 'rate_limited',
          message: 'Provider limit exceeded.',
        }),
      ]),
    ).resolves.toMatchObject({
      type: 'error',
      message: 'Subtitle generation is temporarily rate limited. Wait a minute and try again.',
    });
  });
});

function jobHistory(overrides: Partial<SubtitleJobHistoryItem>): SubtitleJobHistoryItem {
  return {
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    status: 'running',
    startedAt: '2026-05-20T00:00:00Z',
    lastUpdatedAt: '2026-05-20T00:01:00Z',
    stage: 'transcribing',
    progressPercent: 45,
    sourceLanguage: 'auto',
    targetLanguage: 'eng',
    ...overrides,
    enrichmentMode: overrides.enrichmentMode ?? 'on_demand',
    includeRomanization: overrides.includeRomanization ?? true,
    includeTranslation: overrides.includeTranslation ?? false,
  };
}

function completedJobResponse(track: TrackResponse): JobResponse {
  return {
    jobId: track.jobId,
    youtubeVideoId: track.youtubeVideoId,
    sourceLanguage: track.sourceLanguage,
    targetLanguage: track.targetLanguage,
    enrichmentMode: 'on_demand',
    includeRomanization: true,
    includeTranslation: false,
    status: 'completed',
    stage: 'finalizing',
    progressPercent: 100,
    track,
    createdAt: '2026-05-20T00:00:00Z',
    updatedAt: '2026-05-20T00:01:00Z',
    expiresAt: track.expiresAt,
  };
}

function trackResponse(): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'auto',
    targetLanguage: 'eng',
    generatedAt: '2026-05-20T00:01:00Z',
    expiresAt: '2026-06-20T00:01:00Z',
    webVtt: 'WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nhello',
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 0,
        endMs: 1000,
        sourceText: 'hello',
        translatedText: 'hello',
        tokens: [
          {
            index: 0,
            text: 'hello',
            normalizedText: 'hello',
          },
        ],
      },
    ],
  };
}
