import type {
  ApiError,
  CreateSubtitleJobRequest,
  ExtensionAccountResponse,
  ExtensionAuthResponse,
  ExtensionLoginRequest,
  JobResponse,
  LearningTokenRequest,
  LearningTokenResponse,
  LyricsCorrectionRequest,
  LyricsCorrectionCancelRequest,
  LyricsCorrectionStatus,
  QuickFixTokenRequest,
  SubtitleJobHistoryResponse,
  TrackResponse,
} from './contracts';
import { resolveBackendApiBaseUrl } from './api-config';
import {
  guardExtensionAccountResponse,
  guardExtensionAuthResponse,
  guardJobResponse,
  guardLearningTokenResponse,
  guardLyricsCorrectionStatus,
  guardOkResponse,
  guardSubtitleJobHistoryResponse,
  guardTrackResponse,
} from './api-response-guards';

export const DEFAULT_BACKEND_API_BASE_URL = resolveBackendApiBaseUrl(import.meta.env.WXT_BACKEND_API_BASE_URL);
const DEFAULT_REQUEST_TIMEOUT_MS = 10000;
const JOB_HISTORY_TIMEOUT_MS = 2500;
const SUBTITLE_JOB_POLL_TIMEOUT_MS = 4000;
const LEARNING_TOKEN_TIMEOUT_MS = 25000;

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

  public async loginExtension(installId: string, payload: ExtensionLoginRequest): Promise<ExtensionAuthResponse> {
    return this.request<ExtensionAuthResponse>('extension-auth/login', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
    }, guardExtensionAuthResponse);
  }

  public async getExtensionAccount(installId: string, authToken: string): Promise<ExtensionAccountResponse> {
    return this.request<ExtensionAccountResponse>('extension-auth/account', installId, {
      method: 'GET',
      authToken,
    }, guardExtensionAccountResponse);
  }

  public async logoutExtension(installId: string, authToken: string): Promise<{ ok: true }> {
    return this.request<{ ok: true }>('extension-auth/logout', installId, {
      method: 'POST',
      authToken,
    }, guardOkResponse);
  }

  public async createSubtitleJob(
    installId: string,
    authToken: string,
    payload: CreateSubtitleJobRequest,
  ): Promise<JobResponse> {
    return this.request<JobResponse>('subtitle-jobs', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
      authToken,
    }, guardJobResponse);
  }

  public async getSubtitleJob(installId: string, authToken: string, jobId: string): Promise<JobResponse> {
    return this.request<JobResponse>(`subtitle-jobs/${encodeURIComponent(jobId)}`, installId, {
      method: 'GET',
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
      authToken,
    }, guardJobResponse);
  }

  public async cancelSubtitleJob(installId: string, authToken: string, jobId: string): Promise<JobResponse> {
    return this.request<JobResponse>(`subtitle-jobs/${encodeURIComponent(jobId)}`, installId, {
      method: 'DELETE',
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
      authToken,
    }, guardJobResponse);
  }

  public async listSubtitleJobs(installId: string, authToken: string, youtubeVideoId?: string): Promise<SubtitleJobHistoryResponse> {
    return this.request<SubtitleJobHistoryResponse>(youtubeVideoId ? `subtitle-jobs?youtubeVideoId=${encodeURIComponent(youtubeVideoId)}` : 'subtitle-jobs', installId, {
      method: 'GET',
      timeoutMs: JOB_HISTORY_TIMEOUT_MS,
      authToken,
    }, guardSubtitleJobHistoryResponse);
  }

  public async enrichLearningToken(
    installId: string,
    authToken: string,
    payload: LearningTokenRequest,
  ): Promise<LearningTokenResponse> {
    return this.request<LearningTokenResponse>('learning-tokens', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
      timeoutMs: LEARNING_TOKEN_TIMEOUT_MS,
      authToken,
    }, guardLearningTokenResponse);
  }

  public async startLyricsCorrection(
    installId: string,
    authToken: string,
    jobId: string,
    payload: LyricsCorrectionRequest,
  ): Promise<LyricsCorrectionStatus> {
    return this.request<LyricsCorrectionStatus>(`subtitle-jobs/${encodeURIComponent(jobId)}/lyrics`, installId, {
      method: 'POST',
      body: JSON.stringify(payload),
      timeoutMs: LEARNING_TOKEN_TIMEOUT_MS,
      authToken,
    }, guardLyricsCorrectionStatus);
  }

  public async getLyricsCorrectionStatus(
    installId: string,
    authToken: string,
    jobId: string,
  ): Promise<LyricsCorrectionStatus> {
    return this.request<LyricsCorrectionStatus>(`subtitle-jobs/${encodeURIComponent(jobId)}/lyrics`, installId, {
      method: 'GET',
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
      authToken,
    }, guardLyricsCorrectionStatus);
  }

  public async cancelLyricsCorrection(
    installId: string,
    authToken: string,
    jobId: string,
    payload: LyricsCorrectionCancelRequest,
  ): Promise<LyricsCorrectionStatus> {
    return this.request<LyricsCorrectionStatus>(`subtitle-jobs/${encodeURIComponent(jobId)}/lyrics`, installId, {
      method: 'DELETE',
      body: JSON.stringify(payload),
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
      authToken,
    }, guardLyricsCorrectionStatus);
  }

  public async quickFixToken(
    installId: string,
    authToken: string,
    jobId: string,
    cueId: string,
    tokenIndex: number,
    payload: QuickFixTokenRequest,
  ): Promise<TrackResponse> {
    return this.request<TrackResponse>(
      `subtitle-jobs/${encodeURIComponent(jobId)}/cues/${encodeURIComponent(cueId)}/tokens/${tokenIndex}`,
      installId,
      {
        method: 'PATCH',
        body: JSON.stringify(payload),
        timeoutMs: 60000,
        authToken,
      },
      guardTrackResponse,
    );
  }

  private async request<TResponse>(
    path: string,
    installId: string,
    init: Pick<RequestInit, 'method' | 'body'> & { timeoutMs?: number; authToken?: string },
    guardResponse: (body: unknown) => TResponse,
  ): Promise<TResponse> {
    const baseUrl = this.baseUrl.endsWith('/') ? this.baseUrl : `${this.baseUrl}/`;
    const timeoutMs = init.timeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS;
    const controller = new AbortController();
    const timeoutId = globalThis.setTimeout(() => controller.abort(), timeoutMs);

    try {
      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Extension-Install-Id': installId,
      };

      if (init.authToken) {
        headers.Authorization = `Bearer ${init.authToken}`;
      }

      const response = await this.fetchImpl(new URL(path, baseUrl).toString(), {
        method: init.method,
        headers,
        body: init.body,
        signal: controller.signal,
      });
      const body = await response.json().catch((error: unknown) => {
        if (controller.signal.aborted || isAbortError(error)) throw error;
        return null;
      });

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

      if (body === null) {
        throw new TypeError('Backend returned invalid JSON.');
      }

      return guardResponse(body);
    } catch (error) {
      if (controller.signal.aborted || isAbortError(error)) {
        throw new TypeError('Backend request timed out.');
      }

      throw error;
    } finally {
      globalThis.clearTimeout(timeoutId);
    }

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

    case 'invalid_credentials':
      return 'The email or password was not accepted.';

    case 'unauthenticated':
      return 'Sign in to the extension before generating subtitles.';

    case 'unauthorized':
      return 'This extension session is not allowed to access that subtitle job.';

    case 'email_not_verified':
      return 'Verify your email address before generating subtitles.';

    case 'insecure_transport':
      return 'Extension sign-in requires HTTPS in production.';

    case 'payment_required':
      return 'Choose an active billing plan before generating subtitles. Open Account and choose Account and billing.';

    case 'usage_exhausted':
      return 'This billing period does not have enough subtitle minutes left. Check usage and plans through Account and billing in Account.';

    case 'feature_unavailable':
      return 'Your current plan does not include that generation option. Change your Watch selections or review plans through Account and billing in Account.';

    case 'queue_full':
      return 'Your generation queue is full. Wait for a queued video to finish before adding more.';

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
      return 'The AI subtitle analysis step failed. Try again later.';

    case 'rate_limited':
      return 'Subtitle generation is temporarily rate limited. Wait a minute and try again.';

    case 'not_found':
    case 'expired':
      return 'The generated subtitle track is no longer available. Generate subtitles again.';

    case 'internal_error':
      return 'The backend hit an unexpected error. Try again later.';

    case 'lyrics_correction_in_progress':
      return 'A pasted-lyrics correction is already in progress.';

    case 'lyrics_incomplete':
      return 'Paste the complete lyrics for the song. Your current subtitles are unchanged.';

    case 'lyrics_do_not_match':
      return 'These lyrics do not seem to match this song. Check the paste and try again.';

    case 'lyrics_correction_failed':
      return 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.';

    case 'generation_cancelled':
      return 'Generation cancelled. Reserved minutes were released.';

    case 'generation_not_cancellable':
      return 'This generation has already finished and cannot be cancelled.';
  }

  return 'Unable to generate subtitles. Try again later.';
}
