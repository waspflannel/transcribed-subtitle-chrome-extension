import { describe, expect, it, vi } from 'vitest';

import { SubtitleApiClient, SubtitleApiError, publicSubtitleErrorMessage } from '../utils/api';
import type {
  CreateSubtitleJobRequest,
  JobResponse,
  LearningTokenResponse,
  SubtitleJobHistoryResponse,
  TrackResponse,
} from '../utils/contracts';

const installId = 'install_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

describe('SubtitleApiClient', () => {
  it('creates subtitle jobs with the extension install header', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      detectedSourceLanguage: 'spa',
      targetLanguage: 'fra',
      track: trackResponse(),
      createdAt: '2026-04-30T00:00:00Z',
      updatedAt: '2026-04-30T00:00:00Z',
      expiresAt: '2026-05-30T00:00:00Z',
    };
    const fetchMock = vi.fn(async () => jsonResponse(jobResponse, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    const payload: CreateSubtitleJobRequest = {
      youtubeVideoId: 'dQw4w9WgXcQ',
      youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      targetLanguage: 'fra',
      enrichmentMode: 'on_demand',
      includeRomanization: true,
      includeTranslation: true,
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

  it('lists backend job history from the shared jobs endpoint', async () => {
    const history: SubtitleJobHistoryResponse = {
      jobs: [
        {
          youtubeVideoId: 'dQw4w9WgXcQ',
          youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
          status: 'running',
          startedAt: '2026-05-11T00:00:00Z',
          stage: 'transcribing',
          progressPercent: 45,
          sourceLanguage: 'auto',
          detectedSourceLanguage: 'spa',
          targetLanguage: 'eng',
        },
      ],
    };
    const fetchMock = vi.fn(async () => jsonResponse(history, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.listSubtitleJobs(installId)).resolves.toEqual(history);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://localhost:8000/v1/subtitle-jobs',
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({
          'X-Extension-Install-Id': installId,
        }),
      }),
    );
  });

  it('times out backend job history instead of blocking popup startup', async () => {
    vi.useFakeTimers();

    try {
      const fetchMock = vi.fn(
        (_url: RequestInfo | URL, init?: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            init?.signal?.addEventListener('abort', () => {
              const error = new Error('Aborted');

              error.name = 'AbortError';
              reject(error);
            });
          }),
      );
      const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
      const request = client.listSubtitleJobs(installId);
      const assertion = expect(request).rejects.toThrow(TypeError);

      await vi.advanceTimersByTimeAsync(2500);

      await assertion;
    } finally {
      vi.useRealTimers();
    }
  });

  it('enriches one clicked learning token', async () => {
    const tokenResponse: LearningTokenResponse = {
      trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
      cueId: 'cue-0001',
      token: {
        index: 0,
        text: 'hola',
        normalizedText: 'hola',
        gloss: 'hello',
      },
    };
    const fetchMock = vi.fn(async () => jsonResponse(tokenResponse, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(
      client.enrichLearningToken(installId, {
        trackId: tokenResponse.trackId,
        cueId: tokenResponse.cueId,
        tokenIndex: 0,
      }),
    ).resolves.toEqual(tokenResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://localhost:8000/v1/learning-tokens',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          trackId: tokenResponse.trackId,
          cueId: tokenResponse.cueId,
          tokenIndex: 0,
        }),
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
      youtubeUrl: 'https://www.youtube.com/watch?v=bad-job-id',
      sourceLanguage: 'auto',
      targetLanguage: 'fra',
      enrichmentMode: 'on_demand',
      includeRomanization: true,
      includeTranslation: false,
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
    sourceLanguage: 'auto',
    detectedSourceLanguage: 'spa',
    targetLanguage: 'fra',
    generatedAt: '2026-04-30T00:00:00Z',
    expiresAt: '2026-05-30T00:00:00Z',
    webVtt: "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nhola\n",
    cues: [
      {
        cueId: 'cue-0001',
        index: 0,
        startMs: 0,
        endMs: 1000,
        sourceText: 'hola',
        translatedText: 'bonjour',
        tokens: [
          {
            index: 0,
            text: 'hola',
            normalizedText: 'hola',
          },
        ],
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
