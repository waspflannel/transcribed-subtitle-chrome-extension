import { t } from './i18n';
import type {
  ClaudeAccount,
  CodexAccount,
  InstanceSettings,
  UpdateInstanceSettings,
  ApiError,
  CreateSubtitleJobRequest,
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
  guardClaudeAccount,
  guardCodexAccount,
  guardInstanceSettings,
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
const SUBTITLE_JOB_POLL_TIMEOUT_MS = 30000;

export class SubtitleContractError extends TypeError {}
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

  public async getCodexAccount(installId: string): Promise<CodexAccount> {
    return this.request('codex', installId, { method: 'GET', timeoutMs: 30000 }, guardCodexAccount);
  }

  public async loginCodex(installId: string): Promise<CodexAccount> {
    return this.request('codex/login', installId, { method: 'POST', timeoutMs: 45000 }, guardCodexAccount);
  }

  public async disconnectCodex(installId: string): Promise<CodexAccount> {
    return this.request('codex', installId, { method: 'DELETE', timeoutMs: 30000 }, guardCodexAccount);
  }

  public async getClaudeAccount(installId: string): Promise<ClaudeAccount> {
    return this.request('claude', installId, { method: 'GET', timeoutMs: 30000 }, guardClaudeAccount);
  }

  public async loginClaude(installId: string): Promise<ClaudeAccount> {
    return this.request('claude/login', installId, { method: 'POST', timeoutMs: 45000 }, guardClaudeAccount);
  }

  public async submitClaudeCode(installId: string, code: string): Promise<ClaudeAccount> {
    return this.request('claude/login/code', installId, { method: 'POST', body: JSON.stringify({ code }), timeoutMs: 45000 }, guardClaudeAccount);
  }

  public async disconnectClaude(installId: string): Promise<ClaudeAccount> {
    return this.request('claude', installId, { method: 'DELETE', timeoutMs: 30000 }, guardClaudeAccount);
  }



  public async getInstanceSettings(installId: string): Promise<InstanceSettings> {
    return this.request('settings', installId, { method: 'GET' }, guardInstanceSettings);
  }

  public async updateInstanceSettings(installId: string, patch: UpdateInstanceSettings): Promise<InstanceSettings> {
    return this.request('settings', installId, { method: 'PUT', body: JSON.stringify(patch) }, guardInstanceSettings);
  }

  public async prefetchSubtitleAudio(installId: string, youtubeVideoId: string): Promise<{ ok: true }> {
    return this.request('subtitle-audio/prefetch', installId, {
      method: 'POST', body: JSON.stringify({ youtubeVideoId }),
    }, guardOkResponse);
  }


  public async createSubtitleJob(
    installId: string,
    payload: CreateSubtitleJobRequest,
  ): Promise<JobResponse> {
    return this.request<JobResponse>('subtitle-jobs', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
    }, guardJobResponse);
  }

  public async getSubtitleJob(installId: string, jobId: string, sinceRevision?: number): Promise<JobResponse> {
    return this.request<JobResponse>(`subtitle-jobs/${encodeURIComponent(jobId)}${sinceRevision === undefined ? "" : `?sinceRevision=${sinceRevision}`}`, installId, {
      method: 'GET',
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
    }, guardJobResponse);
  }

  public async *streamSubtitleJob(installId: string, jobId: string): AsyncGenerator<JobResponse> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 25000);
    let reader: ReadableStreamDefaultReader<Uint8Array> | undefined;
    try {
      const response = await this.fetchImpl(`${this.baseUrl.replace(/\/$/, '')}/subtitle-jobs/${encodeURIComponent(jobId)}/events`, {
        headers: { Accept: 'text/event-stream', 'X-Extension-Install-Id': installId }, signal: controller.signal,
      });
      if (!response.ok || !response.headers.get('content-type')?.includes('text/event-stream') || !response.body) throw new Error('Status stream unavailable');
      reader = response.body.getReader();
      const decoder = new TextDecoder();
      let pending = '';
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        pending += decoder.decode(value, { stream: true });
        pending = pending.replace(/\r\n/g, '\n');
        let end: number;
        while ((end = pending.indexOf('\n\n')) !== -1) {
          const event = pending.slice(0, end);
          pending = pending.slice(end + 2);
          const data = event.split('\n').filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n');
          if (!data || data === '</stream>') continue;
          try { yield guardJobResponse(JSON.parse(data)); }
          catch { throw new SubtitleContractError('Backend returned an invalid status event.'); }
        }
        if (pending.length > 8_000_000) throw new SubtitleContractError('Status event exceeded the size limit.');
      }
    } finally {
      clearTimeout(timeout);
      controller.abort();
      await reader?.cancel().catch(() => undefined);
    }
  }

  public async cancelSubtitleJob(installId: string, jobId: string): Promise<JobResponse> {
    return this.request<JobResponse>(`subtitle-jobs/${encodeURIComponent(jobId)}`, installId, {
      method: 'DELETE',
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
    }, guardJobResponse);
  }

  public async deleteSavedGeneration(installId: string, jobId: string): Promise<{ ok: true }> {
    return this.request(`subtitle-generations/${encodeURIComponent(jobId)}`, installId, {
      method: 'DELETE',
    }, guardOkResponse);
  }

  public async listSubtitleJobs(installId: string, youtubeVideoId?: string): Promise<SubtitleJobHistoryResponse> {
    return this.request<SubtitleJobHistoryResponse>(youtubeVideoId ? `subtitle-jobs?youtubeVideoId=${encodeURIComponent(youtubeVideoId)}` : 'subtitle-jobs', installId, {
      method: 'GET',
      timeoutMs: JOB_HISTORY_TIMEOUT_MS,
    }, guardSubtitleJobHistoryResponse);
  }

  public async enrichLearningToken(
    installId: string,
    payload: LearningTokenRequest,
  ): Promise<LearningTokenResponse> {
    return this.request<LearningTokenResponse>('learning-tokens', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
      timeoutMs: LEARNING_TOKEN_TIMEOUT_MS,
    }, guardLearningTokenResponse);
  }

  public async startLyricsCorrection(
    installId: string,
    jobId: string,
    payload: LyricsCorrectionRequest,
  ): Promise<LyricsCorrectionStatus> {
    return this.request<LyricsCorrectionStatus>(`subtitle-jobs/${encodeURIComponent(jobId)}/lyrics`, installId, {
      method: 'POST',
      body: JSON.stringify(payload),
      timeoutMs: LEARNING_TOKEN_TIMEOUT_MS,
    }, guardLyricsCorrectionStatus);
  }

  public async getLyricsCorrectionStatus(
    installId: string,
    jobId: string,
  ): Promise<LyricsCorrectionStatus> {
    return this.request<LyricsCorrectionStatus>(`subtitle-jobs/${encodeURIComponent(jobId)}/lyrics`, installId, {
      method: 'GET',
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
    }, guardLyricsCorrectionStatus);
  }

  public async cancelLyricsCorrection(
    installId: string,
    jobId: string,
    payload: LyricsCorrectionCancelRequest,
  ): Promise<LyricsCorrectionStatus> {
    return this.request<LyricsCorrectionStatus>(`subtitle-jobs/${encodeURIComponent(jobId)}/lyrics`, installId, {
      method: 'DELETE',
      body: JSON.stringify(payload),
      timeoutMs: SUBTITLE_JOB_POLL_TIMEOUT_MS,
    }, guardLyricsCorrectionStatus);
  }

  public async quickFixToken(
    installId: string,
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
        },
      guardTrackResponse,
    );
  }

  private async request<TResponse>(
    path: string,
    installId: string,
    init: Pick<RequestInit, 'method' | 'body'> & { timeoutMs?: number },
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
        throw new SubtitleContractError(t("Backend returned invalid JSON."));
      }

      try {
        return guardResponse(body);
      } catch {
        throw new SubtitleContractError(t("Backend returned an invalid response. Update the extension and backend, then try again."));
      }
    } catch (error) {
      if (controller.signal.aborted || isAbortError(error)) {
        throw new TypeError(t("Backend request timed out."));
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

export function publicSubtitleErrorMessage(error: unknown, provider?: JobResponse['aiProvider']): string {
  if (error instanceof SubtitleContractError) return error.message;

  if (error instanceof SubtitleApiError) {
    if (provider === 'codex' && error.code === 'provider_not_configured') return t('Check your Codex connection and ElevenLabs API key in Settings.');
    if (provider === 'codex' && error.code === 'validation_failed') return t('Review the Codex model, fast mode, and video settings, then try again.');
    return messageForApiErrorCode(error.code);
  }

  if (error instanceof TypeError) {
    return t("Could not reach the subtitle backend. Make sure it is running and try again.");
  }

  return t("Unable to generate subtitles. Try again later.");
}

function messageForApiErrorCode(code: ApiError['error']['code']): string {
  switch (code) {
    case 'provider_unavailable':
      return t('The AI provider is unavailable. Check its connection in Settings and try again.');
    case 'provider_not_configured':
      return t("Add the required provider keys in Settings before generating subtitles.");

    case 'validation_failed':
      return t("The video details could not be validated. Refresh the YouTube tab and try again.");



    case 'unauthorized':
      return t("This backend is not available from this connection.");







    case 'unsupported_video':
    case 'audio_unavailable':
      return t("This video is not available for subtitle generation. Use a public non-live YouTube video.");


    case 'audio_acquisition_failed':
      return t("The backend could not extract audio from this video. Try another public video or check local backend setup.");

    case 'transcription_failed':
      return t("The AI transcription step failed. Try again later.");

    case 'enrichment_failed':
      return t("The AI subtitle analysis step failed. Try again later.");

    case 'rate_limited':
      return t("Subtitle generation is temporarily rate limited. Wait a minute and try again.");

    case 'not_found':
    case 'expired':
      return t("The generated subtitle track is no longer available. Generate subtitles again.");

    case 'internal_error':
      return t("The backend hit an unexpected error. Try again later.");

    case 'lyrics_correction_in_progress':
      return t("A pasted-lyrics correction is already in progress.");

    case 'lyrics_incomplete':
      return t("Paste the complete lyrics for the song. Your current subtitles are unchanged.");

    case 'lyrics_do_not_match':
      return t("These lyrics do not seem to match this song. Check the paste and try again.");

    case 'lyrics_correction_failed':
      return t("Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.");

    case 'generation_cancelled':
      return t("Generation cancelled.");

    case 'generation_not_cancellable':
      return t("This generation has already finished and cannot be cancelled.");
  }

  return t("Unable to generate subtitles. Try again later.");
}
