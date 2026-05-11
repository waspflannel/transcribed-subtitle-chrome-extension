import type {
  ApiError,
  CreateSubtitleJobRequest,
  JobResponse,
  LearningTokenRequest,
  LearningTokenResponse,
  SubtitleJobHistoryResponse,
} from './contracts';

export const DEFAULT_BACKEND_API_BASE_URL = 'http://localhost:8000/v1';
const JOB_HISTORY_TIMEOUT_MS = 2500;

export class SubtitleApiError extends Error {
  public constructor(
    public readonly code: ApiError['error']['code'],
    message: string,
    public readonly status: number,
    public readonly details?: ApiError['error']['details'],
  ) {
    super(message);
    this.name = 'SubtitleApiError';
  }
}

export class SubtitleApiClient {
  public constructor(
    private readonly baseUrl = DEFAULT_BACKEND_API_BASE_URL,
    private readonly fetchImpl: typeof fetch = globalThis.fetch.bind(globalThis),
  ) {}

  public async createSubtitleJob(installId: string, payload: CreateSubtitleJobRequest): Promise<JobResponse> {
    return this.request<JobResponse>('subtitle-jobs', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  public async listSubtitleJobs(installId: string): Promise<SubtitleJobHistoryResponse> {
    return this.request<SubtitleJobHistoryResponse>('subtitle-jobs', installId, {
      method: 'GET',
      timeoutMs: JOB_HISTORY_TIMEOUT_MS,
    });
  }

  public async enrichLearningToken(
    installId: string,
    payload: LearningTokenRequest,
  ): Promise<LearningTokenResponse> {
    return this.request<LearningTokenResponse>('learning-tokens', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  private async request<TResponse>(
    path: string,
    installId: string,
    init: Pick<RequestInit, 'method' | 'body'> & { timeoutMs?: number },
  ): Promise<TResponse> {
    const baseUrl = this.baseUrl.endsWith('/') ? this.baseUrl : `${this.baseUrl}/`;
    const controller = typeof init.timeoutMs === 'number' ? new AbortController() : undefined;
    const timeoutId =
      controller && init.timeoutMs
        ? globalThis.setTimeout(() => controller.abort(), init.timeoutMs)
        : undefined;

    let response: Response;

    try {
      response = await this.fetchImpl(new URL(path, baseUrl).toString(), {
        method: init.method,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Extension-Install-Id': installId,
        },
        body: init.body,
        signal: controller?.signal,
      });
    } catch (error) {
      if (isAbortError(error)) {
        throw new TypeError('Backend request timed out.');
      }

      throw error;
    } finally {
      if (timeoutId !== undefined) {
        globalThis.clearTimeout(timeoutId);
      }
    }

    const body = await response.json().catch(() => null);

    if (!response.ok) {
      const apiError = body as Partial<ApiError> | null;
      const error = apiError?.error;

      throw new SubtitleApiError(
        error?.code ?? 'internal_error',
        error?.message ?? `Backend request failed with status ${response.status}`,
        response.status,
        error?.details,
      );
    }

    return body as TResponse;
  }
}

function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError';
}

export function publicSubtitleErrorMessage(error: unknown): string {
  if (error instanceof SubtitleApiError) {
    return messageForApiErrorCode(error.code);
  }

  if (error instanceof TypeError) {
    return 'Could not reach the subtitle backend. Make sure it is running and try again.';
  }

  return 'Unable to generate subtitles. Try again later.';
}

function messageForApiErrorCode(code: ApiError['error']['code']): string {
  switch (code) {
    case 'validation_failed':
      return 'The video details could not be validated. Refresh the YouTube tab and try again.';

    case 'unsupported_video':
    case 'audio_unavailable':
      return 'This video is not available for subtitle generation. Use a public non-live YouTube video.';

    case 'video_too_long':
      return 'This video is over the 60 minute release limit.';

    case 'audio_acquisition_failed':
      return 'The backend could not extract audio from this video. Try another public video or check local backend setup.';

    case 'transcription_failed':
      return 'The AI transcription step failed. Try again later.';

    case 'enrichment_failed':
      return 'The AI word-card step failed. Try again later.';

    case 'rate_limited':
      return 'Subtitle generation is temporarily rate limited. Wait a minute and try again.';

    case 'not_found':
    case 'expired':
      return 'The generated subtitle track is no longer available. Generate subtitles again.';

    case 'internal_error':
      return 'The backend hit an unexpected error. Try again later.';
  }
}
