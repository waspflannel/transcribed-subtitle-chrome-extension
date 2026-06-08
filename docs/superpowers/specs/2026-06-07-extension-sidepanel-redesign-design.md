# Extension Frontend Redesign — Side Panel + Sharp Word Cards

**Date:** 2026-06-07
**Branch:** (new) `redesign/extension-sidepanel-sharp`
**Status:** Approved (design) — ready for implementation plan
**Supersedes the layout (not the brand) of:** `2026-06-07-extension-ui-rework-design.md`

## Goal

Re-architect the extension's *layout and interaction model*, not just its color. The
current UI is a cramped ~400×600 toolbar popup with four stacked tabs and rounded
"surface" cards; it disappears the moment the user clicks back on the video. We
replace it with a **persistent, toggleable side panel** organized by a left icon
rail, and give every surface a **sharp** visual language (square corners, hairline
rules, Geist type) matching the marketing site.

Color is intentionally **out of scope to finalize** here — we keep the existing
black + crimson brand tokens so the prototype reads correctly, and the user tunes
exact colors to the new layout afterward.

This is a validated design: a clickable hi-fi prototype was built and reviewed
(`.superpowers/brainstorm/.../prototype-v3.html`). This document captures what that
prototype commits us to.

## Scope

**In scope — both extension surfaces:**

1. **The side panel** (replaces the popup) — Generate · Study · Transcript · Jobs ·
   Account, behind a left icon rail with a persistent "now playing" header.
2. **The on-video words panel** (the Shadow-DOM overlay) — caption rail + per-token
   word cards, restyled sharp.

**Out of scope:** Everything else on the page is YouTube's own UI. Backend Blade
views and the marketing site are untouched. No backend/data-contract changes — the
redesign consumes the existing `TrackResponse` / `SubtitleCue` / `LearningToken`
contracts as-is.

## The two surfaces

> The extension owns exactly two pieces of UI. The video frame, player chrome, and
> page around them are YouTube's.

### Surface 1 — Side panel (replaces the popup)

**Container.** A native browser side panel via a WXT `sidepanel` entrypoint
(`entrypoints/sidepanel/`), which emits Chrome's `side_panel` and Firefox's
`sidebar_action`. The native side panel **docks beside the page and reflows the
viewport — it does not overlay YouTube**, which is what the user asked for. Toggling
it closed returns the space to the video.

- **Toggle.** The toolbar action opens the panel
  (`chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true })` in the
  background; Firefox uses the native sidebar button). The browser supplies a native
  close affordance. The design adds an in-panel collapse control and an optional
  on-page reopen handle as conveniences; their exact wiring to the `sidePanel` API
  (e.g. `window.close()`, `chrome.sidePanel.open()` from a user gesture) is an
  implementation detail to validate, with the **native toolbar toggle as the
  guaranteed fallback**.
- **The popup is retired** (`entrypoints/popup/` removed). Its logic is ported, not
  rewritten — see "Logic reuse" below.

**Navigation — left icon rail + state-aware default.** A persistent ~56px rail of
icons switches between full-height views. This replaces top tabs because the content
is lopsided: Study is large and used continuously, Generate is one screen used once
per video, Jobs/Account are occasional. The rail lets each view own the full panel
height instead of competing inside one 600px box, and it never loses your place.

| Rail destination | Contents (all existing controls, rehomed) |
| --- | --- |
| **Generate** | Subtitle/Translation language route · options (Translate · Romanization · Full word cards) · Generate · privacy copy · in-line progress while a job runs |
| **Study** | *Caption display:* show overlay, position, size, density, contrast, gloss, timing delay (+reset). *Study mode:* blur source/romanization/translation, pause-on-hover, keyboard shortcuts. *Shortcuts:* help list |
| **Transcript** | Searchable cue list, active-cue highlight, jump/replay/copy/save per cue (migrated off the video — see Surface 2) |
| **Jobs** | History grouped **Videos** vs **Shorts / Reels**; **each row links to its YouTube watch/shorts URL** |
| **Account** | Status/plan/speed · usage meter · pending/reset · upgrade · "Local data" (clear local state) |

- **Persistent "now playing" header** above every view (eyebrow + video title +
  `duration · SOURCE → TARGET`) so context is always visible.
- **State-aware default view:** not signed in → Account (the gate); signed in but no
  YouTube video → an empty state; video present, no track → Generate; generating →
  Generate with progress; track ready → Study (the resting state).

**Logic reuse.** The panel is the popup's logic in a new surface. It keeps the
existing `PopupState` shape and `popup.*` message contract and reuses
`settings-model`, `settings`, `account-session`, `popup-progress`,
`popup-saas-state`, `job-history-media`, `languages`, `api`, and
`backend-subtitle-state`. Renaming `popup.*` → `panel.*` is optional and deferred
(avoids a wide rename mid-redesign). What changes is the **markup, the CSS, and the
view-switching shell** — not the data flow.

### Surface 2 — On-video words panel (Shadow-DOM overlay)

The content script keeps mounting the overlay into its isolated Shadow DOM and
binding the active `<video>`. The redesign is a **visual rewrite of
`utils/overlay/overlay-styles.ts`** plus targeted markup tweaks in
`overlay-render.ts`, preserving the DOM contract that `tests/overlay.test.ts`
guards (class names, `data-*` hooks, `aria-*`, rendered text).

**Caption rail.** Sharp black-glass rail, crimson left edge, mono timecode, square
token cards. Each token stacks `text` + per-token `romanization` (the existing
`token-extra study-token-romanization` span). The full-cue romanization line
(existing `cue-romanization`) already renders **above** the translation line
(`renderOverlayContent` emits `cueRomanization` before `renderTranslation`) — the
user's "romanization on the token *and* above the translation" is therefore an
existing structure we **preserve and style**, not new plumbing.

**Word card (token detail popover).** Clicking a token opens the
`token-popover`. The current renderer (`tokenDetailRows`) already emits the **full
`LearningToken` metadata** — Text, Lemma, Root, Part of speech, Romanization, Gloss,
Usage note — filtered to non-empty fields (verified by `overlay.test.ts`, which
asserts Root/`hol`/Usage note/Common greeting). The redesign:

- **Restyles** it as a sharp card: headword + part-of-speech chip, then **reading
  (romanization) directly under the headword, above the translation/meaning**, then
  the remaining metadata rows, an in-context cue line, and replay/copy/save actions.
- **Reorders** the rows so romanization sits above translation/gloss. The test uses
  substring assertions (not order), so reordering is safe.
- Renders only the fields a token carries and hides the rest (e.g. `root` only
  appears for languages that have it). This is the existing
  "filter non-empty rows" behavior — the card already "supports all metadata we will
  generate"; we make that legible and on-brand.

**Transcript leaves the overlay** (sequenced in Phase 2 — see Phasing). Today
`renderOverlayContent` also renders the transcript `aside` into the same Shadow DOM.
The end state **removes it from the overlay** and rebuilds it as the panel's
Transcript view (next section), leaving the overlay to render only the caption rail +
word card + status/progress shells.

## Cross-surface wiring (transcript migration)

Moving the transcript from the on-video Shadow DOM into the side panel is the one
change that crosses the content-script ↔ panel boundary. The background script is
already the hub (it broadcasts `background.subtitleStateChanged` /
`background.settingsChanged`); we extend that pattern:

- **Active-cue relay.** The content script computes the active cue from video time
  (it already does, to drive the rail). It reports active-cue changes to the
  background, which broadcasts them so the panel's Transcript can highlight the
  current cue. (New message, e.g. `background.activeCueChanged` with `cueId`, or fold
  the active cue id into the existing state broadcast.)
- **Seek/replay from the panel.** Jump/replay in the panel Transcript send a message
  to the content script to seek/replay the bound video (new, e.g.
  `content.seekToCue { cueId, mode: 'jump' | 'replay' }`). Copy uses the clipboard in
  the panel; Save reuses the existing placeholder.
- **Shortcut.** The content script's "open transcript" shortcut now opens/focuses the
  panel's Transcript view instead of the overlay aside.
- **Word-card enrichment is unchanged:** clicking a token in the on-video rail still
  uses `content.enrichLearningToken` → background → patched track re-render.

Rationale: the panel has the room and persistence a long, searchable cue list wants,
and it declutters the video. The cost is this modest relay plumbing, which fits the
existing background-hub architecture. This is the most involved part and is a natural
**second phase** (see Phasing).

## Visual system — "sharp"

Defined structurally; exact color values inherit today's black + crimson tokens and
are the user's to tune after the layout lands.

- **Zero border-radius.** Square buttons, fields, cards, chips, switches, the rail —
  everywhere. This is the signature move replacing today's `--radius: 12px` curves.
- **Hairline rules, not rounded cards.** Sections are separated by 1px lines and mono
  labels rather than elevated rounded "surface" panels. Editorial/technical, like the
  site.
- **Control primitives:** segmented selectors (Position/Size/Density/Contrast), square
  checkboxes (options, study toggles), square switches (on/off).
- **Type:** **Geist** for everything; **IBM Plex Mono** for uppercase micro-labels,
  eyebrows, timecodes, and key hints. Headings use Geist 600 with slight negative
  letter-spacing; buttons match the site (Geist 700, +0.02em, square, sentence-case).
- **Crimson, sparingly:** active rail item, primary CTA, active token/cue, progress
  fill, focus ring, danger outline. Everything else is neutral near-black + gray.

**Font bundling.** The site references Geist but no font file is bundled, so today's
panel falls back to Inter/system. To make the panel and overlay *actually* match the
site, we **bundle Geist + IBM Plex Mono as local `@font-face` assets** under
`public/fonts/` (used by both the panel document and the overlay Shadow DOM). No
network dependency, no FOUT.

## Responsiveness

- **Panel:** target width ~360–400px, usable down to ~320px; the icon rail is fixed,
  the now-playing header sticks, views scroll. Two-column control grids collapse to
  one column on narrow panels.
- **Overlay:** keep the existing 899px/599px breakpoints; the token row scrolls
  horizontally on narrow video; the word card opens above the token (real YouTube
  players are tall enough to contain it).

## Testing & verification

- **`tests/overlay.test.ts`** stays the regression guard for the rail + token cards +
  word card + progress/error shells + copy feedback + blur scopes. Preserve those
  hooks; the only edits are (a) removing the transcript cases (transcript leaves the
  overlay) and (b) any assertion tied to row order (none today). Reordering word-card
  rows is safe under substring assertions.
- **New panel tests** (`tests/panel-*.test.ts` or similar) cover: state-aware default
  view selection, the Transcript view (render, search/filter, active-cue, jump/replay/
  copy/save controls — ported from the old overlay transcript cases), and Jobs rows
  exposing correct YouTube watch/shorts links grouped Videos vs Shorts/Reels.
- **Gates:** `npm test` (vitest), `npm run compile` (tsc --noEmit), `npm run build`
  (wxt build) all pass for Chrome and Firefox targets.
- **Visual:** static prototype + screenshots for before/after; manual smoke of open/
  collapse/reopen and the on-video rail.

## Files

**Add**
- `app/extension/entrypoints/sidepanel/index.html` · `main.ts` · `style.css`
- `app/extension/public/fonts/*` (Geist, IBM Plex Mono) + `@font-face`
- Panel view modules (Transcript, Jobs, Generate, Study, Account) — factor into small
  focused units (e.g. `utils/panel/*` or `sidepanel/components/*`) so each view is
  independently testable.
- `tests/panel-*.test.ts`

**Change**
- `app/extension/wxt.config.ts` — add `sidePanel` permission (Chrome); action config.
- `app/extension/entrypoints/background.ts` — open panel on action click; relay
  active cue; route seek/replay to the content script; keep enrichment.
- `app/extension/entrypoints/content.ts` — stop owning the transcript aside; report
  active cue to background; handle seek/replay from the panel; keep rail mount, video
  bind, hover-pause, shortcuts ("open transcript" now targets the panel).
- `app/extension/utils/overlay/overlay-render.ts` — remove transcript rendering;
  reorder word-card rows (reading above translation); otherwise preserve hooks.
- `app/extension/utils/overlay/overlay-styles.ts` — full sharp restyle.
- `app/extension/utils/messages.ts` — add active-cue relay + seek messages; (optional)
  `popup.*` → `panel.*` rename, deferred.
- `docs/FRONTEND.md` — update the description to the side-panel architecture.

**Remove**
- `app/extension/entrypoints/popup/` (index.html, main.ts, style.css).

## Phasing (for the implementation plan)

The work splits cleanly so the big win lands first and the cross-context plumbing is
isolated:

- **Phase 1 — Side panel + sharp.** New `sidepanel` entrypoint with the icon rail,
  state-aware default, all views except Transcript; retire the popup; bundle fonts;
  sharp restyle of the panel **and** the on-video overlay's rail + word card
  selectors. The overlay transcript keeps its current DOM and styling for now, so
  `overlay.test.ts` is untouched this phase. Jobs gains its YouTube links.
- **Phase 2 — Transcript migration.** Move the transcript into the panel; add the
  active-cue relay + seek/replay messages; remove the overlay transcript and migrate
  its tests to the panel.

## Open items (deferred, not blocking)

- **Color tuning** to the new layout — owner: user, after Phase 1.
- **On-page reopen handle** feasibility vs. the `sidePanel` user-gesture rules —
  resolve in Phase 1; native toolbar toggle is the fallback.
- **`popup.* → panel.*` message rename** — cosmetic; do it only if it stays cheap.
