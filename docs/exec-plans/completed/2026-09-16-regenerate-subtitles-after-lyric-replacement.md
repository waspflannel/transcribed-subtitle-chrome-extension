# Plan: Regenerate subtitles after lyric replacement

Status: completed
Owner: agent
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Explain full-lyrics replacement and let Generate again recover from a damaged track.

## Scope

Add replacement instructions and an optional regeneration request flag. Reuse original transcription while rebuilding learning data. Do not change lyric validation or add a migration.

## Acceptance Criteria

- [x] Explain complete lyrics, repetitions, section tags, and timing risks.
- [x] Existing-track generation requests bypass completed track reuse.
- [x] Cached original transcription survives edits; fresh analysis replaces edited data.
- [x] Missing transcription cache triggers normal transcription.
- [x] Active runs deduplicate; rejected billing preserves the current track.
- [x] Repository checks pass.

## Relevant Context

- Product docs: `docs/product-specs/lyrics-editing.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`

## Implementation Steps

- [x] Trace track reuse, transcription cache, billing, and run identity.
- [x] Add panel copy, request contract, and completed-run reset flag.
- [x] Cover regeneration, cache expiry, duplicate requests, stale workers, conflicts, and billing rejection.
- [x] Update product documentation.
- [x] Run repository checks and finish review.

## Validation Plan

Run `scripts/agent/check.ps1`, PHP formatting, and diff checks. Provider calls are faked in tests.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Reuse original transcription cache. | Lyrics replacement modifies the track, not the original transcription. |
| 2026-09-16 | Reuse the existing job reset and new run ID. | Preserves billing and stale-worker protections without schema changes. |
| 2026-09-16 | Remove the prior track on accepted regeneration. | Existing pipeline requires no ready track while generating. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Implemented and focused tests pass. | Backend: 8 tests, 58 assertions. Background: 68 tests. |

## Completion Notes

- Validation results: full check.ps1 passed: contracts, 681 backend tests (9 skipped; 5,392 assertions), 334 extension tests, TypeScript compilation, and production build. PHP formatting and git diff --check passed. Provider calls were faked; no live provider smoke test was run.
- Simplicity/readability review: optional request flag uses the existing reset flow; no new cache layer or migration.
- Residual risk: accepted regeneration removes the previous track and uses normal plan minutes.
- Follow-up debt: none.
