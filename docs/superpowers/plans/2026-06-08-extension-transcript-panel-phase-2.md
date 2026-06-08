# Extension Transcript-in-Panel — Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the generated-transcript UI off the on-video overlay and into the side panel as a fifth rail destination, with a live active-cue highlight and click-to-seek driven through the background message hub.

**Architecture:** The content script already computes the active cue (`onCueChange`) and owns the video. Phase 2 relays active-cue changes to the panel via the background hub (`content.activeCueChanged → background.activeCueChanged`) and routes the panel's jump/replay back to the content script (`popup.seekToCue → background.seekToCue`). The transcript rendering/search logic moves out of `utils/overlay/overlay-render.ts` into a panel renderer; the overlay keeps only the caption rail + word card. The overlay's transcript tests migrate to the panel.

**Tech Stack:** WXT, TypeScript, Vitest, Chrome `side_panel` / Firefox `sidebar_action`. Builds on Phase 1 (merged to `main`).

**Spec:** `docs/superpowers/specs/2026-06-07-extension-sidepanel-redesign-design.md` — see "Cross-surface wiring (transcript migration)" and "Phasing → Phase 2".

---

## Design decisions (settled)

- **Transcript becomes the 5th rail destination** (Generate · Study · **Transcript** · Jobs · Account), removed entirely from the on-video overlay. The overlay keeps the caption rail + word card.
- **Active-cue relay (hub):** content `onCueChange` → `content.activeCueChanged` → background re-broadcasts `background.activeCueChanged` (via `browser.runtime.sendMessage`, which reaches the open panel). The panel highlights the matching cue. The content keeps its own rail behavior unchanged.
- **Seek (hub):** panel jump/replay → `popup.seekToCue` → background forwards `background.seekToCue` to the active tab's content script (`sendTabMessage`) → content runs its existing `jumpToCue`/`replayCue`.
- **Copy / Save in the panel transcript** are panel-local (clipboard / the existing Phase-02 save placeholder) — no messaging needed; the panel already has the cues.
- **`toggle-transcript` shortcut (S)** is repointed: content broadcasts `content.focusPanelTranscript` → `background.focusTranscript` → the panel switches to the Transcript view and focuses search **if it is open**. Opening a closed panel from the page is out of scope (gesture limitation; the toolbar icon opens it).
- **Tests migrate:** the three overlay transcript cases in `tests/overlay.test.ts` are removed; equivalent coverage is added for the panel renderer.

## Working agreements

- Run commands from `app/extension/`. Gates: `npm test`, `npm run compile`, `npm run build` (and `npm run build -- -b firefox`).
- Keep the namespaced message convention: `content.*`/`popup.*` are sent **to** the background; `background.*` are sent **from** the background to content (`tabs.sendMessage`) or to the panel (`runtime.sendMessage`).
- Do not retune colors (Phase-1 brand tokens stand).

## File structure

**Create**
- `app/extension/utils/panel/transcript.ts` — pure transcript helpers relocated from the overlay: `filterTranscriptCues(cues, query)` and `panelTranscriptListHtml({ cues, activeCueId, query, settings })`.
- `app/extension/tests/panel-transcript.test.ts`
- `app/extension/entrypoints/sidepanel/transcript-view.ts` — binds the Transcript view DOM (search input, list delegation → seek/copy/save, applying the active-cue highlight).

**Modify**
- `app/extension/utils/messages.ts` (+ `tests/messages.test.ts`) — add the 6 message types + `isRuntimeMessage` validation.
- `app/extension/entrypoints/background.ts` — handle `content.activeCueChanged`, `popup.seekToCue`, `content.focusPanelTranscript`.
- `app/extension/entrypoints/content.ts` — emit `content.activeCueChanged`; handle `background.seekToCue`; repoint the `toggle-transcript` shortcut; drop the overlay transcript callbacks.
- `app/extension/utils/overlay.ts` — remove `toggleTranscript`/`setTranscriptOpen` and transcript callbacks from `OverlayShell`.
- `app/extension/utils/overlay/overlay-render.ts` — remove the transcript render/search functions; `renderFrame` returns just the rail.
- `app/extension/utils/overlay/overlay-styles.ts` — remove `.transcript-*` styles.
- `app/extension/utils/overlay/types.ts` — drop `transcriptOpen`/`transcriptSearchQuery`/`transcriptStatus` from `OverlayInteractionState`.
- `app/extension/tests/overlay.test.ts` — remove the three transcript cases.
- `app/extension/utils/panel/view-state.ts` — add `'transcript'` to the `PanelView` union (not returned by `selectDefaultView`).
- `app/extension/entrypoints/sidepanel/index.html` — Transcript rail button + Transcript view section.
- `app/extension/entrypoints/sidepanel/dom.ts` — Transcript view selectors.
- `app/extension/entrypoints/sidepanel/main.ts` — `runtime.onMessage` listener for `background.activeCueChanged`/`background.focusTranscript`; mount the transcript view; render cues on `ready` state.
- `docs/FRONTEND.md` — transcript now lives in the panel.

---

## Task 1: Message contract for relay + seek (TDD)

**Files:**
- Modify: `app/extension/utils/messages.ts`
- Test: `app/extension/tests/messages.test.ts`

- [ ] **Step 1: Add the failing validation tests**

Append to `tests/messages.test.ts` (mirror the file's existing `isRuntimeMessage` test style):
```ts
describe('isRuntimeMessage — phase 2 transcript relay', () => {
  it('accepts content.activeCueChanged with a cueId or null', () => {
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v' })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: null, youtubeVideoId: 'v' })).toBe(true);
    expect(isRuntimeMessage({ type: 'content.activeCueChanged', cueId: 'cue-1' })).toBe(false);
  });

  it('accepts background.activeCueChanged', () => {
    expect(isRuntimeMessage({ type: 'background.activeCueChanged', cueId: 'cue-1', youtubeVideoId: 'v' })).toBe(true);
  });

  it('accepts popup.seekToCue and background.seekToCue with a valid mode', () => {
    expect(isRuntimeMessage({ type: 'popup.seekToCue', cueId: 'cue-1', mode: 'jump' })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.seekToCue', cueId: 'cue-1', mode: 'replay' })).toBe(true);
    expect(isRuntimeMessage({ type: 'popup.seekToCue', cueId: 'cue-1', mode: 'nope' })).toBe(false);
  });

  it('accepts the transcript-focus signals', () => {
    expect(isRuntimeMessage({ type: 'content.focusPanelTranscript' })).toBe(true);
    expect(isRuntimeMessage({ type: 'background.focusTranscript' })).toBe(true);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- messages`
Expected: FAIL (new cases fail; the unknown types return `false`).

- [ ] **Step 3: Extend the `RuntimeMessage` union**

In `utils/messages.ts`, add to the `RuntimeMessage` union:
```ts
  | { type: 'content.activeCueChanged'; cueId: string | null; youtubeVideoId: string }
  | { type: 'background.activeCueChanged'; cueId: string | null; youtubeVideoId: string }
  | { type: 'popup.seekToCue'; cueId: string; mode: 'jump' | 'replay' }
  | { type: 'background.seekToCue'; cueId: string; mode: 'jump' | 'replay' }
  | { type: 'content.focusPanelTranscript' }
  | { type: 'background.focusTranscript' }
```

- [ ] **Step 4: Extend `isRuntimeMessage`**

Add cases to the `switch` in `isRuntimeMessage`:
```ts
    case 'content.focusPanelTranscript':
    case 'background.focusTranscript':
      return true;

    case 'content.activeCueChanged':
    case 'background.activeCueChanged':
      return (value.cueId === null || hasString(value, 'cueId')) && hasString(value, 'youtubeVideoId');

    case 'popup.seekToCue':
    case 'background.seekToCue':
      return hasString(value, 'cueId') && (value.mode === 'jump' || value.mode === 'replay');
```
(`hasString` already exists in the file.)

- [ ] **Step 5: Run tests + compile**

Run: `npm test -- messages` (PASS) then `npm run compile` (clean).

- [ ] **Step 6: Commit**
```bash
git add app/extension/utils/messages.ts app/extension/tests/messages.test.ts
git commit -m "feat(extension): add transcript relay + seek message types"
```

---

## Task 2: Panel transcript renderer (TDD)

Relocate the overlay's transcript list rendering + search into a panel module, using the panel's sharp classes (the prototype's `.cue`/`.tc`/`.ct`/`.cr`/`.cg`).

**Files:**
- Create: `app/extension/utils/panel/transcript.ts`
- Test: `app/extension/tests/panel-transcript.test.ts`
- Reference: the transcript functions currently in `utils/overlay/overlay-render.ts` (`filteredTranscriptCues`, `transcriptSearchText`, `renderTranscriptCue`) — copy the *logic*, restyle the markup.

- [ ] **Step 1: Write the failing test**

Create `tests/panel-transcript.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { SubtitleCue } from '../utils/contracts';
import { filterTranscriptCues, panelTranscriptListHtml } from '../utils/panel/transcript';

const cues: SubtitleCue[] = [
  { cueId: 'c1', index: 0, startMs: 500, endMs: 2100, sourceText: 'hola', translatedText: 'hello', romanization: 'o-la', tokens: [{ index: 0, text: 'hola', normalizedText: 'hola' }] },
  { cueId: 'c2', index: 1, startMs: 2600, endMs: 4200, sourceText: 'adios', translatedText: 'goodbye', romanization: 'a-dios', tokens: [{ index: 0, text: 'adios', normalizedText: 'adios' }] },
];

describe('filterTranscriptCues', () => {
  it('returns all cues for an empty query', () => {
    expect(filterTranscriptCues(cues, '').length).toBe(2);
  });
  it('matches source, romanization, and translation text', () => {
    expect(filterTranscriptCues(cues, 'goodbye').map((c) => c.cueId)).toEqual(['c2']);
    expect(filterTranscriptCues(cues, 'o-la').map((c) => c.cueId)).toEqual(['c1']);
  });
});

describe('panelTranscriptListHtml', () => {
  it('renders rows with timecode, source, and the active-cue marker', () => {
    const html = panelTranscriptListHtml({ cues, activeCueId: 'c2', query: '', settings: { ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true } });
    expect(html).toContain('data-cue-id="c1"');
    expect(html).toContain('data-cue-id="c2"');
    expect(html).toContain('hola');
    expect(html).toContain('goodbye');
    expect(html).toContain('aria-current="true"'); // on c2
    expect(html).toContain('data-transcript-action="jump"');
    expect(html).toContain('data-transcript-action="replay"');
  });
  it('shows an empty-state when the query matches nothing', () => {
    expect(panelTranscriptListHtml({ cues, activeCueId: null, query: 'zzz', settings: DEFAULT_EXTENSION_SETTINGS })).toContain('No cues match');
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- panel-transcript`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement `utils/panel/transcript.ts`**

```ts
import type { SubtitleCue } from '../contracts';
import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';

export function filterTranscriptCues(cues: readonly SubtitleCue[], query: string): SubtitleCue[] {
  const q = query.trim().toLowerCase();
  if (q === '') return [...cues];
  return cues.filter((cue) => transcriptSearchText(cue).includes(q));
}

function transcriptSearchText(cue: SubtitleCue): string {
  return [
    cue.sourceText,
    cue.romanization ?? '',
    cue.translatedText,
    ...cue.tokens.flatMap((t) => [t.text, t.normalizedText, t.romanization ?? '', t.gloss ?? '', t.translation ?? '']),
  ].join(' ').toLowerCase();
}

function timecode(ms: number): string {
  const s = Math.max(0, Math.floor(ms / 1000));
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

export function panelTranscriptListHtml(input: {
  cues: readonly SubtitleCue[];
  activeCueId: string | null;
  query: string;
  settings: ExtensionSettings;
}): string {
  const cues = filterTranscriptCues(input.cues, input.query);
  if (cues.length === 0) {
    return '<p class="transcript-empty muted">No cues match that search.</p>';
  }
  return cues.map((cue) => transcriptRow(cue, cue.cueId === input.activeCueId, input.settings)).join('');
}

function transcriptRow(cue: SubtitleCue, active: boolean, settings: ExtensionSettings): string {
  const rom = settings.showRomanization && cue.romanization
    ? `<div class="cr">${escapeHtml(cue.romanization)}</div>` : '';
  const tr = settings.showTranslation && cue.translatedText.trim() !== cue.sourceText.trim()
    ? `<div class="cg">${escapeHtml(cue.translatedText)}</div>` : '';
  return `
    <article class="cue${active ? ' on' : ''}" role="listitem" aria-current="${active ? 'true' : 'false'}" data-cue-id="${escapeHtml(cue.cueId)}">
      <div class="tc">${escapeHtml(timecode(cue.startMs))}</div>
      <div class="cbody">
        <div class="ct">${escapeHtml(cue.sourceText)}</div>
        ${rom}
        ${tr}
        <div class="cue-actions">
          <button type="button" class="cue-action" data-transcript-action="jump" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Jump to cue ${cue.index + 1}">Jump</button>
          <button type="button" class="cue-action" data-transcript-action="replay" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Replay cue ${cue.index + 1}">Replay</button>
          <button type="button" class="cue-action" data-transcript-action="copy" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Copy cue ${cue.index + 1}">Copy</button>
          <button type="button" class="cue-action" data-transcript-action="save" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Save cue ${cue.index + 1}">Save</button>
        </div>
      </div>
    </article>`;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npm test -- panel-transcript` (PASS) then `npm run compile` (clean).

- [ ] **Step 5: Commit**
```bash
git add app/extension/utils/panel/transcript.ts app/extension/tests/panel-transcript.test.ts
git commit -m "feat(extension): panel transcript renderer + cue filtering"
```

---

## Task 3: Background — relay active cue, forward seek, relay focus

**Files:**
- Modify: `app/extension/entrypoints/background.ts` (the `handleRuntimeMessage` switch at ~line 75; uses existing helpers `sendTabMessage(tabId, msg)` and the active-tab query pattern).

- [ ] **Step 1: Add handler cases**

In `handleRuntimeMessage`'s `switch (message.type)`, add:
```ts
    case 'content.activeCueChanged':
      // Re-broadcast to extension pages (the open side panel). runtime.sendMessage
      // reaches the panel but not content scripts, so this won't echo back to content.
      void browser.runtime.sendMessage({
        type: 'background.activeCueChanged',
        cueId: message.cueId,
        youtubeVideoId: message.youtubeVideoId,
      }).catch(() => {});
      return { ok: true };

    case 'content.focusPanelTranscript':
      void browser.runtime.sendMessage({ type: 'background.focusTranscript' }).catch(() => {});
      return { ok: true };

    case 'popup.seekToCue': {
      const tab = await activeYoutubeTabId();
      if (tab !== null) {
        await sendTabMessage(tab, { type: 'background.seekToCue', cueId: message.cueId, mode: message.mode });
      }
      return { ok: true };
    }
```

- [ ] **Step 2: Add the active-tab resolver if one isn't already reusable**

If the file lacks a single helper that returns the active YouTube tab id, add one near the other tab helpers (reuse the existing `browser.tabs.query({ active: true, currentWindow: true })` pattern already used around lines 124/404):
```ts
async function activeYoutubeTabId(): Promise<number | null> {
  const [activeTab] = await browser.tabs.query({ active: true, currentWindow: true });
  return activeTab?.id ?? null;
}
```
If an equivalent already exists, call that instead.

- [ ] **Step 3: Compile + targeted check**

Run: `npm run compile` (clean). Grep to confirm the cases exist:
```bash
grep -n "content.activeCueChanged\|popup.seekToCue\|content.focusPanelTranscript" entrypoints/background.ts
```

- [ ] **Step 4: Commit**
```bash
git add app/extension/entrypoints/background.ts
git commit -m "feat(extension): background relays active cue, focus, and seek"
```

---

## Task 4: Content — emit active cue, handle seek, repoint shortcut, drop overlay transcript callbacks

**Files:**
- Modify: `app/extension/entrypoints/content.ts`

- [ ] **Step 1: Emit `content.activeCueChanged` on cue change**

In `bindGeneratedSubtitles`, the `onCueChange` callback currently does `activeCue = change.activeCue; updateOverlay();`. Add a relay (guard against unsupported pages):
```ts
        onCueChange(change) {
          activeCue = change.activeCue;
          updateOverlay();
          const page = parseYoutubePage(window.location.href);
          if (page.supported) {
            void browser.runtime.sendMessage({
              type: 'content.activeCueChanged',
              cueId: change.activeCue?.cueId ?? null,
              youtubeVideoId: page.videoId,
            }).catch(() => {});
          }
        },
```

- [ ] **Step 2: Handle `background.seekToCue`**

In `handleRuntimeMessage`, add before the final `return false;`:
```ts
      if (message.type === 'background.seekToCue') {
        if (subtitleState.type === 'ready') {
          const cue = subtitleState.track.cues.find((c) => c.cueId === message.cueId);
          if (cue) {
            if (message.mode === 'replay') replayCue(cue); else jumpToCue(cue);
          }
        }
        sendResponse({ ok: true });
        return false;
      }
```

- [ ] **Step 3: Repoint the `toggle-transcript` shortcut**

Replace the `case 'toggle-transcript'` block in `handleShortcutAction` with a panel-focus broadcast (the overlay no longer has a transcript):
```ts
        case 'toggle-transcript':
          void browser.runtime.sendMessage({ type: 'content.focusPanelTranscript' }).catch(() => {});
          overlay.showActionStatus('Transcript is in the side panel.', 'info');
          return;
```

- [ ] **Step 4: Drop the overlay transcript callbacks**

In the `new OverlayShell(document, { ... })` options, remove the two **transcript-only** callbacks `onJumpCue` and `onSaveCue`. **Keep `onCopyCue` and `onReplayCue`** — the caption rail's own study controls (`data-study-control="copy"`/`"replay"`, covered by the overlay "copy feedback status" test) use them on the active cue. Also remove the `overlay.setTranscriptOpen(false)` call in `clearBoundWebVttTrack`. Keep the `jumpToCue`/`replayCue` functions themselves — the prev/next shortcuts and the new `background.seekToCue` handler use them.

- [ ] **Step 5: Compile**

Run: `npm run compile`. Fix references to any removed `OverlayShell` option (Task 5 removes the matching types so they line up).

- [ ] **Step 6: Commit** (commit together with Task 5 since the `OverlayShell` interface changes span both — see Task 5 Step 5.)

---

## Task 5: Remove the on-video transcript (overlay) + migrate its tests

**Files:**
- Modify: `app/extension/utils/overlay.ts`, `utils/overlay/overlay-render.ts`, `utils/overlay/overlay-styles.ts`, `utils/overlay/types.ts`
- Modify: `app/extension/tests/overlay.test.ts`

- [ ] **Step 1: Remove transcript from the renderer**

In `overlay-render.ts`: change `renderFrame` to return just the rail (drop the `renderTranscriptPanel(...)` concatenation). Delete `renderTranscriptPanel`, `renderTranscriptCueList`, `renderTranscriptCue`, `transcriptActionButton`, `filteredTranscriptCues`, `transcriptSearchText`. Keep everything else (rail, tokens, word card, study controls, shell).

- [ ] **Step 2: Remove transcript from `OverlayShell` + types**

In `utils/overlay.ts`: remove `toggleTranscript`, `setTranscriptOpen`, the `onJumpCue`/`onSaveCue` options, and their DOM wiring inside the shell (the transcript search input listener and the `data-transcript-action`/`data-transcript-search`/`data-transcript-close` click handling). **Keep `onCopyCue`/`onReplayCue`** (the rail's study controls use them). In `utils/overlay/types.ts`: remove `transcriptOpen`, `transcriptSearchQuery`, `transcriptStatus` from `OverlayInteractionState`.

- [ ] **Step 3: Remove transcript styles**

In `overlay-styles.ts`: delete the `.transcript-*` rule blocks. Leave rail/token/word-card/study-control styles intact.

- [ ] **Step 4: Migrate the overlay tests**

In `tests/overlay.test.ts`: delete the three transcript cases — `'renders transcript cues with active state, metadata, and accessible controls'`, `'filters transcript cues by source, romanization, translation, and token text'`, and `'can show the transcript while the caption rail is hidden'`. (Their coverage now lives in `tests/panel-transcript.test.ts` from Task 2.) Remove any now-unused `transcriptOpen` fields from the remaining test helpers.

- [ ] **Step 5: Verify the overlay contract still holds, then commit Tasks 4+5 together**

Run: `npm test -- overlay` (the remaining ~10 rail/token/word-card/progress/copy cases pass), `npm test` (full suite green), `npm run compile` (clean), `npm run build` (succeeds).
```bash
git add app/extension/entrypoints/content.ts app/extension/utils/overlay.ts app/extension/utils/overlay/overlay-render.ts app/extension/utils/overlay/overlay-styles.ts app/extension/utils/overlay/types.ts app/extension/tests/overlay.test.ts
git commit -m "refactor(extension): remove on-video transcript; route transcript to the panel"
```

---

## Task 6: Panel Transcript view — markup, selectors, rail icon

**Files:**
- Modify: `app/extension/entrypoints/sidepanel/index.html`, `entrypoints/sidepanel/dom.ts`, `utils/panel/view-state.ts`
- Modify: `app/extension/entrypoints/sidepanel/style.css` (transcript-view styles)

- [ ] **Step 1: Add `'transcript'` to the `PanelView` type**

In `utils/panel/view-state.ts`, change the union to `'generate' | 'study' | 'transcript' | 'jobs' | 'account'`. `selectDefaultView` is unchanged (never returns `'transcript'`).

- [ ] **Step 2: Add the rail button + view section**

In `index.html`, add a Transcript rail button (copy the transcript SVG from `docs/design-assets/extension-sidepanel-redesign/prototype.html`) between Study and the `.rspacer`, with `data-tab="transcript" aria-controls="panel-transcript"`. Add the section:
```html
<section class="view" data-panel="transcript" id="panel-transcript">
  <div class="sec">
    <label class="search-field">
      <input type="search" data-transcript-search placeholder="Search transcript…" autocomplete="off" aria-label="Search transcript" />
    </label>
  </div>
  <div class="transcript-list" data-transcript-list role="list" aria-label="Generated cues"></div>
  <p class="transcript-status muted" data-transcript-status role="status" aria-live="polite"></p>
</section>
```

- [ ] **Step 3: Add selectors to `dom.ts`**

Add to `getPanelDom()`:
```ts
  transcriptSearch: query<HTMLInputElement>('[data-transcript-search]'),
  transcriptList: query<HTMLElement>('[data-transcript-list]'),
  transcriptStatus: query<HTMLElement>('[data-transcript-status]'),
```

- [ ] **Step 4: Style the transcript view sharp**

In `style.css`, style `.transcript-list`, `.cue`/`.cue.on` (crimson left border + tint when active), `.tc` (mono timecode), `.ct`/`.cr`/`.cg`, `.cue-actions`/`.cue-action` (square, hairline), `.search-field input`, `.transcript-empty` — matching the prototype's transcript look and the rest of the panel.

- [ ] **Step 5: Build (hooks present), commit**

Run: `npm run build` (succeeds; confirm `grep -c 'data-tab=' index.html` is now 5). Commit:
```bash
git add app/extension/entrypoints/sidepanel/index.html app/extension/entrypoints/sidepanel/dom.ts app/extension/utils/panel/view-state.ts app/extension/entrypoints/sidepanel/style.css
git commit -m "feat(extension): panel Transcript view markup + styling"
```

---

## Task 7: Panel wiring — render, live highlight, seek, focus

**Files:**
- Create: `app/extension/entrypoints/sidepanel/transcript-view.ts`
- Modify: `app/extension/entrypoints/sidepanel/main.ts`

- [ ] **Step 1: Implement `transcript-view.ts`**

A small controller bound once at startup; re-rendered when state changes and when the active cue is relayed:
```ts
import { browser } from 'wxt/browser';
import type { SubtitleCue } from '../../utils/contracts';
import type { ExtensionSettings } from '../../utils/settings-model';
import { panelTranscriptListHtml } from '../../utils/panel/transcript';

export function bindTranscriptView(dom: {
  transcriptSearch: HTMLInputElement;
  transcriptList: HTMLElement;
  transcriptStatus: HTMLElement;
}) {
  let cues: readonly SubtitleCue[] = [];
  let settings: ExtensionSettings | null = null;
  let activeCueId: string | null = null;

  function render(): void {
    if (!settings) return;
    const total = cues.length;
    dom.transcriptList.innerHTML = total === 0
      ? '<p class="transcript-empty muted">Generate subtitles to see the transcript.</p>'
      : panelTranscriptListHtml({ cues, activeCueId, query: dom.transcriptSearch.value, settings });
    dom.transcriptStatus.textContent = total === 0 ? '' : `${total} cues`;
    const activeRow = dom.transcriptList.querySelector('.cue.on');
    activeRow?.scrollIntoView({ block: 'nearest' });
  }

  dom.transcriptSearch.addEventListener('input', render);
  dom.transcriptList.addEventListener('click', (event) => {
    const button = (event.target as Element)?.closest<HTMLButtonElement>('[data-transcript-action]');
    const cueId = button?.dataset.cueId;
    if (!button || !cueId) return;
    const action = button.dataset.transcriptAction;
    if (action === 'jump' || action === 'replay') {
      void browser.runtime.sendMessage({ type: 'popup.seekToCue', cueId, mode: action }).catch(() => {});
    } else if (action === 'copy') {
      const cue = cues.find((c) => c.cueId === cueId);
      if (cue) void navigator.clipboard?.writeText(cue.sourceText).catch(() => {});
    }
    // 'save' is the existing Phase-02 placeholder — no-op for now.
  });

  return {
    setData(nextCues: readonly SubtitleCue[], nextSettings: ExtensionSettings) {
      cues = nextCues; settings = nextSettings; render();
    },
    setActiveCue(cueId: string | null) { activeCueId = cueId; render(); },
    focus() { dom.transcriptSearch.focus(); },
  };
}
```

- [ ] **Step 2: Wire it in `main.ts`**

Import `bindTranscriptView`; create it once from the panel dom. In `showPanelState`, when `subtitleState.type === 'ready'`, call `transcriptView.setData(subtitleState.track.cues, settings)` (else `setData([], settings)`). Add a `runtime.onMessage` listener:
```ts
browser.runtime.onMessage.addListener((message) => {
  if (!isRuntimeMessage(message)) return;
  if (message.type === 'background.activeCueChanged') transcriptView.setActiveCue(message.cueId);
  else if (message.type === 'background.focusTranscript') { showTab(railButtons, panels, 'transcript'); transcriptView.focus(); }
});
```
Import `isRuntimeMessage` from `../../utils/messages`.

- [ ] **Step 3: Compile, build, full tests**

Run: `npm run compile` (clean), `npm test` (green), `npm run build` (succeeds).

- [ ] **Step 4: Commit**
```bash
git add app/extension/entrypoints/sidepanel/transcript-view.ts app/extension/entrypoints/sidepanel/main.ts
git commit -m "feat(extension): wire panel transcript — live highlight, seek, focus"
```

---

## Task 8: Docs

**Files:**
- Modify: `docs/FRONTEND.md`

- [ ] **Step 1: Update the description**

Change the "optional transcript sidebar" overlay bullet and the side-panel bullet to reflect: the transcript is now the panel's Transcript view (search, active-cue highlight relayed from the content script, jump/replay via the seek message, copy/save); the overlay renders only the caption rail + word card; the `S` shortcut focuses the panel transcript when the panel is open. Add `Transcript` to the rail destination list (now five).

- [ ] **Step 2: Commit**
```bash
git add docs/FRONTEND.md
git commit -m "docs(extension): transcript now lives in the side panel"
```

---

## Task 9: Full verification gate

- [ ] **Step 1: Gates**

Run from `app/extension/`:
```bash
npm test
npm run compile
npm run build
npm run build -- -b firefox
```
Expected: all tests pass (overlay transcript cases gone, panel-transcript + messages cases present); compile clean; both browsers build.

- [ ] **Step 2: Manual acceptance (Chrome)**

Load `.output/chrome-mv3` unpacked on a YouTube video with a generated track:
- The **Transcript** rail icon opens a searchable cue list; the **playing cue highlights live** and auto-scrolls into view.
- **Jump/Replay** on a row seeks/replays the video; **Copy** copies the cue; search filters.
- Pressing **S** on the page (panel open) switches the panel to Transcript and focuses search.
- The on-video overlay shows **only** the caption rail + word card (no transcript).

- [ ] **Step 3: Screenshot** the panel Transcript view into `docs/design-assets/extension-sidepanel-redesign/` for the PR.

---

## Self-review notes

- **Multi-tab:** `background.activeCueChanged` carries `youtubeVideoId`; if you later support multiple YouTube tabs, the panel can ignore cues whose `youtubeVideoId` doesn't match the now-playing video. For Phase 2 (single active tab) the panel applies it directly.
- **No color retuning** — reuse Phase-1 tokens.
- **`jumpToCue`/`replayCue` stay in content.ts** (used by `background.seekToCue` and the prev/next shortcuts); only their wiring to the removed overlay transcript callbacks is deleted.
