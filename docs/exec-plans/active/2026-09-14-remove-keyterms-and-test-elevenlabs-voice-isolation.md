# Plan: Remove keyterms and test ElevenLabs voice isolation

Status: active
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
- [ ] Commit removal before branching for isolation.
- [ ] Isolate original audio before resampling/chunking; keep one transcription pass.
- [ ] Prevent reuse of non-isolated results during the experiment.
- [ ] Preserve safe failures, stale-run checks and audio cleanup.
- [ ] Pass repository checks; leave the experiment ready for user testing.

## Relevant Context

- Product docs: docs/product-specs/index.md
- Architecture docs: ARCHITECTURE.md, docs/SECURITY.md, docs/RELIABILITY.md
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
- Known risks: isolation adds latency/cost and may harm singing. No acoustic improvement is assumed.

## Implementation Steps

- [ ] Inspect current state.
- [ ] Confirm or refine acceptance criteria.
- [ ] Implement the smallest end-to-end slice.
- [ ] Add or update validation.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Update docs and quality score if needed.
- [ ] Run validation and record evidence.
- [ ] Complete review notes.

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
| 2026-09-14 | Keep removal separate from isolation. | Discarding the branch must not restore keyterms. |
| 2026-09-14 | Remove one oversized obsolete local transcript-cache row before shrinking the column. | The user authorized undoing this DB support; saved tracks and usage are preserved. |
| 2026-09-14 | Keep historical migrations; drop vocabulary_hints with a forward migration. | Existing databases have run the feature migration. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-14 | Inspected main at eeb93e1 and three dirty tracked edits, all specific to hints. Widening migration ran locally in batch 9; one 110-character cache key; no queued/running jobs. | git diff, Boost DatabaseSchema and DatabaseQuery. |
| 2026-09-14 | Applied Ponytail, subtitle-pipeline, ai-sdk-development, laravel-best-practices, laravel-security and git-group-commits. | Boost v13 multipart/column docs; Context7 ElevenLabs isolation docs. |
| 2026-09-14 | Removed keyterms across extension, contracts, backend and schema. Rolled back local widening, removed its untracked migration and restored all three original dirty support edits. | Local schema confirms cache model varchar(64), no vocabulary_hints column. |
| 2026-09-14 | Removal validation passed. | scripts/agent/check.ps1: 567 backend tests / 4513 assertions; 277 extension tests; contracts, TypeScript and production build passed. |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:
