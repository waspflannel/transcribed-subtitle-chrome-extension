# Plan: Allow free movement of the subtitle overlay

Status: complete
Owner: agent
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Let viewers detach the subtitles, move them anywhere in the page viewport, and check the attachment setting again to snap back to the selected video preset.

## Scope

- In scope: a saved attachment checkbox in Study, pointer and keyboard movement, viewport constraints, cleanup, focused tests and browser validation.
- Out of scope: resizing, saving coordinates across page loads, backend changes.
- Branch: `codex/draggable-subtitle-overlay`.
- Existing unrelated edit: `scripts/runtime/start-local-stripe.ps1`; preserve it.

## Acceptance Criteria

- [x] Existing installs start attached; unchecking exposes a labeled drag handle.
- [x] Movement works outside the video and survives cue changes and scrolling.
- [x] Checking attachment restores bottom, top or compact positioning.
- [x] Word cards and study buttons remain usable; the handle supports arrow keys.
- [x] Resize/fullscreen changes keep the handle reachable; hiding/unmounting releases capture.
- [x] Browser validation and repository checks pass.

## Relevant Context

- `docs/FRONTEND.md`, `docs/product-specs/index.md`, `docs/quality/golden-principles.md`.
- `OverlayShell` owns placement and mounts into the fullscreen element when present.
- Settings already flow from panel to background storage and content-script broadcasts.

## Implementation Steps

- [x] Inspect the settings and overlay paths.
- [x] Add the saved checkbox and movement behavior using native pointer capture.
- [x] Add behavior tests and update frontend expectations.
- [x] Validate browser layout and run `scripts/agent/check.ps1`.
- [x] Review the final diff and record results.

## Validation Plan

- Focused extension tests cover defaults, panel messages, drag, cue updates, bounds, fullscreen, cleanup and all snap-back presets.
- Browser fixture uses the actual overlay shell/styles and a contract sample; test pointer movement and word-card controls visually.
- Run `.\scripts\agent\check.ps1` before completion.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Keep attachment enabled by default and store only the boolean preference. | Preserve current behavior; arbitrary coordinates belong to the mounted page. |
| 2026-09-16 | Use a persistent drag handle outside the cue content. | Cue rendering cannot replace a captured pointer target or interfere with word clicks. |
| 2026-09-16 | Allow drag within the page viewport; keep presets for reattachment. | Implements the explicit request without adding resize or a dependency. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Implemented saved setting and movement. | 34 focused tests pass; TypeScript compile passes. |
| 2026-09-16 | Fixed detached initialization when the video mounts after the first render. | Regression test passes. |
| 2026-09-16 | Completed browser fixture checks and final repository validation. | 321 extension tests; 637 backend tests pass, 9 skipped; contracts, TypeScript and production build pass. |

## Completion Notes

- What changed: saved attachment setting, persistent drag handle, pointer capture, keyboard movement, viewport bounds and reattachment to the selected preset.
- Validation: `scripts/agent/check.ps1` passes on the final code. `git diff --check` passes. Browser fixture verified dragging below the video, cue-update stability, working word details and replay, keyboard movement and snap-back. Fullscreen parent changes and resize bounds are covered by DOM tests.
- Local evidence: `agent-overlay-check.log`; `app/extension/.wxt/overlay-evidence/attached.png`, `detached.png`, and `reattached.png` (ignored validation artifacts).
- Simplicity review: no dependencies, storage writes during drag, global movement listeners or per-cue drag controls. Existing setting broadcasts and video-position presets are reused.
- Validation limit: browser smoke testing used the real overlay with a local contract fixture; live YouTube fullscreen and touch hardware were not exercised.
- Commit plan: one local feature commit containing the setting, UI, movement, tests and docs. Leave the unrelated Stripe script edit out.
- Follow-up debt: none.
