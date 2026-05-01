import type {
  ApiError,
  CreateSubtitleJobRequest,
  JobResponse,
} from './contracts';

export const DEFAULT_BACKEND_API_BASE_URL = 'http://localhost:8000/v1';

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
    const baseUrl = this.baseUrl.endsWith('/') ? this.baseUrl : `${this.baseUrl}/`;
    const response = await this.fetchImpl(new URL('subtitle-jobs', baseUrl).toString(), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Extension-Install-Id': installId,
      },
      body: JSON.stringify(payload),
    });

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

    return body as JobResponse;
  }
}
