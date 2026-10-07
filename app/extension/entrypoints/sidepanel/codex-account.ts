import { t } from '../../utils/i18n';
import type { CodexAccount } from '../../utils/contracts';
import type { PanelState } from '../../utils/messages';

type CodexAction = 'panel.getCodexAccount' | 'panel.loginCodex' | 'panel.disconnectCodex';

export function bindCodexAccount(
  root: Document,
  request: (type: CodexAction) => Promise<CodexAccount>,
  onChange: (account: CodexAccount) => void,
  openAuthUrl: (url: string) => Promise<unknown>,
): { render(state: PanelState): void } {
  const status = root.querySelector<HTMLElement>('[data-codex-status]')!;
  const connect = root.querySelector<HTMLButtonElement>('[data-codex-connect]')!;
  const disconnect = root.querySelector<HTMLButtonElement>('[data-codex-disconnect]')!;
  const refresh = root.querySelector<HTMLButtonElement>('[data-codex-refresh]')!;
  const authorization = root.querySelector<HTMLElement>('[data-codex-authorization]')!;
  const link = root.querySelector<HTMLAnchorElement>('[data-codex-auth-link]')!;
  let account: CodexAccount | undefined;
  let error: string | undefined;
  let busy = false;
  let autoOpenRequested = false;
  let authorizationError: string | undefined;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const pending = (): boolean => account?.login?.status === 'pending' || account?.login?.status === 'awaiting_authorization';
  const draw = (): void => {
    status.textContent = t(error ?? authorizationError ?? (!account ? 'Loading Codex connection…'
      : !account.available ? 'Codex is unavailable. Install the Codex CLI on the backend and refresh.'
      : account.error === 'Restart the backend, then disconnect Codex and sign in again.' ? account.error
      : account.error ? 'Unable to load Codex. Check the backend and refresh.'
      : account.connected ? 'Codex connected.' : pending() ? 'Waiting for ChatGPT sign-in…'
      : account.login?.status === 'failed' ? 'Codex sign-in failed. Try signing in again.' : 'Codex is not connected.'));
    connect.disabled = busy || !account?.available || pending();
    connect.hidden = account?.connected === true;
    disconnect.disabled = busy;
    disconnect.hidden = !account || (!account.connected && !pending() && account.available && !account.error && !error);
    refresh.disabled = busy;
    authorization.hidden = !pending() || !account?.login?.authUrl;
    if (!authorization.hidden) link.href = account!.login!.authUrl!;
    else link.removeAttribute('href');
  };
  const schedule = (): void => {
    if (timer) clearTimeout(timer);
    if (pending()) timer = setTimeout(() => {
      if (root.visibilityState === 'hidden') schedule();
      else void perform('panel.getCodexAccount');
    }, 2000);
  };
  const perform = async (type: CodexAction): Promise<void> => {
    if (busy) return;
    if (timer) clearTimeout(timer);
    busy = true;
    error = undefined;
    draw();
    try {
      account = await request(type);
      if (!pending()) { autoOpenRequested = false; authorizationError = undefined; }
      onChange(account);
      if (autoOpenRequested && pending() && account.login?.authUrl) {
        autoOpenRequested = false;
        try { await openAuthUrl(account.login.authUrl); }
        catch { authorizationError = 'Could not open the sign-in page. Use Open ChatGPT sign-in below.'; }
      }
    } catch (failure) {
      error = failure instanceof Error ? failure.message : 'Unable to load Codex. Check the backend and refresh.';
    } finally {
      busy = false;
      draw();
      schedule();
    }
  };
  connect.addEventListener('click', () => {
    autoOpenRequested = true;
    authorizationError = undefined;
    void perform('panel.loginCodex');
  });
  disconnect.addEventListener('click', () => {
    autoOpenRequested = false;
    authorizationError = undefined;
    void perform('panel.disconnectCodex');
  });
  link.addEventListener('click', () => { autoOpenRequested = false; authorizationError = undefined; draw(); });
  refresh.addEventListener('click', () => void perform('panel.getCodexAccount'));
  root.defaultView?.addEventListener('pagehide', () => { autoOpenRequested = false; if (timer) clearTimeout(timer); });
  return { render(state): void {
    if (busy) return;
    account = state.codexAccount;
    if (!pending()) { autoOpenRequested = false; authorizationError = undefined; }
    error = state.codexAccountError;
    draw();
    schedule();
  } };
}
