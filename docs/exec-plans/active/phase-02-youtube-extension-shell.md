# Plan: Phase 02 - YouTube Extension Shell

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-28

## Goal

Build the browser-side shell that proves the extension can detect YouTube watch pages, identify the current video, find the active video element, and mount a controlled in-page overlay.

This phase should make the extension feel structurally real without depending on backend AI work. It should establish clean messaging, settings, and UI boundaries before the job API arrives.

## Scope

- In scope:
  - YouTube watch page detection.
  - Video ID parsing from `watch?v=`.
  - YouTube single-page navigation handling.
  - Active video element discovery and listener cleanup.
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

- [ ] Extension loads on YouTube watch pages only.
- [ ] Content script extracts the current YouTube video ID.
- [ ] Content script handles YouTube route changes without requiring full page reload.
- [ ] Content script finds the active `HTMLVideoElement`.
- [ ] Overlay mounts once and cleans up on video/page changes.
- [ ] Popup shows supported/unsupported page state and current video identity.
- [ ] Background worker coordinates messages between popup and content script.
- [ ] Anonymous install ID is generated and persisted locally.
- [ ] Basic settings persist locally and affect the overlay shell.
- [ ] The extension exposes no provider keys or backend secrets.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Related plans: `phase-01-project-scaffold-and-contracts.md`, `phase-03-laravel-job-api-and-persistence.md`
- Known risks:
  - YouTube is a single-page app, so URL-only detection will miss some transitions unless route changes are observed.
  - YouTube DOM changes can break brittle selectors; prefer the actual video element and stable URL state over deep DOM coupling.
  - Overlay styling must not leak into or be broken by YouTube CSS.

## Implementation Steps

- [ ] Inspect the WXT scaffold and extension entrypoints.
- [ ] Implement YouTube URL/video ID detection as a small tested module.
- [ ] Implement route-change observation for YouTube single-page navigation.
- [ ] Implement active video element discovery and listener lifecycle.
- [ ] Add background message types for page/video status.
- [ ] Add popup status UI.
- [ ] Add local install ID creation and settings storage.
- [ ] Add overlay mount/unmount with isolated styling.
- [ ] Add placeholder overlay states.
- [ ] Add extension unit tests where practical.
- [ ] Run validation and record evidence.

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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |

## Completion Notes

- What changed:
- Validation results:
- Residual risk:
- Follow-up debt:
