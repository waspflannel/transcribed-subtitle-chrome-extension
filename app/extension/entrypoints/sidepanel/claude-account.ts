import { t } from '../../utils/i18n';
import type { ClaudeAccount } from '../../utils/contracts';
import type { PanelState } from '../../utils/messages';

export type ClaudeAction =
  | { type: 'panel.getClaudeAccount' | 'panel.loginClaude' | 'panel.disconnectClaude' }
  | { type: 'panel.submitClaudeCode'; code: string };

export function bindClaudeAccount(
  root: Document,
  request: (action: ClaudeAction) => Promise<ClaudeAccount>,
  onChange: (account: ClaudeAccount) => void,
  openAuthUrl: (url: string) => Promise<unknown>,
): { render(state: PanelState): void } {
  const status = root.querySelector<HTMLElement>('[data-claude-status]')!;
  const connect = root.querySelector<HTMLButtonElement>('[data-claude-connect]')!;
  const disconnect = root.querySelector<HTMLButtonElement>('[data-claude-disconnect]')!;
  const refresh = root.querySelector<HTMLButtonElement>('[data-claude-refresh]')!;
  const authorization = root.querySelector<HTMLFormElement>('[data-claude-authorization]')!;
  const link = root.querySelector<HTMLAnchorElement>('[data-claude-auth-link]')!;
  const code = authorization.elements.namedItem('claudeCode') as HTMLInputElement;
  const submit = authorization.querySelector<HTMLButtonElement>('button[type="submit"]')!;
  let account: ClaudeAccount | undefined;
  let error: string | undefined;
  let busy = false;
  let autoOpenRequested = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const loginStatus = (): string | undefined => account?.login?.status;
  const pending = (): boolean => ['pending', 'awaiting_code', 'verifying'].includes(loginStatus() ?? '');
  const draw = (): void => {
    status.textContent = t(error ?? (!account ? 'Loading Claude connection…'
      : !account.available ? 'Install Claude Code CLI 2.1.280 or newer on the backend.'
      : account.error ? 'Unable to load Claude. Check the backend and refresh.'
      : account.connected ? 'Claude connected.'
      : loginStatus() === 'awaiting_code' ? 'Waiting for the Claude sign-in code…'
      : pending() ? 'Checking Claude sign-in…'
      : loginStatus() === 'failed' ? 'Claude sign-in failed. Try signing in again.' : 'Claude is not connected.'));
    if (account?.connected && account.email) status.textContent += ` ${account.email}`;
    connect.disabled = busy || !account?.available || pending();
    connect.hidden = account?.connected === true;
    disconnect.disabled = busy;
    disconnect.hidden = !account || (!account.connected && !pending());
    refresh.disabled = busy;
    authorization.hidden = loginStatus() !== 'awaiting_code' || !account?.login?.authUrl;
    submit.disabled = busy;
    if (!authorization.hidden) link.href = account!.login!.authUrl!;
    else { link.removeAttribute('href'); code.value = ''; }
  };
  const schedule = (): void => {
    if (timer) clearTimeout(timer);
    // While the code box is open, only the user's submit moves the login forward.
    if (pending() && loginStatus() !== 'awaiting_code') timer = setTimeout(() => {
      if (root.visibilityState === 'hidden') schedule();
      else void perform({ type: 'panel.getClaudeAccount' });
    }, 2000);
  };
  const perform = async (action: ClaudeAction): Promise<void> => {
    if (busy) return;
    if (timer) clearTimeout(timer);
    busy = true;
    error = undefined;
    draw();
    try {
      account = await request(action);
      onChange(account);
      if (autoOpenRequested && loginStatus() === 'awaiting_code' && account.login?.authUrl) {
        autoOpenRequested = false;
        await openAuthUrl(account.login.authUrl).catch(() => {});
      }
      if (!pending()) autoOpenRequested = false;
    } catch (failure) {
      error = failure instanceof Error ? failure.message : 'Unable to load Claude. Check the backend and refresh.';
    } finally {
      busy = false;
      draw();
      schedule();
    }
  };
  connect.addEventListener('click', () => {
    autoOpenRequested = true;
    void perform({ type: 'panel.loginClaude' });
  });
  disconnect.addEventListener('click', () => {
    autoOpenRequested = false;
    void perform({ type: 'panel.disconnectClaude' });
  });
  authorization.addEventListener('submit', event => {
    event.preventDefault();
    const value = code.value.trim();
    if (value) void perform({ type: 'panel.submitClaudeCode', code: value });
  });
  refresh.addEventListener('click', () => void perform({ type: 'panel.getClaudeAccount' }));
  root.defaultView?.addEventListener('pagehide', () => { autoOpenRequested = false; if (timer) clearTimeout(timer); });
  return { render(state): void {
    if (busy) return;
    account = state.claudeAccount;
    error = state.claudeAccountError;
    draw();
    schedule();
  } };
}
