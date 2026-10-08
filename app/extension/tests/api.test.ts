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
afterEach(() => setInterfaceLocale('en'));

describe('SubtitleApiClient', () => {
  it('deletes a saved generation through the generation resource', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true })));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
    expect(await client.deleteSavedGeneration(installId, 'saved/job')).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledWith('http://localhost:8000/v1/subtitle-generations/saved%2Fjob',
      expect.objectContaining({ method: 'DELETE', headers: expect.not.objectContaining({ Authorization: expect.anything() }) }));
  });

  it('rejects malformed previews and previews belonging to another job or video', () => {
    const job = {
      jobId: 'job-1', youtubeVideoId: 'dQw4w9WgXcQ', status: 'running', stage: 'transcribing',
      sourceLanguage: 'eng', targetLanguage: 'fra', aiProvider: 'openai', aiModel: 'gpt-6-luna',
      includeRomanization: false, includeTranslation: true,
      progressPercent: 50, createdAt: '2026-09-11T00:00:00Z', updatedAt: '2026-09-11T00:00:00Z',
    };
    const partialTrack = { jobId: job.jobId, youtubeVideoId: job.youtubeVideoId, revision: 1, cues: trackResponse().cues };
    expect(() => guardJobResponse({ ...job, aiProvider: 'auto', aiModel: 'pending' })).toThrow();
    expect(guardJobResponse({ ...job, partialTrack }).partialTrack).toEqual(partialTrack);
    for (const patch of [{ jobId: 'another-job' }, { youtubeVideoId: 'another-id1' }, { cues: [] }, { revision: 0 }, { revision: 1.5 }, { readyThroughMs: -1 }, { readyThroughMs: NaN }]) {
      expect(() => guardJobResponse({ ...job, partialTrack: { ...partialTrack, ...patch } })).toThrow();
    }
    expect(() => guardJobResponse({ ...job, status: 'cancelled', partialTrack })).toThrow();
  });

  it('rejects missing job identity and invalid terminal payloads at the API boundary', async () => {
    const valid = {
      jobId: 'job-1', youtubeVideoId: 'dQw4w9WgXcQ', status: 'completed', stage: 'finalizing',
      sourceLanguage: 'eng', targetLanguage: 'fra', aiProvider: 'openai', aiModel: 'test-model',
      includeRomanization: false, includeTranslation: true, progressPercent: 100,
      createdAt: '2026-10-08T00:00:00Z', updatedAt: '2026-10-08T00:00:00Z', track: trackResponse(), expiresAt: null,
    };
    const { jobId, ...withoutId } = valid;
    const { track, ...withoutTrack } = valid;
    const { expiresAt, ...withoutExpiry } = valid;
    for (const invalid of [withoutId, withoutTrack, withoutExpiry,
      { ...withoutTrack, status: 'failed' },
      ...['queued', 'running', 'failed', 'cancelled'].map(status => ({ ...valid, status,
        errorCode: 'generation_cancelled', message: 'Stopped' })),
    ]) {
      const client = new SubtitleApiClient('http://localhost:8000/v1', async () => jsonResponse(invalid, 200));
      await expect(client.getSubtitleJob(installId, 'job-1')).rejects.toThrow(TypeError);
    }
    expect(guardJobResponse(valid)).toEqual(valid);
  });

  it('requests saved generations for one video without creating a job', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ jobs: [] })));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);
    await client.listSubtitleJobs(installId, 'dQw4w9WgXcQ');
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
      const assertion = expect(client.listSubtitleJobs(installId)).rejects.toThrow('Backend request timed out.');
      await vi.advanceTimersByTimeAsync(2500);
      await assertion;
      expect(vi.getTimerCount()).toBe(0);
    } finally {
      vi.useRealTimers();
    }
  });


  it('creates subtitle jobs with the extension install header', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      detectedSourceLanguage: 'spa',
      targetLanguage: 'fra',
      aiProvider: 'openai',
      aiModel: 'gpt-6-luna',
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

  it('polls one subtitle job by id', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'auto',
      targetLanguage: 'fra',
      aiProvider: 'openai',
      aiModel: 'gpt-6-luna',
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

    await expect(client.getSubtitleJob(installId, jobResponse.jobId)).resolves.toEqual(jobResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      `http://localhost:8000/v1/subtitle-jobs/${jobResponse.jobId}`,
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({
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
      aiModel: 'gpt-6-luna',
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

    await expect(client.cancelSubtitleJob(installId, jobResponse.jobId)).resolves.toEqual(jobResponse);
    expect(fetchMock).toHaveBeenCalledWith(
      `http://localhost:8000/v1/subtitle-jobs/${jobResponse.jobId}`,
      expect.objectContaining({
        method: 'DELETE',
        body: undefined,
        headers: expect.objectContaining({
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
          aiModel: 'gpt-6-luna',
          includeRomanization: true,
          includeTranslation: false,
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
      const request = client.listSubtitleJobs(installId);
      const assertion = expect(request).rejects.toThrow(TypeError);

      await vi.advanceTimersByTimeAsync(2500);

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
      const request = client.getSubtitleJob(installId, '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001');
      const assertion = expect(request).rejects.toThrow(TypeError);

      // Poll budget is 4s; advancing just under it should not resolve the request.
      await vi.advanceTimersByTimeAsync(3999);

      await vi.advanceTimersByTimeAsync(1);

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
        headers: expect.objectContaining({
        }),
        body: JSON.stringify({
          trackId: tokenResponse.trackId,
          cueId: tokenResponse.cueId,
          tokenIndex: 0,
        }),
      }),
    );
  });

  it('starts and polls pasted lyrics correction with its pinned AI selection', async () => {
    const status: LyricsCorrectionStatus = {
      attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
      status: 'queued',
      stage: 'queued',
      updatedAt: '2026-08-13T00:00:00Z',
      aiProvider: 'codex', aiModel: 'gpt-codex', aiFastMode: true,
    };
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(status, 202))
      .mockResolvedValueOnce(jsonResponse(status, 200));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    const payload = { lyrics: 'hello', expectedTrackId: 'track-1', aiProvider: 'codex' as const, aiModel: 'gpt-codex', aiFastMode: true };
    await expect(client.startLyricsCorrection(installId, 'job-1', payload)).resolves.toEqual(status);
    await expect(client.getLyricsCorrectionStatus(installId, 'job-1')).resolves.toEqual(status);
    expect(fetchMock).toHaveBeenNthCalledWith(1, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({ method: 'POST', body: JSON.stringify(payload) }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, 'http://localhost:8000/v1/subtitle-jobs/job-1/lyrics', expect.objectContaining({ method: 'GET' }));
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

    await expect(client.cancelLyricsCorrection(installId, 'job-1', {
      attemptId: cancelled.attemptId,
    })).resolves.toEqual(cancelled);
    await expect(client.quickFixToken(installId, 'job-1', 'cue-1', 2, {
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

    await expect(client.createSubtitleJob(installId, payload)).rejects.toMatchObject({
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
          aiModel: 'gpt-6-luna',
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

    await expect(client.getSubtitleJob(installId, '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001')).rejects.toThrow(
      TypeError,
    );
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
    expect(publicSubtitleErrorMessage(new SubtitleApiError('provider_unavailable', 'Private diagnostics', 503)))
      .toBe('The AI provider is unavailable. Check its connection in Settings and try again.');
    expect(publicSubtitleErrorMessage(new SubtitleApiError('provider_not_configured', 'Private diagnostics', 422), 'codex'))
      .toBe('Check your Codex connection and ElevenLabs API key in Settings.');
    expect(publicSubtitleErrorMessage(new SubtitleApiError('validation_failed', 'Private diagnostics', 422), 'codex'))
      .toBe('Review the Codex model, fast mode, and video settings, then try again.');
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
