# Plan: Phase 02 - YouTube Extension Shell

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-29

## Goal

Build the browser-side shell that proves the extension can detect YouTube watch pages, identify the current video, and mount a controlled in-page overlay.

This phase should make the extension feel structurally real without depending on backend AI work. It should establish clean messaging, settings, and UI boundaries before the job API arrives.

## Scope

- In scope:
  - YouTube watch page detection.
  - Video ID parsing from `watch?v=`.
  - YouTube single-page navigation handling.
  - Background service worker message coordination.
  - Popup support/status surface.
  - Anonymous extension install ID creation and storage.
  - User settings for overlay visibility, position, romanization, and gloss.
  - Shadow DOM or equivalent overlay isolation.
  - Overlay shell states for unsupported page, no track, processing, ready placeholder, and error placeholder.
- Out of scope:
  - Real backend job creation.
  - Real subtitle tracks.
  - Real AI output.
  - Word hover/click detail behavior.
  - Chrome tab audio capture.
  - Full visual polish.

## Acceptance Criteria

- [x] Extension loads on YouTube watch pages only.
- [x] Content script extracts the current YouTube video ID.
- [x] Content script handles YouTube route changes without requiring full page reload.
- [x] Overlay mounts once and cleans up on video/page changes.
- [x] Popup shows supported/unsupported page state and current video identity.
- [x] Background worker coordinates messages between popup and content script.
- [x] Anonymous install ID is generated and persisted locally.
- [x] Basic settings persist locally and affect the overlay shell.
- [x] The extension exposes no provider keys or backend secrets.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Related plans: `phase-01-project-scaffold-and-contracts.md`, `phase-03-laravel-job-api-and-persistence.md`
- Known risks:
  - YouTube is a single-page app, so URL-only detection will miss some transitions unless route changes are observed.
  - YouTube DOM changes can break brittle selectors; prefer stable URL state over deep DOM coupling.
  - Overlay styling must not leak into or be broken by YouTube CSS.

## Implementation Steps

- [x] Inspect the WXT scaffold and extension entrypoints.
- [x] Implement YouTube URL/video ID detection as a small tested module.
- [x] Implement route-change observation for YouTube single-page navigation.
- [x] Keep active page detection based on the current YouTube watch URL.
- [x] Add background message types for popup/content coordination.
- [x] Add popup status UI.
- [x] Add local install ID creation and settings storage.
- [x] Add overlay mount/unmount with isolated styling.
- [x] Add placeholder overlay states.
- [x] Add extension unit tests where practical.
- [x] Run validation and record evidence.

## Refined Implementation Slices

1. Browser integration foundation.
   - Builds: tested YouTube watch URL parsing, video ID validation, and route-change observation.
   - Defers: backend job creation, job polling, and playback-synced cue selection.
   - Touches: `app/extension/utils/`, `app/extension/entrypoints/content.ts`.
   - Validation: focused unit tests plus `npm run compile`.
2. Overlay shell and lifecycle.
   - Builds: one Shadow DOM overlay host, placeholder states, settings-driven visibility/position, and cleanup on unsupported page or video changes.
   - Defers: real subtitle cue rendering and word interactions.
   - Touches: `app/extension/utils/overlay.ts`, content script lifecycle.
   - Validation: WXT build and source review for Shadow DOM isolation.
3. Extension state and messaging.
   - Builds: typed message contracts, background state coordination, anonymous install ID storage, persisted settings, popup status/settings UI, and content-script setting updates.
   - Defers: backend API calls and job state persistence.
   - Touches: background, popup, storage/message utilities, manifest permissions.
   - Validation: focused tests where practical, `npm run compile`, `npm run build`.
4. Harness and handoff.
   - Builds: repeatable extension test command, harness integration, plan evidence, self-review, and phase archival when acceptance criteria are satisfied.
   - Defers: browser screenshot evidence until a manually loaded extension can be exercised against a real YouTube page.
   - Touches: `app/extension/package.json`, `scripts/agent/check.ps1`, this plan.
   - Validation: `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
npm run build
```

Evidence to capture:

- Tests: URL parsing, settings persistence, message handling where testable.
- Screenshots or video: overlay shell on a public YouTube watch page.
- Logs: extension console free of unexpected runtime errors.
- Metrics or traces: not required.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Keep Phase 02 backend-independent. | The extension shell can be validated before AI, queue, and audio risks enter the project. |
| 2026-04-28 | Use WXT storage items and callback-style runtime message handlers. | Current WXT docs require the `storage` permission for storage items and recommend `sendResponse` for async MV3 message responses. |
| 2026-04-28 | Keep YouTube route detection based on page URL instead of patching page history. | YouTube runs as an SPA and content scripts are isolated; URL checks triggered by YouTube navigation events are enough for the extension shell. |
| 2026-04-28 | Add explicit YouTube host permission while keeping the content script match restricted to watch pages. | Chrome site-access behavior can prevent static content-script injection without declared host access; the script still only runs for `*://*.youtube.com/watch*`. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-04-28 | Refined Phase 02 into four implementation slices after reviewing the WXT scaffold, architecture, project guardrails, and current WXT documentation. | `app/extension`, `ARCHITECTURE.md`, `docs/references/project-guardrails.md`, Context7 WXT docs |
| 2026-04-28 | Implemented YouTube parsing, route observation, Shadow DOM overlay shell, typed messaging, background state, popup controls, local install ID/settings storage, and focused tests. | `app/extension/entrypoints`, `app/extension/utils`, `app/extension/package.json`, `scripts/agent/check.ps1` |
| 2026-04-28 | Ran extension checks and full harness successfully. | `npm test` passed 2 files / 6 tests; `npm run compile` passed; `npm run build` passed; `.\scripts\agent\check.ps1` passed. |
| 2026-04-28 | Attempted automated Chrome/CDP visual smoke against `https://www.youtube.com/watch?v=dQw4w9WgXcQ`. | Chrome loaded the YouTube page and video; command-line extension/content-script injection was not observable in that profile, so no screenshot was captured. Tracked as `TD-003`. |
| 2026-04-29 | Completed post-implementation readability cleanup for Phase 02 extension code. | Popup static markup moved to `entrypoints/popup/index.html`; popup script now only wires DOM events and background messages; shared HTML escaping helper added for overlay rendering; background/content entrypoints split into smaller named handlers. |
| 2026-04-29 | Re-ran focused extension validation and dependency audit during phase closeout. | `npm run compile` passed; `npm test` passed 3 files / 8 tests; `npm run build` passed; `npm audit --audit-level=moderate` still reports 4 moderate WXT transitive advisories tracked as `TD-002`. |
| 2026-04-29 | Completed final harness validation for Phase 02 handoff. | `.\scripts\agent\doc-gardening.ps1` reported no findings; `.\scripts\agent\verify-pr.ps1` passed contracts validation/build, Laravel tests, WXT tests, WXT compile, and WXT build. |

## Completion Notes

- What changed: implemented the Phase 02 WXT extension shell with tested YouTube watch parsing, YouTube SPA route observation, Shadow DOM overlay placeholder states, background/popup/content messaging, anonymous install ID storage, persisted overlay settings, and popup status/settings controls. Added Vitest and wired extension tests into the repository harness. Final cleanup moved fixed popup markup into static HTML, simplified popup TypeScript to DOM wiring/state updates, centralized HTML escaping for string-rendered overlay content, and clarified background/content entrypoint handlers.
- Validation results: `npm test` passed 3 files / 8 tests; `npm run compile` passed; `npm run build` passed; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings; `.\scripts\agent\verify-pr.ps1` passed. Chrome/CDP command-line smoke reached a real YouTube watch page, but did not observe content-script injection in that automated profile. `npm audit --audit-level=moderate` still reports 4 moderate WXT transitive advisories; the suggested forced fix is breaking and remains tracked as `TD-002`.
- Residual risk: manual loaded-extension smoke on a real Chrome profile is still needed to capture visual evidence of the overlay on YouTube. The code path is covered by TypeScript/build checks, but the automated CDP profile did not produce a screenshot.
- Follow-up debt: `TD-002` tracks WXT transitive npm audit advisories; `TD-003` tracks a deterministic browser smoke harness for unpacked Chrome extensions and overlay screenshots.
