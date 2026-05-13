# Plan: Codebase Simplification Refactor

Status: completed
Owner: agent
Created: 2026-05-13
Last updated: 2026-05-13

## Goal

Perform a full repository cleanup pass that removes avoidable defensive code, stale compatibility paths, obsolete comments, dead code, and unnecessary indirection. The preferred shape is one clear current product path that validates external inputs at boundaries, then trusts normalized internal values.

Make the codebase loud when an internal assumption is broken instead of hiding problems behind fake defaults, dodge flags, or "kept both for safety" logic. Keep validation for external provider/user/browser boundaries, but remove repeated internal checks when earlier validation or canonical contracts already establish the invariant.

## Scope

- In scope: application code in `app/backend`, `app/extension`, and `packages/contracts`; tests that lock in obsolete paths; docs that describe removed behavior; harness evidence for validation.
- Out of scope: new product features, provider changes, broad UI redesign, new dependencies, database reset/reseed behavior, and cleanup inside vendored/generated dependency directories.

## Acceptance Criteria

- [x] Branch `refactor` contains the cleanup work.
- [x] Dead code, obsolete comments, compatibility wrappers, one-use indirection, fake defaults, and speculative guard paths are removed where current contracts make them unnecessary.
- [x] Remaining validation is concentrated at untrusted boundaries: browser messages, HTTP requests/responses, provider responses, persisted/generated tracks, and contract checks.
- [x] Tests and docs match the simplified current behavior instead of preserving removed compatibility paths.
- [x] `.\scripts\agent\check.ps1` runs before handoff and results are recorded here.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-13-tokenization-pipeline-cleanup-refactor.md`, `docs/exec-plans/completed/2026-05-12-simple-ai-tokenization-pipeline-refactor.md`, `docs/exec-plans/completed/2026-05-11-many-to-many-language-refactor.md`
- Known risks: removing a defensive check can expose a real boundary inconsistency; prefer that visibility for internal invariants, but keep boundary validation where external data enters.

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

- Tests: `.\scripts\agent\check.ps1` passed, including contracts validation/build, backend tests, extension tests, TypeScript compile, and WXT build. `.\scripts\agent\verify-pr.ps1` passed with the same harness checks.
- Screenshots or video: not applicable; no visual UI redesign was performed.
- Logs: `git diff --check` reported only CRLF normalization warnings, no whitespace errors.
- Metrics or traces: backend suite passed 88 tests / 442 assertions; extension suite passed 39 tests.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-13 | Treat this as a simplification refactor, not a feature phase. | The user requested a broad code cleanup pass with no new product behavior. |
| 2026-05-13 | Keep boundary validation, remove repeated internal fallback checks when contracts already establish the shape. | This follows `docs/references/project-guardrails.md` and the requested loud-failure posture. |
| 2026-05-13 | Require provider URLs and model names explicitly rather than deriving cloud defaults in config. | Missing AI/transcription configuration should fail at the boundary instead of silently selecting a model or endpoint. |
| 2026-05-13 | Make the API throttler depend on explicit middleware priority instead of a fake missing-install bucket. | Missing install IDs should be handled by validation middleware; broken route ordering should be visible. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-13 | Plan created and scoped from user request. | `git switch -c refactor`; this plan. |
| 2026-05-13 | Removed tokenless enrichment/romanization fallbacks, scaffold code, stale compatibility wrappers, and defaulted provider model config. | Backend and contract tests updated; generated contracts rebuilt. |
| 2026-05-13 | Tightened extension runtime paths for invalid JSON, invalid labels, invalid timestamps, empty token fallbacks, and stale WebVTT matching. | Extension Vitest and TypeScript compile pass. |
| 2026-05-13 | Completed full harness validation. | `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`; `git diff --check`. |

## Completion Notes

- What changed: Backend generation now fails visibly for invalid tokenization, romanization, missing provider metadata, unsupported audio types, and missing provider config; contract schemas require `youtubeUrl`, non-empty cue tokens, and token `normalizedText`; extension code no longer carries stale source-text/token matching fallbacks; unused Laravel scaffold/auth/web/queue/frontend files were removed.
- Validation results: `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed; `git diff --check` had only CRLF normalization warnings.
- Simplicity/readability review: The current path is API-only, synchronous, tokenized, and contract-first. Remaining validation is boundary validation rather than hidden compatibility behavior.
- Residual risk: This intentionally removes forgiving behavior, so future real provider inconsistencies will surface as generation failures and should be handled with narrow evidence-backed fixes.
- Follow-up debt: None added.

