# Plan: Lyrics replacement partial confirmation and AI merge

Status: completed (code and static review; user owns execution)
Owner: agent
Created: 2026-09-08
Last updated: 2026-09-08

## Goal

Keep hard validation and unrelated-song rejection, but turn suspected incomplete lyrics into an explicit confirmation. After confirmation, AI chooses how to combine the pasted lyrics with existing lyrics. Publish only a valid finished result and preserve the current track on failure.

## Scope

- In scope: AI match/completeness assessment, confirmed partial merge, API flag, panel warning and retry, regression tests, product documentation.
- Out of scope: audio verification, new timing generation, previews, undo, new dependencies, deployment.

## Acceptance Criteria

- [x] Complete matching lyrics replace normally without numerical coverage rejection.
- [x] Suspected incomplete lyrics leave the track unchanged and offer confirmation.
- [x] Confirmed partial lyrics are combined with existing lyrics by AI-selected placement; provided text is preserved and missing text is not invented.
- [x] Unrelated lyrics are rejected even when partial merging is allowed.
- [x] Edited drafts and changed videos/tracks cannot reuse old confirmation.
- [x] Structural, entitlement, concurrency, cancellation, privacy, and atomic publication protections remain.
- [x] Contract and regression-test code are updated; final execution is left to the user as requested.

## Relevant Context

- Product docs: `docs/product-specs/lyrics-editing.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/FRONTEND.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: existing lyrics-editing product specification.
- Known risks: AI match/completeness decisions remain probabilistic and use the existing transcript rather than audio. Existing lyrics may contain mistakes retained during partial merging.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation source code.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Record baseline evidence and the user's instruction to leave final validation to them.
- [x] Complete review notes.

## Validation Plan

Commands for the user (do not run as part of this implementation after their instruction):

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Baseline harness passed before implementation: 412 backend tests (2,867 assertions), 172 extension tests, contracts, compile, build.
- Contracts agent ran `npm run check` successfully before the user requested no further testing.
- Final changed backend and extension behavior will receive static review only. The user owns final tests, builds, and browser/provider validation.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-08 | Reuse `lyrics_incomplete` and submit a new attempt with optional `allowPartial: true` after confirmation. | Avoid a new long-lived confirmation status and retain existing private-state cleanup. |
| 2026-09-08 | AI decides match, completeness, and placement; server validates reconstruction. | User explicitly delegates merging to AI while retaining unrelated-song rejection and hard correctness guards. |
| 2026-09-08 | Use Luna agents at xhigh for backend, extension, and contracts, followed by primary-agent review. | Explicit user request. |
| 2026-09-08 | Stop executing tests, builds, and browser checks; finish code and static review. | User explicitly says they will do testing. This supersedes harness/skill execution requirements for this work. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-08 | Plan created. |  |
| 2026-09-08 | Inspected existing service, prompt, panel, and contracts; delegated bounded implementation slices. Baseline harness started. | Clean initial git status. |
| 2026-09-08 | Baseline harness passed; contracts updated. Static review caught asynchronous warning handling, stale draft consent, and permissive provider-field defaults. Agents instructed to address them. | Changes remain uncommitted on the existing branch. |
| 2026-09-08 | Extension delivered and reviewed: explicit warning is bound to the exact draft, track, video, and attempt; stale consent is checked again before submission. | Read-only diff review; no final execution. |
| 2026-09-08 | Backend uses ordered source segments for full/partial reconstruction. Review removed permissive provider defaults, aligned retry instructions, and restricted whitespace separators. | Invalid or unrelated output preserves the current track. |

## Completion Notes

- What changed: AI separates unrelated and incomplete lyrics. Confirmed partial requests merge referenced pasted/existing parts, including within cues. Unspaced non-Latin existing cues use grapheme boundaries. The panel handles asynchronous warnings and binds consent to the exact draft/track/video/attempt. Contracts, fixtures, and product/architecture/reliability docs match.
- Validation results: baseline and early contract checks passed before the user stopped further testing. Final changes are statically reviewed only; tests, compilation, builds, and browser/provider checks were not run on the final implementation, by explicit user instruction.
- Simplicity/readability review: reused the existing attempt row, error code, encrypted work state, and inline confirmation. No dependencies, migrations, or new public statuses. Fixed review findings for stale consent, obsolete variable names, permissive classification defaults, source separators, retry instructions, and obsolete test fixtures. Code is ready for user validation, not claimed verified for release.
- Residual risk: AI match/completeness/placement decisions remain probabilistic and rely on the existing transcript. Partial merging can retain its mistakes. Runtime/provider behavior remains for user validation.
- Follow-up debt: no new infrastructure debt. Final execution is explicitly handed to the user. Changes are left uncommitted on the existing branch; no push or deployment was requested.
