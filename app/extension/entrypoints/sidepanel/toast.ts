import { escapeHtml } from '../../utils/html';
import { t } from '../../utils/i18n';
import { icon } from '../../utils/panel/icons';

export interface ToastOptions {
  message: string;
  detail?: string;
  kind?: 'error' | 'success';
  /** A button such as "Open Settings". */
  action?: { label: string; run: () => void };
  /** Shows Undo with a countdown bar for `ms`. */
  undo?: { ms: number; run: () => void | Promise<void> };
  /** Runs when an undoable toast is closed or replaced before its countdown ends. */
  onSettle?: () => void;
}

/** One short message at a time in the status dock. Errors stay until dismissed. */
export function bindToast(element: HTMLElement) {
  let timer: ReturnType<typeof setTimeout> | undefined;
  let current: ToastOptions | null = null;

  const hide = (settle: boolean): void => {
    clearTimeout(timer);
    const previous = current;
    current = null;
    element.hidden = true;
    element.replaceChildren();
    if (settle && previous?.undo) previous.onSettle?.();
  };

  element.addEventListener('click', event => {
    const button = (event.target as Element)?.closest<HTMLElement>('[data-toast-action]');
    if (!button || !current) return;
    const options = current;
    const kind = button.dataset.toastAction;
    hide(kind === 'close');
    if (kind === 'undo') void options.undo?.run();
    if (kind === 'action') options.action?.run();
  });

  return {
    show(options: ToastOptions): void {
      hide(true);
      current = options;
      const ms = options.undo?.ms ?? (options.kind === 'error' ? 0 : 3200);
      element.className = `dock-card toast${options.kind ? ` ${options.kind}` : ''}`;
      element.innerHTML = `<span class="toast-msg">${escapeHtml(options.message)}${options.detail ? `<small>${escapeHtml(options.detail)}</small>` : ''}</span>
        ${options.undo ? `<button type="button" class="toast-action" data-toast-action="undo">${escapeHtml(t('Undo'))}</button>` : ''}
        ${options.action ? `<button type="button" class="toast-action" data-toast-action="action">${escapeHtml(options.action.label)}</button>` : ''}
        <button type="button" class="icon-btn sm" data-toast-action="close" aria-label="${escapeHtml(t('Dismiss'))}">${icon('close', 16)}</button>
        ${options.undo ? `<span class="toast-count" style="animation-duration:${ms}ms" aria-hidden="true"></span>` : ''}`;
      element.hidden = false;
      if (ms > 0) timer = setTimeout(() => hide(false), ms);
    },
    /** Close now. An undoable toast settles (its change becomes final). */
    dismiss(): void {
      hide(true);
    },
    hasUndo(): boolean {
      return Boolean(current?.undo);
    },
  };
}

export type Toast = ReturnType<typeof bindToast>;
