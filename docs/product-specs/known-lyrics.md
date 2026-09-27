# Known Lyrics

Known Lyrics is a button beside Lyric correction in the completed transcript toolbar. It toggles an inline panel for saved lyrics. It is hidden during initial setup and partial generation, along with the transcript toolbar.

- Add lyrics opens a collapsed creation form; successful saves collapse it again. Saved entries show their title and Copy/Delete actions, with a native disclosure for the full text.
- Copy/save/delete success notices clear after 2.5 seconds. Errors stay visible; copy errors expand the lyric for manual copying. Closing the panel clears its notice without losing drafts.
- Create an entry with a song title (up to 200 characters) and pasted lyrics (up to 25,000 characters). Empty or whitespace-only fields cannot be saved.
- Entries persist in this browser's extension local storage across panel restarts and videos. They are shared within this browser profile, not synced to an account.
- Copy preserves the pasted text and line breaks for use in Lyric correction or elsewhere. Each entry also has Delete.
- Storage failures keep the draft or saved entry available to retry. Clipboard failures explain how to select and copy the visible text manually.
- Entries use separate storage keys so simultaneous saves from separate windows do not overwrite one another. Reopening the panel refreshes the list.
- Removing the extension removes these local entries. Saving lyrics does not start generation or apply a correction.

Validation: `app/extension/tests/known-lyrics.test.ts` covers persistence, copy, deletion, validation, safe text rendering, and failure recovery.
