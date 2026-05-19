# Plan: Async Pipeline Simplification Review

Status: completed
Owner: agent
Created: 2026-05-18
Last updated: 2026-05-18

## Goal

Review the current async subtitle pipeline branch and remove avoidable compatibility layers that obscure the active product path. The final shape should match `how_to_build.txt`: user-triggered generation creates one explicit queued pipeline, independent cue work runs in parallel batches, invalid state fails loudly, and stale migration paths are not preserved as hidden defaults.

## Scope

- In scope: backend queued subtitle generation, AI batch provider entrypoints, failed job API contracts, extension polling/error mapping, runtime/docs that describe those paths.
- Out of scope: provider prompt redesign, new product features, visual overlay redesign, dependency changes, production deployment automation.

## Acceptance Criteria

- [x] Web requests dispatch subtitle work but do not manage OS queue worker processes.
- [x] Queued subtitle jobs require the current `run_id`; stale or missing queued identities do not remain a compatibility path.
- [x] AI provider public methods match the active batch pipeline; removed synchronous whole-track methods are not kept only for tests.
- [x] Failed job polling returns a stable `errorCode` contract field so the extension does not infer backend failure type from exact message text.
- [x] API resources do not invent default job stages or failure messages for invalid internal rows.
- [x] Tests and docs reflect the current path instead of old worker bootstrap or sequential provider behavior.

## Relevant Context

- Product docs:
- Architecture docs: `ARCHITECTURE.md`, `how_to_build.txt`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-14-async-ai-subtitle-pipeline-optimization.md`, `docs/exec-plans/completed/2026-05-16-postgres-redis-parallel-queue-runtime.md`, `docs/exec-plans/completed/2026-05-18-subtitle-runtime-trace-logging.md`
- Known risks: broad branch delta, generated contract files, Laravel queue batch behavior, extension types generated from schemas.

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
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: contracts check passed; backend 131 tests / 681 assertions passed; extension 9 test files / 41 tests passed; TypeScript compile passed; WXT build passed; `.\scripts\agent\check.ps1` passed.
- Screenshots or video: not applicable; this pass did not change UI layout.
- Logs: command output from focused checks and harness run.
- Metrics or traces: not applicable; no provider-backed runtime run was performed.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-18 | Keep Laravel batches for parallel cue work, but remove web-request-owned queue worker process bootstrapping. | Batches are the active execution model; worker process management is local runtime compatibility machinery and violates the direct product path. |
| 2026-05-18 | Add failed job `errorCode` to the API contract. | The extension should use the backend's stable public error code instead of matching exact message strings. |
| 2026-05-18 | Remove unused synchronous provider methods. | The current architecture runs cue work through queued batch methods; tests should exercise the active entrypoints. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-18 | Plan created. |  |
| 2026-05-18 | Removed request-owned queue worker bootstrapper, explicit auto-worker config, and `.env.parallel.example`; runtime now uses explicit queue workers. | Backend tests and harness passed. |
| 2026-05-18 | Required queued `run_id`, removed old synchronous AI provider entrypoints, added failed-job `errorCode`, and tightened API/loading state defaults. | Contracts check, backend tests, extension tests/compile/build, and harness passed. |

## Completion Notes

- What changed: deleted `SubtitleQueueWorkerBootstrapper`; removed auto-worker env/config/docs/tests; required `run_id` in queued jobs and migration; removed sequential provider methods; changed failed job responses/history to include `errorCode`; removed message/stage fake defaults; updated extension failure mapping and loading state typing.
- Validation results: `npm run check` in contracts passed; `php artisan test --compact` passed; `npm test`, `npm run compile`, and `npm run build` in extension passed; `.\scripts\agent\check.ps1` passed.
- Simplicity/readability review: the active path is now API dispatch -> queued transcription -> parallel cue batches -> continuation/finalization, without web-request process management or string-inferred failure routing.
- Residual risk: real provider-backed Postgres + Redis timing proof remains tracked in `TD-009`.
- Follow-up debt: none added.
