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
const authToken = '1|aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

describe('SubtitleApiClient', () => {
  it('creates subtitle jobs with the extension install header', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      detectedSourceLanguage: 'spa',
      targetLanguage: 'fra',
      enrichmentMode: 'on_demand',
      includeRomanization: true,
      includeTranslation: true,
      status: 'completed',
      stage: 'finalizing',
      progressPercent: 100,
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

    await expect(client.createSubtitleJob(installId, authToken, payload)).resolves.toEqual(jobResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://localhost:8000/v1/subtitle-jobs',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          Authorization: `Bearer ${authToken}`,
          'X-Extension-Install-Id': installId,
        }),
        body: JSON.stringify(payload),
      }),
    );
  });

  it('polls one subtitle job by id', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      targetLanguage: 'fra',
      enrichmentMode: 'on_demand',
      includeRomanization: true,
      includeTranslation: true,
      status: 'running',
      stage: 'tokenizing',
      progressPercent: 65,
      createdAt: '2026-04-30T00:00:00Z',
      updatedAt: '2026-04-30T00:01:00Z',
    };
    const fetchMock = vi.fn(async () => jsonResponse(jobResponse, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.getSubtitleJob(installId, authToken, jobResponse.jobId)).resolves.toEqual(jobResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      `http://localhost:8000/v1/subtitle-jobs/${jobResponse.jobId}`,
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({
          Authorization: `Bearer ${authToken}`,
          'X-Extension-Install-Id': installId,
        }),
      }),
    );
  });

  it('lists backend job history from the shared jobs endpoint', async () => {
    const history: SubtitleJobHistoryResponse = {
      jobs: [
        {
          jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3003',
          youtubeVideoId: 'dQw4w9WgXcQ',
          youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
          status: 'running',
          startedAt: '2026-05-11T00:00:00Z',
          lastUpdatedAt: '2026-05-11T00:01:00Z',
          stage: 'transcribing',
          progressPercent: 45,
          sourceLanguage: 'auto',
          detectedSourceLanguage: 'spa',
          targetLanguage: 'eng',
          enrichmentMode: 'on_demand',
          includeRomanization: true,
          includeTranslation: false,
        },
      ],
    };
    const fetchMock = vi.fn(async () => jsonResponse(history, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.listSubtitleJobs(installId, authToken)).resolves.toEqual(history);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://localhost:8000/v1/subtitle-jobs',
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({
          Authorization: `Bearer ${authToken}`,
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
      const request = client.listSubtitleJobs(installId, authToken);
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
      client.enrichLearningToken(installId, authToken, {
        trackId: tokenResponse.trackId,
        cueId: tokenResponse.cueId,
        tokenIndex: 0,
      }),
    ).resolves.toEqual(tokenResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://localhost:8000/v1/learning-tokens',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          Authorization: `Bearer ${authToken}`,
        }),
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

    await expect(client.createSubtitleJob(installId, authToken, payload)).rejects.toMatchObject({
      name: 'SubtitleApiError',
      code: 'validation_failed',
      status: 422,
      } satisfies Partial<SubtitleApiError>);
  });

  it('maps stable backend errors to public release copy', () => {
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('unauthenticated', 'Token required.', 401)),
    ).toBe('Sign in to the extension before generating subtitles.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('email_not_verified', 'Verify email.', 403)),
    ).toBe('Verify your email address before generating subtitles.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('rate_limited', 'Too many requests.', 429)),
    ).toBe('Subtitle generation is temporarily rate limited. Wait a minute and try again.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('queue_unavailable', 'Queue busy.', 503)),
    ).toBe('The subtitle queue database is busy. Wait for the current generation to finish, then try again.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('audio_unavailable', 'Private video.', 422)),
    ).toBe('This video is not available for subtitle generation. Use a public non-live YouTube video.');
    expect(
      publicSubtitleErrorMessage(new SubtitleApiError('internal_error', 'Stack trace here.', 500)),
    ).toBe('The backend hit an unexpected error. Try again later.');
  });

  it('logs in, fetches account state, and logs out with scoped extension tokens', async () => {
    const authResponse = extensionAuthResponse();
    const accountResponse = { account: authResponse.account };
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(authResponse, 200))
      .mockResolvedValueOnce(jsonResponse(accountResponse, 200))
      .mockResolvedValueOnce(jsonResponse({ ok: true }, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(
      client.loginExtension(installId, {
        email: authResponse.account.email,
        password: 'correct-password',
      }),
    ).resolves.toEqual(authResponse);
    await expect(client.getExtensionAccount(installId, authResponse.token.plainTextToken)).resolves.toEqual(
      accountResponse,
    );
    await expect(client.logoutExtension(installId, authResponse.token.plainTextToken)).resolves.toEqual({ ok: true });

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      'http://localhost:8000/v1/extension-auth/login',
      expect.objectContaining({
        method: 'POST',
        headers: expect.not.objectContaining({
          Authorization: expect.any(String),
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      'http://localhost:8000/v1/extension-auth/account',
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({
          Authorization: `Bearer ${authResponse.token.plainTextToken}`,
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      'http://localhost:8000/v1/extension-auth/logout',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          Authorization: `Bearer ${authResponse.token.plainTextToken}`,
        }),
      }),
    );
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

function extensionAuthResponse() {
  return {
    account: {
      status: 'authenticated' as const,
      id: '1',
      email: 'learner@example.com',
      name: 'Beta Learner',
      emailVerified: true,
      planName: 'Local beta',
      tierName: 'Base',
      tierSpeedLabel: 'Standard queue',
      monthlyMinuteLimit: 60,
      monthlyMinutesUsed: 0,
      monthlyMinutesPending: 0,
      monthlyMinutesRemaining: 60,
      resetAt: '2026-06-01T00:00:00.000Z',
      upgradeAvailable: true,
    },
    token: {
      plainTextToken: authToken,
      tokenType: 'Bearer' as const,
      expiresAt: '2026-06-21T00:00:00.000Z',
      abilities: ['extension:account:read', 'extension:subtitles:write', 'extension:tokens:revoke'],
    },
  };
}
