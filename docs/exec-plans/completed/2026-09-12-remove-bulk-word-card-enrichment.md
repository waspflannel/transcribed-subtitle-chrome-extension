# Plan: Remove bulk word card enrichment

Status: complete
Owner: agent
Work mode: standard (if the user selects Brain / Worker, follow `docs/work-modes/brain-worker.md` and add its chunk backlog and packet notes)
Created: 2026-09-12
Last updated: 2026-09-12

## Goal

Remove the unused bulk word-card enrichment mode from generation and lyrics correction. Keep only clicked-token enrichment, including its provider call, validation, cache and track update.

## Scope

- In scope: API contracts, persistence, queue jobs, pipeline branches, billing features, extension state, evaluation fixtures, tests and current architecture/reliability docs.
- Out of scope: changing clicked-token behavior or its cache; rewriting historical review documents.

## Acceptance Criteria

- [x] Generation and correction never dispatch bulk word-card work.
- [x] Public contracts and extension state have no enrichment mode.
- [x] Clicked-token enrichment remains covered.
- [x] The obsolete database column has a forward migration.
- [x] Full repository harness passes.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-09-12-implement-ponytail-application-review.md`
- Known risks: deployment must run the new column-removal migration after workers using the old job payload are drained.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update current architecture and contract docs.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: 531 backend tests / 4,188 assertions and 255 extension tests across 32 files.
- Screenshots or video:
- Logs: contract validation, TypeScript compile and Chrome production build passed.
- Metrics or traces: extension build is 604.93 kB; no paid provider benchmark was run.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-12 | Remove the mode field instead of retaining a one-value enum. | There is only one supported behavior. |
| 2026-09-12 | Preserve `on-demand` in the processing-version string. | Existing valid cached tracks remain compatible without preserving the removed feature switch. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-12 | Plan created. |  |
| 2026-09-12 | Removed the bulk agent, queue stage, API field, database field and extension branches. | Focused backend and contract checks pass; full validation pending. |
| 2026-09-12 | Full harness passed. | `scripts/agent/check.ps1`: 531 backend tests, 255 extension tests, contracts, compile and build. |

## Completion Notes

- What changed: Deleted bulk cue enrichment and its public mode, leaving only clicked-token cards.
- Validation results: Full repository harness and `git diff --check` pass.
- Simplicity/readability review: 852 lines removed and no replacement abstraction added.
- Residual risk: Deployments must drain old workers before dropping `subtitle_jobs.enrichment_mode`.
- Follow-up debt: None.
