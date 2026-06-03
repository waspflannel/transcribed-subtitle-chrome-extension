export const LOCAL_BACKEND_API_BASE_URL = 'http://localhost:8000/v1';

export function resolveBackendApiBaseUrl(value?: string): string {
  const rawValue = value?.trim() || LOCAL_BACKEND_API_BASE_URL;
  const url = new URL(rawValue);

  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new TypeError('Backend API base URL must use http or https.');
  }

  const path = url.pathname === '/' ? '/v1' : url.pathname.replace(/\/$/, '');

  return `${url.origin}${path}`;
}

export function backendApiHostPermission(baseUrl: string): string {
  const url = new URL(resolveBackendApiBaseUrl(baseUrl));

  return `${url.protocol}//${url.host}/*`;
}
