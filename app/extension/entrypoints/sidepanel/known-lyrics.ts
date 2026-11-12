import { browser } from 'wxt/browser';
import { t } from '../../utils/i18n';

const prefix = 'knownLyrics:';
type KnownLyrics = { title: string; lyrics: string };

function isKnownLyrics(value: unknown): value is KnownLyrics {
  if (!value || typeof value !== 'object') return false;
  const entry = value as Record<string, unknown>;
  return typeof entry.title === 'string' && !!entry.title.trim() && entry.title.length <= 200
    && typeof entry.lyrics === 'string' && !!entry.lyrics.trim() && entry.lyrics.length <= 25000;
}

export interface KnownLyricsOptions {
  /** Show the Known Lyrics sheet. Defaults to un-hiding it in place. */
  open?: (panel: HTMLElement, invoker: HTMLElement) => void;
  /** Put saved lyrics into Lyric correction. Adds a "Use in correction" button. */
  onUse?: (lyrics: string) => void;
  /** A delete happened; `restore` puts the entry back (for Undo). */
  onDeleted?: (title: string, restore: () => Promise<void>) => void;
}

export function bindKnownLyrics(root: Document, options: KnownLyricsOptions = {}): void {
  const form = root.querySelector<HTMLFormElement>('[data-known-lyrics-form]')!;
  const title = form.elements.namedItem('title') as HTMLInputElement;
  const lyrics = form.elements.namedItem('lyrics') as HTMLTextAreaElement;
  const save = form.querySelector<HTMLButtonElement>('button')!;
  const list = root.querySelector<HTMLElement>('[data-known-lyrics-list]')!;
  const status = root.querySelector<HTMLElement>('[data-known-lyrics-status]')!;
  const create = root.querySelector<HTMLDetailsElement>('[data-known-lyrics-create]')!;
  let busy = false;
  let revision = 0;
  let noticeTimer: ReturnType<typeof setTimeout> | undefined;

  function announce(message: string, temporary = false): void {
    clearTimeout(noticeTimer);
    status.dataset.i18n = message;
    status.textContent = t(message);
    if (temporary) noticeTimer = setTimeout(() => announce(''), 2500);
  }

  async function refresh(): Promise<void> {
    const request = ++revision;
    try {
      // Read only Known Lyrics keys; remembered tracks can hold megabytes.
      const keys = (await browser.storage.local.getKeys()).filter(key => key.startsWith(prefix));
      const stored = keys.length > 0 ? await browser.storage.local.get(keys) : {};
      if (request !== revision) return;
      if (status.dataset.i18n === 'Could not load lyrics. Reopen Known Lyrics to retry.') announce('');
      const expanded = new Set([...list.querySelectorAll<HTMLDetailsElement>('details[open]')].map(details => details.dataset.key));
      list.replaceChildren();
      for (const [key, entry] of Object.entries(stored)) {
        if (!key.startsWith(prefix) || !isKnownLyrics(entry)) continue;
        const card = root.createElement('article');
        card.className = 'known-lyrics-entry';
        const details = root.createElement('details');
        details.dataset.key = key;
        details.open = expanded.has(key);
        const heading = root.createElement('summary');
        heading.textContent = entry.title;
        const preview = root.createElement('pre');
        preview.textContent = entry.lyrics;
        const actions = root.createElement('div');
        actions.className = 'known-actions';
        if (options.onUse) {
          const use = root.createElement('button');
          use.type = 'button';
          use.className = 'btn sm';
          use.dataset.i18n = 'Use in correction';
          use.textContent = t('Use in correction');
          use.addEventListener('click', () => options.onUse?.(entry.lyrics));
          actions.append(use);
        }
        for (const action of ['Copy', 'Delete'] as const) {
          const button = root.createElement('button');
          button.type = 'button';
          button.className = action === 'Delete' ? 'btn sm danger' : 'btn sm';
          button.dataset.i18n = action;
          button.textContent = t(action);
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              if (action === 'Copy') {
                await navigator.clipboard.writeText(entry.lyrics);
                announce('Lyrics copied.', true);
              } else {
                await browser.storage.local.remove(key);
                card.classList.add('leaving');
                if (options.onDeleted) {
                  options.onDeleted(entry.title, async () => {
                    await browser.storage.local.set({ [key]: entry });
                    await refresh();
                  });
                } else {
                  announce('Lyrics deleted.', true);
                }
                setTimeout(() => {
                  void refresh().then(() => (list.querySelector('summary') ?? create.querySelector('summary'))?.focus());
                }, 260);
              }
            } catch {
              if (action === 'Copy') details.open = true;
              announce(action === 'Copy' ? 'Could not copy lyrics. Select the text and copy it manually.' : 'Could not delete lyrics. Try again.');
            } finally {
              button.disabled = false;
            }
          });
          actions.append(button);
        }
        details.append(heading, preview);
        card.append(details, actions);
        list.append(card);
      }
      if (!list.childElementCount) {
        const empty = root.createElement('p');
        empty.dataset.i18n = 'No saved lyrics yet.';
        empty.textContent = t('No saved lyrics yet.');
        list.append(empty);
      }
    } catch {
      if (request === revision) announce('Could not load lyrics. Reopen Known Lyrics to retry.');
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const entry = { title: title.value.trim(), lyrics: lyrics.value };
    if (busy) return;
    if (!isKnownLyrics(entry)) {
      announce('Enter a title and lyrics (up to 25,000 characters).');
      return;
    }
    busy = true;
    save.disabled = title.disabled = lyrics.disabled = true;
    try {
      // Separate keys keep saves from different browser windows from overwriting one another.
      await browser.storage.local.set({ [`${prefix}${crypto.randomUUID()}`]: entry });
      form.reset();
      create.open = false;
      create.querySelector('summary')!.focus();
      announce('Lyrics saved.', true);
      await refresh();
    } catch {
      announce('Could not save lyrics. Your draft is still here. Try again.');
    } finally {
      busy = false;
      save.disabled = title.disabled = lyrics.disabled = false;
    }
  });

  const panel = root.querySelector<HTMLElement>('#panel-known-lyrics')!;
  for (const toggle of root.querySelectorAll<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')) {
    toggle.addEventListener('click', () => {
      announce('');
      if (options.open) options.open(panel, toggle);
      else panel.hidden = false;
      void refresh();
    });
  }
}
