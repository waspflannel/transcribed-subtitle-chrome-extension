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

  public createSubtitleJob(installId: string, payload: CreateSubtitleJobRequest): Promise<JobResponse> {
    return this.requestJson<JobResponse>('subtitle-jobs', installId, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  private async requestJson<T>(
    path: string,
    installId: string,
    options: { method?: string; body?: string } = {},
  ): Promise<T> {
    const response = await this.fetchImpl(this.urlFor(path), {
      method: options.method ?? 'GET',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Extension-Install-Id': installId,
      },
      body: options.body,
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

    return body as T;
  }

  private urlFor(path: string): string {
    const baseUrl = this.baseUrl.endsWith('/') ? this.baseUrl : `${this.baseUrl}/`;

    return new URL(path.replace(/^\/+/, ''), baseUrl).toString();
  }
}
