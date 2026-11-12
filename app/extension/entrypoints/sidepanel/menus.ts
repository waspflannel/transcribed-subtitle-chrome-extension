import { isPopoverOpen } from './sheets';

/*
 * Native popover menus anchored under the button that opened them.
 * The browser handles light dismiss and Escape; we place the menu and keep
 * aria-expanded and focus in sync.
 */
export function openMenu(menu: HTMLElement, invoker: HTMLElement): void {
  if (typeof menu.showPopover !== 'function') return;
  // Clicking the button of an open menu: light dismiss already closed it on pointerdown.
  if (isPopoverOpen(menu) || Date.now() - Number(menu.dataset.closedAt ?? 0) < 250) {
    closeMenu(menu);
    return;
  }
  menu.showPopover();
  invoker.setAttribute('aria-expanded', 'true');
  const anchor = invoker.getBoundingClientRect();
  const bounds = (invoker.closest('.panel') ?? invoker.ownerDocument.body).getBoundingClientRect();
  const width = menu.offsetWidth;
  menu.style.top = `${Math.round(anchor.bottom + 6)}px`;
  menu.style.left = `${Math.round(Math.max(bounds.left + 8, Math.min(anchor.right - width, bounds.right - width - 8)))}px`;
  menu.querySelector<HTMLElement>('button:not(:disabled), input')?.focus({ preventScroll: true });
  menu.addEventListener('toggle', function onToggle(event) {
    if ((event as ToggleEvent).newState !== 'closed') return;
    menu.removeEventListener('toggle', onToggle);
    menu.dataset.closedAt = String(Date.now());
    invoker.setAttribute('aria-expanded', 'false');
    if (menu.contains(menu.ownerDocument.activeElement) || menu.ownerDocument.activeElement === menu.ownerDocument.body) {
      invoker.focus({ preventScroll: true });
    }
  });
}

export function closeMenu(menu: HTMLElement): void {
  if (typeof menu.hidePopover === 'function' && isPopoverOpen(menu)) menu.hidePopover();
}
