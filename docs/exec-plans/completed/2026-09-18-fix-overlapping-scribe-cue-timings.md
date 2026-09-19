# Plan: Fix overlapping Scribe cue timings

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-18
Last updated: 2026-09-18

## Goal

Prevent valid but overlapping Scribe word intervals from producing empty subtitle cue intervals. Preserve every word, provider timing bounds, and already-published streaming cues.

## Scope

- In scope: shared Scribe normalization, regression coverage, local verification.
- Out of scope: Jev routing, provider requests, transcription quality judgments, changing timestamps to invented durations.

## Acceptance Criteria

- [x] Nested, equal and transitive overlaps retain all words in valid, non-overlapping cues.
- [x] Touching words remain separable and grouped words count toward ordinary word limits.
- [x] Groups crossing the stable boundary are withheld; published prefixes match the final track.
- [x] Required project checks pass.

## Relevant Context

- Architecture docs: `docs/RELIABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Incident: video Y_vB-3R_BYc, job d84e9af4-043e-426b-85e5-e6decda4a9c3; five Scribe chunks completed, final merge failed with invalid_segment_timing. The same video failed before Jev was introduced.
- Known risks: a connected overlap group can exceed normal cue limits; preserving the source interval takes precedence over splitting it into invalid intervals. Existing raw positive sub-millisecond intervals are a separate concern.

## Implementation Steps

- [x] Inspect current state and both full/streaming callers.
- [x] Confirm acceptance criteria with failing regressions.
- [x] Group overlapping intervals before segmentation and stable cutoff filtering.
- [x] Add unit and pipeline regressions.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update reliability expectations.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Focused transcription/streaming suite: 85 tests pass (381 assertions). Six new regression cases failed before the change.
- Final-chunk pipeline regression rerun after expanding its fixture: passed.
- Pint: passed.
- Full root harness: 736 backend tests passed; 11 disposable-service tests skipped. All 366 extension tests, contracts, docs, TypeScript, Chrome build, and release guards passed.
- Full harness log: ignored `app/backend/storage/logs/scribe-overlap-check.log`.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-18 | Group strictly overlapping words using their running maximum end before filtering | Avoid invalid cue splits without fabricating timings or modifying published cues. |
| 2026-09-18 | Preserve word count inside each group | Grouping must not weaken ordinary cue size limits. |
| 2026-09-18 | Apply Ponytail and local subtitle-pipeline, AI SDK and Laravel best-practice skills | Shared normalizer is the narrowest fix. Laravel rule reader confirmed streaming invariants. Boost docs checked; no new dependencies or provider APIs. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-18 | Reproduced and fixed the timing failure. | Nested/equal timing regressions previously raised transcription_failed; now pass. |
| 2026-09-18 | Restarted idle local backend/workers and retried the original video through SubtitleJobService. | Same job, run 937219b9-7d82-4541-af24-4a016cca39ab completed in 55,029 ms. All five chunks merged in 4,683 ms; Jev selected OpenAI in 541 ms. |

## Completion Notes

- What changed: connected overlap groups are inseparable during cue construction and streaming publication.
- Validation results: focused tests, formatter and full root harness pass. Original video Y_vB-3R_BYc generated successfully and its track is saved in the local runtime.
- Simplicity/readability review: one shared normalizer change, no new services, configuration or fallback requests.
- Residual risk: inseparable groups can exceed usual display limits.
- Follow-up debt: none introduced.

## Commit Plan

One commit, `Keep overlapping Scribe words in valid subtitle cues`: shared normalizer, unit and pipeline regressions, reliability contract, and this plan. No remote push.
