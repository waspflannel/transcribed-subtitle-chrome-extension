# Extension UI Rework — Black + Crimson Brand

**Date:** 2026-06-07
**Branch:** `redesign/extension-ui-black-red`
**Status:** Approved — implementing

## Goal

Severe, on-brand UI rework of **all extension frontend**: the WXT popup, the
in-page YouTube overlay caption rail, and the transcript panel. The result must
be clean, responsive, well organized, and aligned to the existing site brand
(black + crimson) — replacing the current off-brand teal + amber palette.

Scope is the **extension only**. Backend Blade views and the marketing site are
out of scope (the marketing site already uses this brand).

## Brand system (mirrors `app/backend/public/css/site.css`)

| Token | Value |
| --- | --- |
| `--bg` | `#0a0a0a` |
| `--obsidian` | `#0d0d0d` |
| `--surface` | `#141414` |
| `--surface-high` | `#1e1e1e` |
| `--line` | `rgba(255,255,255,0.10)` |
| `--line-strong` | `rgba(255,255,255,0.16)` |
| `--text` | `#f1f1f1` |
| `--muted` | `#b4b4b4` |
| `--accent` (crimson) | `#d83b3b` |
| `--accent-hover` | `#e85d5d` |
| `--accent-deep` (brass) | `#b23030` |
| `--oxblood` | `#7f1d1d` |
| `--success` | `#3d8b68` |
| `--failure` | `#b91c1c` |

Font stack: `"Geist", "Inter", ui-sans-serif, system-ui, sans-serif` with a
graceful fallback (no Geist font file is bundled in the repo, so no new font
assets are added). Mono stack unchanged.

Disambiguation: the accent red and the failure red read differently —
in-progress/loading uses the crimson accent (pulse), errors use the deeper
failure red with an error treatment.

## Popup information architecture: 6 tabs → 4

A persistent compact header (brand mark + plan/sign-in chip + refresh) sits above
a single row of four tabs.

| New tab | Absorbs | Contents |
| --- | --- | --- |
| **Generate** | Generate | Status card (video / duration / track / job + progress) · language route · options (translate / romanization / full word cards) · generate · privacy copy |
| **Study** | Study + Settings' overlay display | *Caption display:* show overlay, position, size, density, contrast, gloss, timing delay (+ reset). *Study & shortcuts:* blur source/romanization/translation, pause-on-hover, keyboard shortcuts + help |
| **Account** | Account + Usage | Sign in/out, status/plan/speed, feature list, usage meter, pending, reset, upgrade, and a "Local data" zone (clear local state) |
| **Jobs** | Jobs | History grouped Videos / Shorts |

Every existing control and action is retained and rewired:
`sourceLanguage`, `targetLanguage`, `showTranslation`, `showRomanization`,
`fullTrackEnrichment`, `blurSourceWords`, `blurRomanization`, `blurTranslation`,
`pauseOnWordHover`, `keyboardShortcutsEnabled`, `overlayVisible`,
`overlayPosition`, `captionFontSize`, `captionDensity`, `captionContrastTheme`,
`showGloss`, `subtitleTimingOffsetSeconds`; actions generate / login / logout /
refresh / clear-state / reset-timing / upgrade / view-video / retry-job.

## Overlay + transcript (visual rework, DOM contract preserved)

Rewrite the shadow-DOM `<style>` in `utils/overlay.ts` to the brand: black-glass
rail, crimson eyebrow/time/token-hover/pinned states (replacing amber), token
popover + inline preview, crimson transcript active-cue + scrollbar (replacing
teal), high-contrast variant in red/white. Position/size/density/contrast
data-attribute variants stay.

All class names, `data-*` hooks, rendered text, and aria attributes are
**unchanged** so `tests/overlay.test.ts` passes without edits.

## Responsiveness

- Popup ~400px (min 320 supported); sticky header + tab bar; panels scroll
  within the ~600px popup height cap; grids collapse to one column < 360px.
- Overlay keeps/refines the 899px and 599px breakpoints; token row scrolls
  horizontally on narrow video; transcript panel adapts.

## Constraints / verification

- `npm test` (vitest), `npm run compile` (tsc --noEmit), `npm run build`
  (wxt build) all pass.
- No DOM tests exist for the popup, so its markup/JS may be restructured freely;
  the overlay test contract is the regression guard for the overlay.
- Visual verification via a static preview harness + screenshots.

## Files

- `app/extension/entrypoints/popup/index.html`
- `app/extension/entrypoints/popup/main.ts`
- `app/extension/entrypoints/popup/style.css`
- `app/extension/utils/overlay.ts` (shadow styles only)
- `docs/FRONTEND.md` (update description)

Do **not** touch the unrelated uncommitted changes already present on `main`.
