import { afterEach, describe, expect, it, vi } from 'vitest';
import { INTERFACE_LOCALES, setInterfaceLocale } from '../utils/i18n';

import { guardJobResponse } from '../utils/api-response-guards';
import { SubtitleApiClient, SubtitleApiError, publicSubtitleErrorMessage } from '../utils/api';
import type {
  CreateSubtitleJobRequest,
  JobResponse,
  LearningTokenResponse,
  LyricsCorrectionStatus,
  SubtitleJobHistoryResponse,
  TrackResponse,
} from '../utils/contracts';

const installId = 'install_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const authToken = '1|aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
afterEach(() => setInterfaceLocale('en'));

describe('SubtitleApiClient', () => {
  it('deletes a saved generation through the authenticated generation resource', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true })));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
    expect(await client.deleteSavedGeneration(installId, authToken, 'saved/job')).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledWith('http://localhost:8000/v1/subtitle-generations/saved%2Fjob',
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ Authorization: `Bearer ${authToken}` }) }));
  });

  it('rejects malformed previews and previews belonging to another job or video', () => {
    const job = {
      jobId: 'job-1', youtubeVideoId: 'dQw4w9WgXcQ', status: 'running', stage: 'transcribing',
      sourceLanguage: 'eng', targetLanguage: 'fra', aiProvider: 'openai', aiModel: 'gpt-5.6-luna',
      includeRomanization: false, includeTranslation: true,
      progressPercent: 50, createdAt: '2026-09-11T00:00:00Z', updatedAt: '2026-09-11T00:00:00Z',
    };
    const partialTrack = { jobId: job.jobId, youtubeVideoId: job.youtubeVideoId, revision: 1, cues: trackResponse().cues };
    expect(guardJobResponse({ ...job, partialTrack }).partialTrack).toEqual(partialTrack);
    for (const patch of [{ jobId: 'another-job' }, { youtubeVideoId: 'another-id1' }, { cues: [] }, { revision: 0 }, { revision: 1.5 }, { readyThroughMs: -1 }, { readyThroughMs: NaN }]) {
      expect(() => guardJobResponse({ ...job, partialTrack: { ...partialTrack, ...patch } })).toThrow();
    }
    expect(() => guardJobResponse({ ...job, status: 'cancelled', partialTrack })).toThrow();
  });

  it('requests saved generations for one video without creating a job', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ jobs: [] })));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
    await client.listSubtitleJobs(installId, authToken, 'dQw4w9WgXcQ');
    expect(fetchMock).toHaveBeenCalledWith('http://localhost:8000/v1/subtitle-jobs?youtubeVideoId=dQw4w9WgXcQ', expect.objectContaining({ method: 'GET' }));
  });

  it('keeps the timeout active while a response body is still streaming', async () => {
    vi.useFakeTimers();
    try {
      const fetchMock = vi.fn(async (_url: RequestInfo | URL, init?: RequestInit) => new Response(
        new ReadableStream({
          start(controller) {
            controller.enqueue(new TextEncoder().encode('{"jobs":['));
            init?.signal?.addEventListener('abort', () => controller.error(new DOMException('Aborted', 'AbortError')));
          },
        }),
      ));
      const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
      const assertion = expect(client.listSubtitleJobs(installId, authToken)).rejects.toThrow('Backend request timed out.');
      await vi.advanceTimersByTimeAsync(2500);
      await assertion;
      expect(vi.getTimerCount()).toBe(0);
    } finally {
      vi.useRealTimers();
    }
  });

  it.each([
    ['payment_required', 'Account and billing'],
    ['usage_exhausted', 'Account and billing'],
    ['feature_unavailable', 'Watch selections'],
  ] as const)('gives %s a recovery action', (code, action) => {
    const message = publicSubtitleErrorMessage(new SubtitleApiError(code, 'Rejected.', 403));
    expect(message).toContain(action);
    expect(message).toContain('Account');
  });

  it('creates subtitle jobs with the extension install header', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      detectedSourceLanguage: 'spa',
      targetLanguage: 'fra',
      aiProvider: 'openai',
      aiModel: 'gpt-5.6-luna',
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
      aiProvider: 'openai',
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
      aiProvider: 'openai',
      aiModel: 'gpt-5.6-luna',
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

  it('cancels one subtitle job without sending a request body', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      targetLanguage: 'fra',
      aiProvider: 'openai',
      aiModel: 'gpt-5.6-luna',
      includeRomanization: true,
      includeTranslation: true,
      status: 'cancelled',
      stage: 'transcribing',
      progressPercent: 42,
      errorCode: 'generation_cancelled',
      message: 'Generation cancelled.',
      createdAt: '2026-04-30T00:00:00Z',
      updatedAt: '2026-04-30T00:01:00Z',
    };
    const fetchMock = vi.fn(async () => jsonResponse(jobResponse, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.cancelSubtitleJob(installId, authToken, jobResponse.jobId)).resolves.toEqual(jobResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      `http://localhost:8000/v1/subtitle-jobs/${jobResponse.jobId}`,
      expect.objectContaining({
        method: 'DELETE',
        body: undefined,
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
          aiProvider: 'openai',
          aiModel: 'gpt-5.6-luna',
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

  it('times out backend job history instead of blocking panel startup', async () => {
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

  it('times out account fetches with the default budget when fetch never resolves', async () => {
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
      const request = client.getExtensionAccount(installId, authToken);
      const assertion = expect(request).rejects.toThrow(TypeError);

      // Default budget is 10s; advancing just under it should not resolve the request.
      await vi.advanceTimersByTimeAsync(9999);
      expect(fetchMock).toHaveBeenCalledOnce();

      await vi.advanceTimersByTimeAsync(1);

      await assertion;
    } finally {
      vi.useRealTimers();
    }
  });

  it('times out subtitle job polls with the 4s poll budget so a stalled poll fails fast', async () => {
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
      const request = client.getSubtitleJob(installId, authToken, '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001');
      const assertion = expect(request).rejects.toThrow(TypeError);

      // Poll budget is 4s; advancing just under it should not resolve the request.
      await vi.advanceTimersByTimeAsync(3999);

      await vi.advanceTimersByTimeAsync(1);

      await assertion;
    } finally {
      vi.useRealTimers();
    }
  });

  it('resolves quickly when the backend responds fast and the default timeout does not interfere', async () => {
    const accountResponse = { account: extensionAuthResponse().account };
    const fetchMock = vi.fn(async () => jsonResponse(accountResponse, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.getExtensionAccount(installId, authToken)).resolves.toEqual(accountResponse);
    expect(fetchMock).toHaveBeenCalledOnce();
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

  it('starts and polls pasted lyrics correction status', async () => {
    const status: LyricsCorrectionStatus = {
      attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
      status: 'queued',
      stage: 'queued',
      updatedAt: '2026-08-13T00:00:00Z',
    };
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(status, 202))
      .mockResolvedValueOnce(jsonResponse(status, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.startLyricsCorrection(installId, authToken, 'job-1', { lyrics: 'hello', expectedTrackId: 'track-1' })).resolves.toEqual(status);
    await expect(client.getLyricsCorrectionStatus(installId, authToken, 'job-1')).resolves.toEqual(status);
    expect(fetchMock).toHaveBeenNthCalledWith(1, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({ method: 'POST', body: JSON.stringify({ lyrics: 'hello', expectedTrackId: 'track-1' }) }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({ method: 'GET' }));
  });

  it('preserves the optional legacy allowPartial request field', async () => {
    const status: LyricsCorrectionStatus = {
      attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
      status: 'queued',
      stage: 'queued',
      updatedAt: '2026-08-13T00:00:00Z',
    };
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(status, 202))
      .mockResolvedValueOnce(jsonResponse(status, 202));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await client.startLyricsCorrection(installId, authToken, 'job-1', {
      lyrics: 'partial lyrics',
      expectedTrackId: 'track-1',
    });
    await client.startLyricsCorrection(installId, authToken, 'job-1', {
      lyrics: 'partial lyrics',
      expectedTrackId: 'track-1',
      allowPartial: true,
    });

    expect(fetchMock).toHaveBeenNthCalledWith(1, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({
      body: JSON.stringify({ lyrics: 'partial lyrics', expectedTrackId: 'track-1' }),
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({
      body: JSON.stringify({ lyrics: 'partial lyrics', expectedTrackId: 'track-1', allowPartial: true }),
    }));
  });

  it.each(Object.keys(INTERFACE_LOCALES))('keeps correction endpoints and source content unchanged in %s', async (locale) => {
    setInterfaceLocale(locale);
    const cancelled: LyricsCorrectionStatus = {
      attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
      status: 'cancelled',
      stage: 'cancelled',
      updatedAt: '2026-08-13T00:00:00Z',
    };
    const track = trackResponse();
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(cancelled, 200))
      .mockResolvedValueOnce(jsonResponse(track, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.cancelLyricsCorrection(installId, authToken, 'job-1', {
      attemptId: cancelled.attemptId,
    })).resolves.toEqual(cancelled);
    await expect(client.quickFixToken(installId, authToken, 'job-1', 'cue-1', 2, {
      expectedTrackId: track.trackId,
      text: 'updated',
    })).resolves.toEqual(track);
    expect(fetchMock).toHaveBeenNthCalledWith(1, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({
      method: 'DELETE',
      body: JSON.stringify({ attemptId: cancelled.attemptId }),
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, 'http://localhost:8000/v1/subtitle-jobs/job-1/cues/cue-1/tokens/2', expect.objectContaining({
      method: 'PATCH', body: JSON.stringify({ expectedTrackId: track.trackId, text: 'updated' }),
    }));
    expect(new SubtitleApiError('internal_error', 'test', 500).name).toBe('SubtitleApiError');
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
      aiProvider: 'openai',
      includeRomanization: true,
      includeTranslation: false,
    };

    await expect(client.createSubtitleJob(installId, authToken, payload)).rejects.toMatchObject({
      name: 'SubtitleApiError',
      code: 'validation_failed',
      status: 422,
      } satisfies Partial<SubtitleApiError>);
  });

  it('rejects malformed successful backend responses before they enter extension state', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(
        {
          jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
          youtubeVideoId: 'dQw4w9WgXcQ',
          sourceLanguage: 'auto',
          targetLanguage: 'fra',
          aiProvider: 'openai',
          aiModel: 'gpt-5.6-luna',
          includeRomanization: true,
          includeTranslation: true,
          status: 'completed',
          stage: 'finalizing',
          createdAt: '2026-04-30T00:00:00Z',
          updatedAt: '2026-04-30T00:00:00Z',
        },
        200,
      ),
    );
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    await expect(client.getSubtitleJob(installId, authToken, '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001')).rejects.toThrow(
      TypeError,
    );
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
      planName: 'Beta Base',
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
