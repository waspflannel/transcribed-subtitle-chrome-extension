import { describe, expect, it } from 'vitest';

import {
  LOCAL_BACKEND_API_BASE_URL,
  backendApiHostPermission,
  resolveBackendApiBaseUrl,
} from '../utils/api-config';

describe('backend API config', () => {
  it('defaults to the local Laravel API for development builds', () => {
    expect(resolveBackendApiBaseUrl()).toBe(LOCAL_BACKEND_API_BASE_URL);
    expect(backendApiHostPermission(resolveBackendApiBaseUrl())).toBe('http://localhost:8000/*');
  });

  it('normalizes production API base URLs and derives exact host permissions', () => {
    expect(resolveBackendApiBaseUrl('https://api.example.test/v1/')).toBe('https://api.example.test/v1');
    expect(backendApiHostPermission('https://api.example.test/v1/')).toBe('https://api.example.test/*');
  });

  it('uses v1 when only an API origin is provided', () => {
    expect(resolveBackendApiBaseUrl('https://api.example.test')).toBe('https://api.example.test/v1');
  });

  it('rejects unsupported URL protocols', () => {
    expect(() => resolveBackendApiBaseUrl('chrome-extension://abc/v1')).toThrow(TypeError);
  });
});
