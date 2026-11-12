import { storage } from 'wxt/utils/storage';

/* History cards come from the backend, which only knows video IDs. The panel
   remembers the page titles it has seen so History can show real titles. */
const MAX_TITLES = 200;
const titlesStorage = storage.defineItem<Record<string, string>>('local:videoTitles', { fallback: {} });
let writes = Promise.resolve();

/** Tab title minus YouTube chrome ("(3) " unread prefix, " - YouTube" suffix). */
export function cleanYoutubeTitle(title: string | undefined): string {
  return (title ?? '').replace(/\s*-\s*YouTube\s*$/i, '').replace(/^\(\d+\)\s*/, '').trim();
}

export async function clearVideoTitles(): Promise<void> {
  const clear = writes.then(() => titlesStorage.removeValue());
  writes = clear.then(() => undefined, () => undefined);
  await clear;
}

export function getVideoTitles(): Promise<Record<string, string>> {
  return titlesStorage.getValue();
}

export async function rememberVideoTitle(videoId: string, rawTitle: string | undefined): Promise<void> {
  const title = cleanYoutubeTitle(rawTitle);
  // YouTube shows the bare "YouTube" title while a video page is still loading.
  if (!title || title === 'YouTube') return;
  const write = writes.then(async () => {
    const titles = await titlesStorage.getValue();
    if (titles[videoId] === title) return;
    delete titles[videoId];
    titles[videoId] = title;
    // ponytail: insertion order as LRU; fine for a few hundred entries.
    const keys = Object.keys(titles);
    for (const key of keys.slice(0, Math.max(0, keys.length - MAX_TITLES))) delete titles[key];
    await titlesStorage.setValue(titles);
  });
  writes = write.then(() => undefined, () => undefined);
  await write;
}
