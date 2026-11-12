/*
 * Sheets slide in over the panel like a phone navigation stack. The screen under
 * the top sheet slides left, dims and becomes inert. Back or Escape closes the top one.
 */
export interface Sheets {
  open(sheet: HTMLElement, invoker?: Element | null): void;
  close(): void;
  closeAll(): void;
  isOpen(sheet: HTMLElement): boolean;
}

const SLIDE_MS = 300;

/** False where the browser (or a test DOM) has no popover support. */
export function isPopoverOpen(element: Element): boolean {
  try {
    return element.matches(':popover-open');
  } catch {
    return false;
  }
}

export function bindSheets(root: Document, base: HTMLElement): Sheets {
  const stack: Array<{ sheet: HTMLElement; invoker: Element | null }> = [];
  const below = (): HTMLElement => stack.at(-1)?.sheet ?? base;

  const finishClose = (sheet: HTMLElement): void => {
    const done = (): void => { if (!sheet.classList.contains('open')) sheet.hidden = true; };
    if (root.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)').matches) done();
    else setTimeout(done, SLIDE_MS);
  };

  const api: Sheets = {
    open(sheet, invoker = root.activeElement) {
      if (stack.some(entry => entry.sheet === sheet)) return;
      const under = below();
      under.classList.add('covered');
      under.inert = true;
      stack.push({ sheet, invoker });
      sheet.hidden = false;
      void sheet.offsetWidth; // Commit the off-screen position so the slide runs.
      sheet.classList.add('open');
      sheet.querySelector<HTMLElement>('[data-action="close-sheet"]')?.focus({ preventScroll: true });
    },
    close() {
      const entry = stack.pop();
      if (!entry) return;
      entry.sheet.classList.remove('open');
      finishClose(entry.sheet);
      const under = below();
      under.classList.remove('covered');
      under.inert = false;
      if (entry.invoker instanceof HTMLElement && entry.invoker.isConnected) entry.invoker.focus({ preventScroll: true });
    },
    closeAll() {
      while (stack.length) api.close();
    },
    isOpen(sheet) {
      return stack.some(entry => entry.sheet === sheet);
    },
  };

  root.addEventListener('click', event => {
    if ((event.target as Element)?.closest?.('[data-action="close-sheet"]')) api.close();
  });
  root.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || !stack.length || event.defaultPrevented) return;
    if (root.querySelector('dialog[open]') || [...root.querySelectorAll('[popover]')].some(isPopoverOpen)) return;
    event.preventDefault();
    api.close();
  });
  return api;
}
