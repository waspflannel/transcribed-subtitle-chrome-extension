# Plan: Manual subtitle timing offset

Status: completed
Owner: agent
Created: 2026-05-05
Last updated: 2026-05-05

## Goal

Add a manual subtitle timing offset for generated tracks when provider timestamps are consistently early or late against the actual YouTube audio.

The fix should stay extension-local. Backend tracks remain canonical, while the popup lets the user shift rendered cue timing during playback.

## Scope

- In scope:
- Popup timing delay control.
- Extension setting for subtitle timing offset.
- Client-side WebVTT and cue timing shift before binding the hidden browser `TextTrack`.
- Rebinding the active generated track when the offset changes.
- Unit tests for setting validation and timing shift behavior.
- Out of scope:
- Automatic audio/lyric alignment.
- Backend mutation of generated WebVTT or persisted cue timings.
- Provider/model changes.
- Per-video offset storage unless the global control proves too blunt.

## Acceptance Criteria

- [x] User can set subtitle timing delay from -10s to +10s in 0.1s steps.
- [x] Positive delay makes subtitles appear later.
- [x] Offset changes apply to the current ready track without regenerating subtitles.
- [x] Offset setting is validated and persisted with extension settings.
- [x] Backend-generated WebVTT and persisted cue timings are not changed.
- [x] Tests cover setting validation and shifted WebVTT/cue timing.

## Relevant Context

- Product docs: `docs/product-specs/release-readiness.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/FRONTEND.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/phase-07-hardening-and-release-readiness.md`
- Known risks:
  - The user-reported issue may be a constant offset for one video or may be drift over time.
  - A global timing offset can affect the next video until reset.
  - Rebinding the hidden text track on slider changes is simple but may be less smooth than a custom timeupdate loop.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests:
- Screenshots or video:
- Logs:
- Metrics or traces:

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-05 | Use a manual extension-local offset first. | It directly solves constant leading/lagging lyrics without backend regeneration or speculative audio alignment. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-05 | Plan created and scoped to extension-local timing adjustment. | User reported lyrics appearing 4-5 seconds ahead of the audio. |
| 2026-05-05 | Added popup timing delay, setting validation, WebVTT/cue offsetting, active-track rebinding, and focused tests. | `npm test -- webvtt-track.test.ts settings-model.test.ts`; `npm run compile` |
| 2026-05-05 | Full harness validation passed and plan archived. | `.\scripts\agent\check.ps1` |

## Completion Notes

- What changed: Added a popup timing delay control, persisted `subtitleTimingOffsetSeconds`, applied client-side WebVTT/cue timing shifts before binding generated tracks, rebound the active track when timing changes, and logged non-zero offset usage.
- Validation results: `npm test -- webvtt-track.test.ts settings-model.test.ts` passed; `npm run compile` passed; `.\scripts\agent\check.ps1` passed with contracts validation/build, 41 Laravel tests, 24 extension tests, TypeScript compile, and WXT build.
- Simplicity/readability review: Kept timing adjustment extension-local and did not mutate backend tracks, add provider calls, or introduce audio-alignment infrastructure.
- Residual risk: This solves constant offset, not timing drift across a video. The offset is currently global extension state, so users should reset it for videos that do not need adjustment.
- Follow-up debt: `TD-008` tracks drift/per-video calibration if repeated real videos prove the global constant offset is too limited.

