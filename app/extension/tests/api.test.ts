import { describe, expect, it, vi } from 'vitest';

import { SubtitleApiClient, SubtitleApiError } from '../utils/api';
import type { CreateSubtitleJobRequest, JobResponse } from '../utils/contracts';

const installId = 'install_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

describe('SubtitleApiClient', () => {
  it('creates subtitle jobs with the extension install header', async () => {
    const jobResponse: JobResponse = {
      jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
      status: 'queued',
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'ar',
      targetLanguage: 'en',
      createdAt: '2026-04-30T00:00:00Z',
      updatedAt: '2026-04-30T00:00:00Z',
    };
    const fetchMock = vi.fn(async () => jsonResponse(jobResponse, 202));
    const client = new SubtitleApiClient('http://localhost:8000/v1', fetchMock as typeof fetch);

    const payload: CreateSubtitleJobRequest = {
      youtubeVideoId: 'dQw4w9WgXcQ',
      sourceLanguage: 'ar',
      targetLanguage: 'en',
      options: {
        includeRomanization: true,
        includeGloss: true,
      },
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

    await expect(client.getSubtitleJob(installId, 'bad-job-id')).rejects.toMatchObject({
      name: 'SubtitleApiError',
      code: 'validation_failed',
      status: 422,
    } satisfies Partial<SubtitleApiError>);
  });
});

function jsonResponse(body: unknown, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
    },
  });
}
