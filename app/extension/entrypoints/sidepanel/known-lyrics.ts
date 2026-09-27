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

export function bindKnownLyrics(root: Document): void {
  const form = root.querySelector<HTMLFormElement>('[data-known-lyrics-form]')!;
  const title = form.elements.namedItem('title') as HTMLInputElement;
  const lyrics = form.elements.namedItem('lyrics') as HTMLTextAreaElement;
  const save = form.querySelector<HTMLButtonElement>('button')!;
  const list = root.querySelector<HTMLElement>('[data-known-lyrics-list]')!;
  const status = root.querySelector<HTMLElement>('[data-known-lyrics-status]')!;
  let busy = false;
  let revision = 0;

  function announce(message: string): void {
    status.dataset.i18n = message;
    status.textContent = t(message);
  }

  async function refresh(): Promise<void> {
    const request = ++revision;
    try {
      const stored = await browser.storage.local.get(null);
      if (request !== revision) return;
      if (status.dataset.i18n === 'Could not load lyrics. Reopen Known Lyrics to retry.') announce('');
      list.replaceChildren();
      for (const [key, entry] of Object.entries(stored)) {
        if (!key.startsWith(prefix) || !isKnownLyrics(entry)) continue;
        const card = root.createElement('article');
        card.className = 'card known-lyrics-entry';
        const heading = root.createElement('h3');
        heading.textContent = entry.title;
        const preview = root.createElement('pre');
        preview.textContent = entry.lyrics;
        const actions = root.createElement('div');
        actions.className = 'transcript-actions';
        for (const action of ['Copy', 'Delete'] as const) {
          const button = root.createElement('button');
          button.type = 'button';
          button.className = 'btn-ghost';
          button.dataset.i18n = action;
          button.textContent = t(action);
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              if (action === 'Copy') {
                await navigator.clipboard.writeText(entry.lyrics);
                announce('Lyrics copied.');
              } else {
                await browser.storage.local.remove(key);
                await refresh();
                announce('Lyrics deleted.');
                title.focus();
              }
            } catch {
              announce(action === 'Copy' ? 'Could not copy lyrics. Select the text and copy it manually.' : 'Could not delete lyrics. Try again.');
            } finally {
              button.disabled = false;
            }
          });
          actions.append(button);
        }
        card.append(heading, preview, actions);
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
      announce('Lyrics saved.');
      await refresh();
    } catch {
      announce('Could not save lyrics. Your draft is still here. Try again.');
    } finally {
      busy = false;
      save.disabled = title.disabled = lyrics.disabled = false;
    }
  });

  const toggle = root.querySelector<HTMLButtonElement>('[data-action="toggle-known-lyrics"]')!;
  const panel = root.querySelector<HTMLElement>('#panel-known-lyrics')!;
  toggle.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    toggle.setAttribute('aria-expanded', String(!panel.hidden));
    if (!panel.hidden) void refresh();
  });
}
