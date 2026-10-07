// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { bindCodexAccount } from '../entrypoints/sidepanel/codex-account';
import { guardCodexAccount } from '../utils/api-response-guards';
import { SubtitleApiClient } from '../utils/api';
import type { CodexAccount } from '../utils/contracts';
import type { PanelState } from '../utils/messages';
import { setInterfaceLocale, t } from '../utils/i18n';

const disconnected: CodexAccount = { available: true, connected: false, models: [], login: null };
const pending: CodexAccount = { ...disconnected, login: { status: 'awaiting_authorization', authUrl: 'https://auth.openai.com/oauth/authorize?client_id=test&state=test' } };
const connected: CodexAccount = { ...disconnected, connected: true, models: [{ id: 'test-model', name: 'Test', supportsFastMode: true }] };
afterEach(() => { vi.clearAllTimers(); vi.useRealTimers(); setInterfaceLocale('en'); });

it.each(['immediate', 'queued'])('opens browser login once after an explicit click (%s), then stops polling on callback', async arrival => {
  vi.useFakeTimers();
  document.documentElement.innerHTML = markup;
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
  const request = vi.fn().mockResolvedValueOnce(arrival === 'queued' ? { ...disconnected, login: { status: 'pending' } } : pending)
    .mockResolvedValueOnce(pending).mockResolvedValueOnce(pending).mockResolvedValueOnce(connected).mockResolvedValueOnce(disconnected);
  const onChange = vi.fn();
  const open = vi.fn(async () => {});
  const view = bindCodexAccount(document, request, onChange, open);
  view.render({ codexAccount: disconnected } as PanelState);
  document.querySelector<HTMLButtonElement>('[data-codex-connect]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(request).toHaveBeenLastCalledWith('panel.loginCodex');
  expect(open).toHaveBeenCalledTimes(arrival === 'queued' ? 0 : 1);
  await vi.advanceTimersByTimeAsync(2000);
  expect(open).toHaveBeenCalledExactlyOnceWith(pending.login?.authUrl);
  expect(document.querySelector('a[data-codex-auth-link]')?.getAttribute('href')).toBe(pending.login?.authUrl);
  expect(document.querySelector('[data-codex-code]')).toBeNull();
  await vi.advanceTimersByTimeAsync(2000);
  expect(open).toHaveBeenCalledTimes(1);
  await vi.advanceTimersByTimeAsync(2000);
  expect(onChange).toHaveBeenLastCalledWith(connected);
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe('Codex connected.');
  expect(document.querySelector('[data-codex-auth-link]')?.hasAttribute('href')).toBe(false);
  await vi.advanceTimersByTimeAsync(6000);
  expect(request).toHaveBeenCalledTimes(4);
  document.querySelector<HTMLButtonElement>('[data-codex-disconnect]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(request).toHaveBeenLastCalledWith('panel.disconnectCodex');
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe('Codex is not connected.');
});

it('never opens a tab for an existing attempt on passive render, refresh or return to the panel', async () => {
  vi.useFakeTimers();
  document.documentElement.innerHTML = markup;
  const request = vi.fn().mockResolvedValue(pending);
  const open = vi.fn(async () => {});
  const view = bindCodexAccount(document, request, vi.fn(), open);
  view.render({ codexAccount: pending } as PanelState);
  document.querySelector<HTMLButtonElement>('[data-codex-refresh]')!.click();
  await vi.advanceTimersByTimeAsync(4000);
  view.render({ codexAccount: connected } as PanelState);
  await vi.advanceTimersByTimeAsync(6000);
  expect(open).not.toHaveBeenCalled();
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe('Codex connected.');
});

it('discards legacy device-code state and keeps disconnect available while the backend is restarted', () => {
  document.documentElement.innerHTML = markup;
  const account = guardCodexAccount({ ...disconnected, login: { status: 'awaiting_authorization', verificationUrl: 'https://auth.openai.com/codex/device', userCode: 'ABCD-EFGH' } });
  const open = vi.fn(async () => {});
  bindCodexAccount(document, vi.fn(), vi.fn(), open).render({ codexAccount: account } as PanelState);
  expect(account.login).toEqual({ status: 'failed' });
  expect(JSON.stringify(account)).not.toContain('ABCD-EFGH');
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe('Restart the backend, then disconnect Codex and sign in again.');
  expect(document.querySelector<HTMLButtonElement>('[data-codex-disconnect]')!.hidden).toBe(false);
  expect(document.querySelector('[data-codex-auth-link]')?.hasAttribute('href')).toBe(false);
  expect(open).not.toHaveBeenCalled();
});

it('keeps a manual link when automatic opening fails and permits a fresh sign-in after disconnect', async () => {
  vi.useFakeTimers();
  document.documentElement.innerHTML = markup;
  const request = vi.fn().mockResolvedValue(pending);
  const open = vi.fn().mockRejectedValueOnce(new Error('Blocked')).mockResolvedValue(undefined);
  const view = bindCodexAccount(document, request, vi.fn(), open);
  view.render({ codexAccount: disconnected } as PanelState);
  document.querySelector<HTMLButtonElement>('[data-codex-connect]')!.click();
  await vi.advanceTimersByTimeAsync(4000);
  expect(open).toHaveBeenCalledTimes(1);
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe('Could not open the sign-in page. Use Open ChatGPT sign-in below.');
  expect(document.querySelector('a[data-codex-auth-link]')?.getAttribute('href')).toBe(pending.login?.authUrl);
  request.mockResolvedValueOnce(disconnected);
  document.querySelector<HTMLButtonElement>('[data-codex-disconnect]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  document.querySelector<HTMLButtonElement>('[data-codex-connect]')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(open).toHaveBeenCalledTimes(2);
});

it('shows failed login and a retry without continuing to poll', async () => {
  vi.useFakeTimers();
  document.documentElement.innerHTML = markup;
  const request = vi.fn().mockRejectedValue(new Error('Network unavailable'));
  const view = bindCodexAccount(document, request, vi.fn(), vi.fn(async () => {}));
  view.render({ codexAccount: { ...disconnected, login: { status: 'failed' } } } as PanelState);
  const connect = document.querySelector<HTMLButtonElement>('[data-codex-connect]')!;
  expect(connect.disabled).toBe(false);
  connect.click();
  await vi.advanceTimersByTimeAsync(5000);
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe('Network unavailable');
  expect(request).toHaveBeenCalledTimes(1);
  expect(connect.disabled).toBe(false);
});

it.each([false, true])('allows clearing stored credentials when the CLI check fails (available: %s)', async available => {
  document.documentElement.innerHTML = markup;
  const unavailable = { ...disconnected, available, error: 'Codex connection could not be checked.' };
  const request = vi.fn().mockResolvedValue(unavailable);
  const view = bindCodexAccount(document, request, vi.fn(), vi.fn(async () => {}));
  view.render({ codexAccount: unavailable } as PanelState);
  const disconnect = document.querySelector<HTMLButtonElement>('[data-codex-disconnect]')!;
  expect(disconnect.hidden).toBe(false);
  expect(disconnect.disabled).toBe(false);
  disconnect.click();
  await vi.waitFor(() => expect(request).toHaveBeenCalledWith('panel.disconnectCodex'));
});

it.each([false, true])('localizes summary failures while retaining recovery actions (available: %s)', available => {
  document.documentElement.innerHTML = markup;
  setInterfaceLocale('es');
  const rawError = 'Install Codex CLI 0.123.0 or newer on the backend and set CODEX_BINARY.';
  const view = bindCodexAccount(document, vi.fn(), vi.fn(), vi.fn(async () => {}));
  view.render({ codexAccount: { ...disconnected, available, error: rawError } } as PanelState);
  const message = available ? 'Unable to load Codex. Check the backend and refresh.'
    : 'Codex is unavailable. Install the Codex CLI on the backend and refresh.';
  expect(document.querySelector('[data-codex-status]')?.textContent).toBe(t(message));
  expect(t(message)).not.toBe(message);
  expect(document.querySelector('[data-codex-status]')?.textContent).not.toContain(rawError);
  expect(document.querySelector<HTMLButtonElement>('[data-codex-disconnect]')!.hidden).toBe(false);
  expect(document.querySelector<HTMLButtonElement>('[data-codex-connect]')!.disabled).toBe(!available);
});

it.each([
  ['getCodexAccount', 20000], ['disconnectCodex', 20000], ['loginCodex', 35000],
] as const)('allows bounded backend CLI checks before %s completes', async (method, delay) => {
  vi.useFakeTimers();
  const fetcher = vi.fn((_url: string | URL | Request, init?: RequestInit) => new Promise<Response>((resolve, reject) => {
    const timer = setTimeout(() => resolve(new Response(JSON.stringify(disconnected))), delay);
    init?.signal?.addEventListener('abort', () => { clearTimeout(timer); reject(new DOMException('Aborted', 'AbortError')); });
  }));
  const api = new SubtitleApiClient('http://localhost/v1', fetcher as typeof fetch);
  const response = api[method]('install_test');
  const result = expect(response).resolves.toEqual(disconnected);
  await vi.advanceTimersByTimeAsync(delay);
  await result;
});

it('uses separate account endpoints and rejects secrets or unsafe sign-in links', async () => {
  const fetcher = vi.fn(async (_url: string | URL | Request, _init?: RequestInit) => new Response(JSON.stringify(disconnected)));
  const api = new SubtitleApiClient('http://localhost:8001/v1', fetcher as typeof fetch);
  await api.getCodexAccount('install_test');
  await api.loginCodex('install_test');
  await api.disconnectCodex('install_test');
  expect(fetcher.mock.calls.map(call => [call[0], (call[1] as RequestInit).method])).toEqual([
    ['http://localhost:8001/v1/codex', 'GET'], ['http://localhost:8001/v1/codex/login', 'POST'], ['http://localhost:8001/v1/codex', 'DELETE'],
  ]);
  expect(guardCodexAccount(connected)).toEqual(connected);
  expect(guardCodexAccount({ ...pending, login: { ...pending.login, authUrl: 'https://chatgpt.com/auth/login' } }).login?.authUrl).toBe('https://chatgpt.com/auth/login');
  expect(guardCodexAccount({ ...pending, login: { status: 'awaiting_authorization', verificationUrl: 'https://auth.openai.com/codex/device', userCode: 'ABCD-EFGH' } }).login).toEqual({ status: 'failed' });
  for (const value of [{ ...connected, accessToken: 'secret' }, { ...pending, login: { ...pending.login, refreshToken: 'secret' } },
    ...['javascript:alert(1)', 'https://auth.openai.com.example.com/', 'https://user:pass@auth.openai.com/oauth/authorize', 'https://auth.openai.com:443/', 'https://chatgpt.com:8443/', 'https://chatgpt.com\\@example.com/', 'https://chatgpt.com/\n', 'https://chatgpt.com/' + 'a'.repeat(4096)].map(authUrl => ({ ...pending, login: { ...pending.login, authUrl } }))]) {
    expect(() => guardCodexAccount(value)).toThrow();
  }
});
