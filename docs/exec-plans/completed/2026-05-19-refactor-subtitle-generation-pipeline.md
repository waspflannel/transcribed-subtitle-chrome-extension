# Plan: Refactor subtitle generation pipeline

Status: completed
Owner: agent
Created: 2026-05-19
Last updated: 2026-05-19

## Goal

Refactor the backend subtitle generation pipeline so `SubtitleGenerationPipeline` reads as the high-level workflow while queue configuration, Laravel batch dispatch, cue-batch worker execution, failure mapping, and telemetry mechanics live in focused services.

Runtime behavior must stay the same: no public API, contract, extension progress stage, provider behavior, artifact retention, failure message, or sanitized trace guarantee changes.

## Scope

- In scope:
- Backend subtitle pipeline service extraction.
- Descriptive continuation job and pipeline method renames.
- Existing tests updated for renamed services/classes and preserved behavior.
- Focused documentation updates only if architecture or observability docs become inaccurate.
- Out of scope:
- Extension changes, API/contract changes, provider prompt/schema behavior changes, queue infrastructure changes, and compatibility aliases for old queued continuation job class names.

## Acceptance Criteria

- [x] `SubtitleGenerationPipeline` owns orchestration only: transcription, dispatching analysis/romanization/enrichment batches, merging batch results, and final track persistence.
- [x] Telemetry payload construction, queue wait/stage timing, slow warnings, stale-run traces, batch lifecycle traces, completion traces, and failure trace payloads are outside the pipeline.
- [x] Laravel batch dispatch mechanics and queue/connection wiring are outside the pipeline.
- [x] Tokenization, translation, romanization, and enrichment workers share one guarded cue-batch execution path.
- [x] Queue job `failed()` callbacks call a dedicated failure handler instead of the pipeline.
- [x] Queue configuration is exposed by `SubtitleQueue::connection()` and `SubtitleQueue::name()`.
- [x] Ambiguous continuation job/method names are replaced with self-documenting names.
- [x] Public job stages remain unchanged: `transcribing`, `tokenizing`, `translating`, `romanizing`, `enriching`, `finalizing`.
- [x] Existing trace event names and sanitized payload constraints remain intact unless only trace-only stage labels are clarified.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/OBSERVABILITY.md`, `docs/RELIABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-18-subtitle-runtime-trace-logging.md`, `docs/exec-plans/completed/2026-05-16-postgres-redis-parallel-queue-runtime.md`
- Known risks:
- Renaming queued job classes can strand already-queued payloads, so this refactor assumes queues are drained or cleared before deployment.
- Trace tests are likely to catch sanitized context regressions, but a careless extraction could preserve logs while weakening persisted trace rows.
- The current desktop runtime may still block live Postgres + Redis proof; local PHPUnit and harness checks are the acceptance gate.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: introduce `SubtitleQueue`, `SubtitlePipelineTelemetry`, `SubtitleBatchDispatcher`, and `SubtitleJobFailureHandler`.
- [x] Slice 2: introduce `SubtitleCueBatchProcessor` and move cue-batch worker execution out of the pipeline.
- [x] Slice 3: rename continuation jobs/methods and update queue tracer allow-list, call sites, and tests.
- [x] Slice 4: simplify `SubtitleGenerationPipeline` around the self-documenting workflow and remove old private helpers.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact tests/Feature/SubtitleJobApiTest.php tests/Feature/SubtitleRuntimeTracingTest.php tests/Unit/SubtitleRuntimeTracerTest.php tests/Unit/SubtitleWorkflowLoggerTest.php; Pop-Location
Push-Location .\app\backend; vendor\bin\pint --dirty --format agent; Pop-Location
Push-Location .\app\backend; php artisan test --compact; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: focused backend tests, full backend tests, harness check.
- Screenshots or video: not applicable.
- Logs: trace-event assertions from existing tests.
- Metrics or traces: queue wait, stage timing, slow warning, batch lifecycle, stale run, and failure trace assertions.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-19 | Keep public progress stage values unchanged while allowing clearer trace-only continuation labels. | Extension progress helpers and public job APIs depend on these stage strings; trace-only labels are internal diagnostics. |
| 2026-05-19 | Do not keep old continuation job aliases. | The plan assumes drained queues and the project guardrails prefer one active path over compatibility wrappers. |
| 2026-05-19 | Use focused services instead of a generic pipeline engine. | The real workflow has stage dependencies and branches; explicit orchestration is clearer than a speculative abstraction. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-19 | Plan created and refined from the requested implementation plan. | `AGENTS.md`; `ARCHITECTURE.md`; `docs/OBSERVABILITY.md`; `docs/RELIABILITY.md`; Context7 Laravel queue batch docs. |
| 2026-05-19 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed: contracts, backend tests, extension tests, TypeScript compile, WXT build. |
| 2026-05-19 | Implemented the service extraction and renamed continuation jobs/methods. | `SubtitleQueue`, `SubtitlePipelineTelemetry`, `SubtitleBatchDispatcher`, `SubtitleJobFailureHandler`, `SubtitleCueBatchProcessor`, renamed queue jobs, simplified `SubtitleGenerationPipeline`. |
| 2026-05-19 | Focused backend validation passed. | `php artisan test --compact tests/Feature/SubtitleJobApiTest.php tests/Feature/SubtitleRuntimeTracingTest.php tests/Unit/SubtitleRuntimeTracerTest.php tests/Unit/SubtitleWorkflowLoggerTest.php` passed: 59 tests / 413 assertions. |
| 2026-05-19 | Formatting completed and focused validation still passed. | `vendor\bin\pint --dirty --format agent` fixed `SubtitleBatchDispatcher.php`; focused backend tests passed again: 59 tests / 413 assertions. |
| 2026-05-19 | Full validation and self-review completed. | `php artisan test --compact` passed: 131 tests / 681 assertions; `.\scripts\agent\check.ps1` passed before and after plan archival; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings; `git diff --check` passed. |

## Completion Notes

- What changed: Extracted queue config, telemetry, batch dispatch, cue-batch processing, and failure handling from `SubtitleGenerationPipeline`; renamed continuation jobs and methods to describe their join/merge work; updated queue tracer allow-list, runtime commands, job dispatches, and tests.
- Validation results: Focused backend tests passed twice; full backend tests passed; full harness check passed; PR verification passed; doc gardening and whitespace checks passed.
- Simplicity/readability review: The pipeline now reads as explicit workflow orchestration and dropped repeated worker, tracing, failure, and batch callback mechanics. No generic pipeline engine or compatibility aliases were added.
- Residual risk: Already-queued payloads using the removed continuation job class names must be drained or cleared before deployment, as planned.
- Follow-up debt: None added.
