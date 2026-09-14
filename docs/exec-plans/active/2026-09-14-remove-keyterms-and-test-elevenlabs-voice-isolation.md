# Plan: Remove keyterms and test ElevenLabs voice isolation

Status: complete; isolation experiment rejected and discarded
Owner: agent
Work mode: standard (if the user selects Brain / Worker, follow `docs/work-modes/brain-worker.md` and add its chunk backlog and packet notes)
Created: 2026-09-14
Last updated: 2026-09-14

## Goal

Remove vocabulary hints and undo the user's local cache-column widening. Commit removal separately, then create codex/elevenlabs-voice-isolator for a single isolation pass before normal audio preparation. The user will test real audio quality and latency.

## Scope

- In scope: UI, contracts, provider arguments, storage, costs, cache identity, tests, local schema rollback, experimental isolation and temporary audio cleanup.
- Out of scope: production changes, paid provider runs by the agent, repeated transcription, unrelated cleanup.

## Acceptance Criteria

- [x] Remove active vocabulary-hint paths and its database column.
- [x] Roll back and remove the uncommitted widening migration; model keys fit 64 characters.
- [x] Commit removal before branching for isolation.
- [x] Isolate original audio before resampling/chunking; keep one transcription pass.
- [x] Prevent reuse of non-isolated results during the experiment.
- [x] Preserve safe failures, stale-run checks and audio cleanup.
- [x] Pass repository checks; leave the experiment ready for user testing.
- [x] Discard isolation after user evaluation, restore main and clear the local transcript cache.

## Relevant Context

- Product docs: docs/product-specs/index.md
- Architecture docs: ARCHITECTURE.md, docs/SECURITY.md, docs/RELIABILITY.md
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
- Known risks: isolation adds latency/cost and may harm singing. No acoustic improvement is assumed.

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

- Tests: main removal passed 567 backend tests and 277 extension tests; experimental implementation passed 585 backend tests and 277 extension tests before user evaluation.
- Screenshots or video: user tested the isolation branch and reported lower transcription quality.
- Logs: isolated-audio adapter and pipeline were tested with provider fakes; real ffmpeg smoke preserved a two-second fixture duration.
- Metrics or traces: no measured WER improvement established; subjective user evaluation rejected the experiment.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-14 | Keep removal separate from isolation. | Discarding the branch must not restore keyterms. |
| 2026-09-14 | Remove one oversized obsolete local transcript-cache row before shrinking the column. | The user authorized undoing this DB support; saved tracks and usage are preserved. |
| 2026-09-14 | Keep historical migrations; drop vocabulary_hints with a forward migration. | Existing databases have run the feature migration. |
| 2026-09-14 | Discard ElevenLabs Voice Isolator after the user's manual trial. | User reported lower transcription quality; retain the original-audio pipeline and consider alternatives separately. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-14 | Inspected main at eeb93e1 and three dirty tracked edits, all specific to hints. Widening migration ran locally in batch 9; one 110-character cache key; no queued/running jobs. | git diff, Boost DatabaseSchema and DatabaseQuery. |
| 2026-09-14 | Applied Ponytail, subtitle-pipeline, ai-sdk-development, laravel-best-practices, laravel-security and git-group-commits. | Boost v13 multipart/column docs; Context7 ElevenLabs isolation docs. |
| 2026-09-14 | Removed keyterms across extension, contracts, backend and schema. Rolled back local widening, removed its untracked migration and restored all three original dirty support edits. | Local schema confirms cache model varchar(64), no vocabulary_hints column. |
| 2026-09-14 | Removal validation passed. | scripts/agent/check.ps1: 567 backend tests / 4513 assertions; 277 extension tests; contracts, TypeScript and production build passed. |
| 2026-09-14 | Removal committed on main at 45e35e4; isolation implemented separately at a39eaea. | Isolated original audio before preparation, separate cache identity, single transcription pass, failures and stale-run cleanup tested. |
| 2026-09-14 | User rejected the isolation trial; switched back to main and deleted codex/elevenlabs-voice-isolator. | Working tree was clean before switching; no implementation from the discarded branch was merged. |
| 2026-09-14 | Restarted local backend and 31 workers on upload, then cleared cached_video_transcripts. | No queued/running jobs; three cache rows deleted, zero remain. Saved tracks and billing rows were not changed. |

## Completion Notes

- What changed: keyterms and its database support remain removed. The voice-isolation experiment was tested and discarded at the user's request.
- Validation results: implementation checks above passed before evaluation; rollback verified normal upload mode and an empty local transcript cache.
- Simplicity/readability review: original pipeline restored; no isolation dependencies, configuration or migrations remain.
- Residual risk: clearing transcript cache does not delete existing saved generations, which remain independently reusable.
- Follow-up debt: evaluate source selection, native sample-rate preservation and targeted preprocessing separately; no new experiment selected yet.
