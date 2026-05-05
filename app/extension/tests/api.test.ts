import { describe, expect, it, vi } from 'vitest';

import { SubtitleApiClient, SubtitleApiError, publicSubtitleErrorMessage } from '../utils/api';
import type { CreateSubtitleJobRequest, JobResponse, TrackResponse } from '../utils/contracts';

const installId = 'install_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

describe('SubtitleApiClient', () => {
  it('creates subtitle jobs with the extension install header', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'ar',
      targetLanguage: 'en',
      track: trackResponse(),
      createdAt: '2026-04-30T00:00:00Z',
      updatedAt: '2026-04-30T00:00:00Z',
      expiresAt: '2026-05-30T00:00:00Z',
    };
    const fetchMock = vi.fn(async () => jsonResponse(jobResponse, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    const payload: CreateSubtitleJobRequest = {
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'ar',
      targetLanguage: 'en',
    };

    await expect(client.createSubtitleJob(installId, payload)).resolves.toEqual(jobResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://localhost:8000/v1/subtitle-jobs',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          'X-Extension-Install-Id': installId,
        }),
        body: JSON.stringify(payload),
      }),
    );
  });

  it('throws stable backend errors', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(
        {
          error: {
            code: 'validation_failed',
            message: 'Request validation failed.',
          },
        },
        422,
      ),
    );
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
    const payload: CreateSubtitleJobRequest = {
      youtubeVideoId: 'bad-job-id',
      sourceLanguage: 'ar',
      targetLanguage: 'en',
    };

    await expect(client.createSubtitleJob(installId, payload)).rejects.toMatchObject({
      name: 'SubtitleApiError',
      code: 'validation_failed',
      status: 422,
      } satisfies Partial<SubtitleApiError>);
  });

  it('maps stable backend errors to public release copy', () => {
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('rate_limited', 'Too many requests.', 429)),
    ).toBe('Subtitle generation is temporarily rate limited. Wait a minute and try again.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('audio_unavailable', 'Private video.', 422)),
    ).toBe('This video is not available for subtitle generation. Use a public non-live YouTube video.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('internal_error', 'Stack trace here.', 500)),
    ).toBe('The backend hit an unexpected error. Try again later.');
  });
});

function trackResponse(): TrackResponse {
  return {
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    youtubeVideoId: 'dQw4w9WgXcQ',
    sourceLanguage: 'ar',
    targetLanguage: 'en',
    generatedAt: '2026-04-30T00:00:00Z',
    expiresAt: '2026-05-30T00:00:00Z',
    webVtt: "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nmarhaban\n",
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 0,
        endMs: 1000,
        sourceText: 'marhaban',
        translatedText: 'hello',
        tokens: [],
      },
    ],
  };
}

function jsonResponse(body: unknown, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
    },
  });
}
