# Plan: Whole Codebase Simplification Pass

Status: active
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Goal

Run a full code-simplifier pass over the active backend, extension, and contract code. Remove dead or duplicated execution paths, collapse indirection that no longer carries product value, and keep the current generation flow direct and inspectable.

Preserve existing behavior unless the current behavior is only supporting obsolete compatibility. Any change must remain contract-valid and pass the standard repository checks.

## Scope

- In scope: active Laravel subtitle job API, queue pipeline, tracing, metrics, and provider orchestration code.
- In scope: active WXT extension background/content/popup utilities and contract consumption.
- In scope: contract package scripts, schemas, fixtures, and generated types where simplification requires alignment.
- In scope: durable docs that describe behavior changed by this pass.
- Out of scope: new product features, provider changes, account/billing work, and public launch infrastructure.
- Out of scope: provider-backed timing runs that would spend credentials without explicit approval.
- Out of scope: pre-existing untracked files unless they are directly required for this pass.

## Acceptance Criteria

- [x] Current behavior and validation commands are mapped before editing.
- [x] Dead, duplicated, fallback-heavy, or obsolete compatibility code found during the pass is either removed or recorded as deliberate remaining debt.
- [x] Tests/docs are updated for any behavior or contract changes.
- [x] `.\scripts\agent\check.ps1` passes, or blockers are recorded with exact failure output.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`
- Build posture: `how_to_build.txt`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/active/saas-roadmap/01-generation-optimization.md`
- Known risk: the backend requires Laravel Boost `search-docs` per `app/backend/AGENTS.md`, but the Boost MCP tools are not exposed in this Codex session. Use local docs and tests, and avoid dependency/API changes unless necessary.
- Known risk: the repository has a pre-existing untracked `docs/TEMP_HANDOFF.md`; keep it out of this pass unless it proves directly relevant.

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

- Tests: `.\scripts\agent\check.ps1`
- Screenshots or video:
- Logs:
- Metrics or traces:

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-20 | Use the existing `codex/generation-optimization` branch for this pass. | The branch is already checked out, tracks origin, and matches the active generation optimization context. |
| 2026-05-20 | Treat `docs/TEMP_HANDOFF.md` as pre-existing user work. | It was untracked before this pass and is not required for the simplification criteria. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-20 | Plan created. | `scripts/agent/new-plan.ps1 -Title "Whole Codebase Simplification Pass"` |
| 2026-05-20 | Scope refined for active-path simplification. | Read `AGENTS.md`, `how_to_build.txt`, `ARCHITECTURE.md`, backend AGENTS, and active generation optimization plan. |
| 2026-05-20 | Fixed tier queue routing to read the persisted job tier instead of falling back to the mutable default tier. | Added `test_queue_name_uses_job_generation_tier_not_current_default`; targeted test passed. |
| 2026-05-20 | Removed batch telemetry's default queue fallback and passed the actual dispatched queue into the batch dispatched event. | Backend job and runtime tracing tests passed after the change. |
| 2026-05-20 | Made tokenization/translation batch analysis require full cue context instead of silently substituting the current batch. | Added `test_batch_agents_require_full_cue_context`; targeted test passed. |
| 2026-05-20 | Tightened extension runtime message guards so a matching `type` alone is not accepted as a valid payload. | Added `app/extension/tests/messages.test.ts`; targeted Vitest and TypeScript compile passed. |
| 2026-05-20 | Full harness validation passed. | `.\scripts\agent\check.ps1` passed: docs lint, contracts, Laravel tests, WXT tests, TypeScript compile, and WXT build. |

## Completion Notes

- What changed: fixed persisted-tier queue routing, removed a default queue fallback from batch telemetry, made AI cue-batch calls require full cue context, stopped suppressing `set_time_limit` failures, and tightened extension runtime message validation.
- Validation results: `vendor\bin\pint --dirty --format agent` passed, `git diff --check` passed, and `.\scripts\agent\check.ps1` passed.
- Simplicity/readability review: the changes remove silent fallbacks and false boundary typing without adding new services, dependencies, or alternate execution paths.
- Residual risk: Laravel Boost `search-docs` could not be run because the Boost MCP tools were not exposed in this session.
- Follow-up debt: none added.
