# Plan: Transcription overlay UI revamp

Status: completed
Owner: agent
Created: 2026-05-11
Last updated: 2026-05-11

## Goal

Revamp the synchronized in-page transcription overlay into a cinematic bottom learning rail that keeps playback readable while making word-level language study easier to scan. The selected direction is a low, translucent rail above YouTube controls with large source-token cards, compact romanization/gloss metadata, a clear English translation line, and a pinned token detail popover.

The change should improve visual hierarchy and usability without changing subtitle generation, WebVTT timing, shared contracts, popup behavior, backend workflows, or extension message boundaries.

## Design Target

- Layout: fixed Shadow DOM rail over the video, defaulting to the bottom position above native video controls, with existing top and compact positions still supported.
- Structure: metadata block, token card row, translation line, optional token detail popover, and small icon-only controls.
- Token cards: larger, stable hit targets with source word, optional romanization, and optional gloss. Selected token uses teal border/fill and a visible keyboard focus ring.
- Token detail: pinned on click, showing lemma, root, part of speech, romanization, gloss, and usage note when available. Desktop can position it as a popover above the token; mobile should render it inline to avoid viewport clipping.
- Shell states: loading, error, no-track, and unsupported states should reuse the same rail material and spacing instead of falling back to a visually unrelated box.
- Visual system: charcoal translucent panel, white primary text, slate secondary text, teal accent, radius no greater than 8px, strong contrast over video, no decorative backgrounds.

## Scope

- In scope:
- Update `app/extension/utils/overlay.ts` Shadow DOM styles and ready-state markup.
- Preserve `OverlayShell`, `renderOverlayContent`, `pinnedTokenIndex`, `escapeHtml`, and the existing subtitle state model.
- Preserve existing on-click token enrichment, pending-token state, failed-token state, and full word card behavior.
- Preserve the current `bottom`, `top`, and `compact` overlay positions.
- Improve token card layout, selected-token styling, and pinned token detail presentation.
- Add responsive CSS for desktop, tablet, and mobile viewports inside the Shadow DOM.
- Keep tests current in `app/extension/tests/overlay.test.ts`.
- Capture visual evidence for desktop and mobile overlay states if a browser target is practical.
- Update `docs/FRONTEND.md` only if the overlay behavior or documented visual structure changes materially.
- Out of scope:
- Backend API, Laravel services, provider integrations, contracts, storage, and job history.
- Popup redesign.
- Dragging, resizing, custom placement, transcript list drawer, vocabulary review, or subtitle editing.
- Functional replay/star/settings behavior unless an existing behavior already exists.
- New dependencies or UI frameworks.

## Acceptance Criteria

- [x] Ready-state overlay renders as a bottom learning rail with metadata, source-token cards, translation, optional romanization/gloss, and optional pinned token detail.
- [x] Existing settings still control overlay visibility, position, romanization visibility, and gloss visibility.
- [x] Clicking a token pins/unpins detail without breaking cue changes or causing null/undefined placeholders.
- [x] Keyboard users can focus token buttons and toggle the pinned detail with visible focus styling.
- [x] Duplicate English translation suppression still works for English-source tracks.
- [x] Loading, error, no-track, and unsupported states remain visible, readable, and consistent with the new rail style.
- [x] Desktop layout keeps the rail above native player controls and within roughly the lower fifth of the video.
- [x] Tablet/mobile layouts avoid text overflow, avoid viewport-clipped popovers, and keep tap targets usable.
- [x] Overlay remains isolated in Shadow DOM and avoids unnecessary DOM churn during playback.
- [x] Focused unit tests and the repo harness pass.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/product-specs/release-readiness.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/FRONTEND.md`, `docs/DESIGN.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/phase-05-generated-track-and-overlay-sync.md`, `docs/exec-plans/completed/phase-06-translation-and-arabic-learning-data.md`, `docs/exec-plans/completed/2026-05-05-manual-subtitle-timing-offset.md`
- Current implementation: `app/extension/utils/overlay.ts`, `app/extension/entrypoints/content.ts`, `app/extension/tests/overlay.test.ts`
- Known risks:
  - The rail could cover native YouTube controls or important video content on small players.
  - Large token cards can overflow for long words, dense scripts, or many-token cues.
  - Desktop popover positioning can clip near viewport edges unless mobile and compact variants render inline.
  - Adding non-functional controls can imply behavior that does not exist. Keep icon controls visual-only or omit them unless labels and expectations are clear.
  - Visual-only changes can regress accessibility if buttons lose semantic labels or focus states.

## Implementation Steps

- [x] Inspect current overlay markup, CSS, and tests.
- [x] Confirm the selected layout rules against the mockup and this plan.
- [x] Refactor ready-state markup into named regions: rail, meta, token area, translation, controls, and token detail.
- [x] Update Shadow DOM CSS for the desktop bottom rail, including panel material, token cards, selected state, focus state, translation hierarchy, and detail popover.
- [x] Add responsive CSS for tablet, mobile, top, and compact positions.
- [x] Reuse the new shell styling for loading, error, no-track, and unsupported states.
- [x] Keep token interactions direct and preserve pinned-token reset on cue changes.
- [x] Update or add overlay unit tests for token rendering, visibility settings, pinned detail, duplicate translation suppression, and shell states.
- [x] Run focused extension validation.
- [x] Run repo harness validation.
- [x] Capture browser screenshots or video for desktop and mobile overlay states if a local extension/browser smoke path is available.
- [x] Review against `docs/quality/golden-principles.md`.
- [x] Update docs and completion notes with validation evidence.

## Layout Rules

- Keep the overlay in a single rail surface. Do not put cards inside cards.
- Keep border radius at 8px or less.
- Keep the default bottom rail above native video controls.
- Use stable token dimensions so hover, focus, selected state, and text changes do not shift the layout.
- Use wrapping or horizontal overflow for dense token rows rather than shrinking text below readable sizes.
- Keep source tokens prominent, translation second, romanization/gloss third.
- Use icon-only buttons only for familiar controls and provide accessible labels.
- Do not add marketing copy, decorative gradients, bokeh, or background ornaments.

## Responsive Rules

- Desktop, 900px and wider: three-column rail with metadata left, tokens and translation center, controls right.
- Tablet, 600px to 899px: single-column or two-row rail with controls moved to the top-right or omitted if crowded.
- Mobile, below 600px: compact rail with smaller source word type, reduced padding, horizontal token scrolling or tight wrapping, and inline token detail below the token area.
- Compact position: preserve current right-side width behavior while adapting the rail to a single column.
- Top position: keep equivalent spacing from the top and avoid covering browser/player chrome.

## Validation Plan

Commands:

```powershell
cd app\extension
npm test -- overlay.test.ts
npm run compile
cd ..\..
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: focused overlay tests, TypeScript compile, full harness result.
- Screenshots or video: not captured because the repo still has no local loaded-extension browser smoke target; existing `TD-003` and `TD-007` track that harness gap.
- Logs: note any browser console warnings from overlay rendering or content script updates.
- Metrics or traces: not required unless DOM churn or playback responsiveness appears visibly degraded.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-11 | Use the cinematic bottom learning rail as the selected direction. | It best preserves video focus while improving source-token readability and word-level learning hierarchy. |
| 2026-05-11 | Keep the change in `overlay.ts` unless implementation proves the file needs small local helpers. | The behavior is currently isolated in one Shadow DOM renderer, and the project favors direct code over premature abstraction. |
| 2026-05-11 | Treat replay/star/more controls as optional visual controls unless existing behavior is wired. | Avoid implying product behavior that is not part of this UI-only revamp. |
| 2026-05-11 | Preserve on-demand token enrichment as-is. | Current `main` already enriches clicked transcript tokens through content/background messages, so this revamp must remain presentation-only around that flow. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-11 | Plan created for selected transcription overlay UI revamp. | User-selected mockup and generated JSON/dev implementation guidance. |
| 2026-05-11 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed with docs lint, contracts check, backend tests, extension tests, compile, and WXT build. |
| 2026-05-11 | Plan review refined scope to preserve clicked-token enrichment and pending/failed token UI. | `docs/FRONTEND.md`, `app/extension/utils/overlay.ts`, `app/extension/entrypoints/content.ts` |
| 2026-05-11 | Implemented the rail markup/CSS and updated focused overlay tests. | `npm test -- overlay.test.ts`; `npm run compile` |
| 2026-05-11 | Full harness and PR verification passed; frontend docs updated for the durable rail structure. | `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`; `.\scripts\agent\doc-gardening.ps1` |

## Completion Notes

- What changed: Reworked the in-page overlay from a compact box into a responsive Shadow DOM learning rail with cue timing, larger source-token cards, translation hierarchy, selected-token popovers, compact/top/mobile variants, and consistent loading/error/no-track/unsupported shells. Preserved clicked-token enrichment, pending/failed token states, cue-change reset behavior, romanization/gloss settings, and duplicate translation suppression.
- Validation results: `npm test -- overlay.test.ts` passed with 6 tests; `npm run compile` passed; `.\scripts\agent\check.ps1` passed with docs lint, contracts check, 52 backend tests, 32 extension tests, TypeScript compile, and WXT build; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings.
- Simplicity/readability review: Kept the change isolated to `overlay.ts`, the focused overlay tests, and the frontend doc. No new dependencies, contracts, state machines, popup changes, or backend paths were added.
- Residual risk: Browser screenshot evidence was not captured because the repository still lacks a deterministic loaded-extension smoke target. Visual behavior should be manually checked on a real YouTube watch page before release.
- Follow-up debt: Existing `TD-003` and `TD-007` continue to track automated extension screenshot smoke coverage and release visual QA.
