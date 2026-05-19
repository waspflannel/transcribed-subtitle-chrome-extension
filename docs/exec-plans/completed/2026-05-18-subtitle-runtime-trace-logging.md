# Plan: Subtitle runtime trace logging

Status: completed
Owner: agent
Created: 2026-05-18
Last updated: 2026-05-18

## Goal

Build a sanitized, queryable runtime trace layer for the async subtitle pipeline. Developers should be able to inspect active jobs, prove queue workers are processing in parallel, reconstruct failed runs, and compare queue/stage/provider timings without reading raw provider payloads or leaking user/video-derived text.

Keep the public extension API unchanged. The work is backend-only diagnostics: persisted trace events, structured logs, per-run stale-work guards, queue/batch/worker instrumentation, timing warnings, and local CLI inspection commands.

## Scope

- In scope:
- Add per-generation `run_id` fencing to subtitle jobs and queued subtitle job payloads.
- Add persisted sanitized subtitle job events and structured log emission through one tracing service.
- Instrument queue lifecycle hooks, batch callbacks, pipeline stages, worker bootstrap, failures, and pruning.
- Add Artisan commands for job trace, runtime status, and slow-stage inspection.
- Update backend tests and durable observability/reliability/security docs.
- Out of scope:
- Public API, contract, or extension UI changes.
- HTTP diagnostics endpoints or dashboard UI.
- Raw transcript/cue/token/prompt/translation/romanization/audio-path logging.
- Laravel Horizon or external metrics/tracing infrastructure.

## Acceptance Criteria

- [x] `subtitle_jobs` has a fresh `run_id` for each create/reset, and all subtitle queued jobs carry it.
- [x] Stale queued jobs no-op before provider calls or artifact writes and emit a sanitized stale-run trace event.
- [x] `subtitle_job_events` persists compact sanitized events with job/run/queue/batch/timing/failure context.
- [x] `SubtitleRuntimeTracer` emits both DB events and structured Laravel logs, omitting unsafe context keys.
- [x] Laravel queue hooks emit processing, processed, and failed events for subtitle queue jobs.
- [x] Batch callbacks emit dispatched/progress/completed/failed/finalized events.
- [x] Pipeline traces include queue wait, stage start/completion, slow warnings, failure, and completion timings.
- [x] `subtitles:trace`, `subtitles:runtime`, and `subtitles:slow` support human output and `--json`.
- [x] Pruning removes trace events for deleted expired jobs.
- [x] Backend tests cover sanitization, stale run guards, queue/batch/failure/timing events, slow warnings, pruning, and commands.
- [x] Full harness validation passes or blockers are recorded.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/OBSERVABILITY.md`, `docs/RELIABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
- `docs/exec-plans/completed/2026-05-16-postgres-redis-parallel-queue-runtime.md`
- Known risks:
- This desktop PHP runtime still lacks `pdo_pgsql`, so live Postgres + Redis proof remains blocked by `TD-009`.
- Queue hooks must avoid logging unrelated queues and must not fail real queue processing if tracing persistence has an issue.
- Payloads must remain scalar and sanitized so queue records do not carry transcript or generated learning content.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: add trace persistence, model, tracer service, sanitization tests, and job `run_id`.
- [x] Slice 2: thread `run_id` through queued jobs and add stale-run guards.
- [x] Slice 3: instrument queue hooks, batch callbacks, worker bootstrap, pipeline timings, failures, and pruning.
- [x] Slice 4: add CLI diagnostics and command tests.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\backend; vendor\bin\pint --dirty --format agent; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: focused backend feature/unit tests, then full backend and harness tests.
- Screenshots or video:
- Logs: sanitized trace event expectations in tests.
- Metrics or traces: command JSON output and persisted timing rows from tests.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-18 | Use DB trace rows plus structured logs, not a dashboard or HTTP diagnostics endpoint. | The current release needs inspectable local diagnostics without adding user-facing or secured admin surface. |
| 2026-05-18 | Add `run_id` fencing to queued work. | It improves trace reconstruction and prevents reset/retry races from old queued payloads doing provider or artifact work. |
| 2026-05-18 | Keep trace context sanitized-only. | Project security docs forbid logging raw audio paths, prompts, transcripts, translations, token payloads, or provider secrets by default. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-18 | Plan created and refined from the requested phase. | `docs/exec-plans/active/2026-05-18-subtitle-runtime-trace-logging.md` |
| 2026-05-18 | Loaded harness, backend, Laravel queue, security, and subtitle-pipeline context. | `AGENTS.md`; `app/backend/AGENTS.md`; Boost skill list; Context7 Laravel queue docs. |
| 2026-05-18 | Baseline validation passed before implementation. | `.\scripts\agent\check.ps1` passed: contracts, backend tests, extension tests, TypeScript compile, WXT build. |
| 2026-05-18 | Implemented trace persistence, `run_id` fencing, queue/batch/stage/failure tracing, worker trace logs, pruning integration, and diagnostic commands. | `subtitle_job_events`, `SubtitleRuntimeTracer`, subtitle queued jobs, pipeline, commands. |
| 2026-05-18 | Focused trace validation passed. | `php artisan test --compact tests/Unit/SubtitleRuntimeTracerTest.php tests/Feature/SubtitleRuntimeTracingTest.php tests/Feature/SubtitleJobApiTest.php tests/Feature/PruneExpiredSubtitleTracksTest.php` passed: 59 tests / 410 assertions. |
| 2026-05-18 | Hardened queue hook scoping and enriched failure traces with worker, batch, and last-successful-event context. | `SubtitleRuntimeTracer`; `SubtitleGenerationPipeline`; focused tests passed: 59 tests / 413 assertions. |
| 2026-05-18 | Hardened sanitizer coverage for URL/path/token-like context keys. | `php artisan test --compact tests/Unit/SubtitleRuntimeTracerTest.php tests/Feature/SubtitleRuntimeTracingTest.php` passed: 7 tests / 28 assertions. |
| 2026-05-18 | Final validation passed. | `php artisan test --compact` passed: 132 tests / 673 assertions; `.\scripts\agent\check.ps1` passed after plan archival; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings. |

## Completion Notes

- What changed: Added DB-backed sanitized trace events, `run_id` fencing for queued subtitle work, queue/batch/stage/failure/worker instrumentation, artifact timing traces, slow-operation warnings, pruning coverage, and local diagnostic commands.
- Validation results: `vendor\bin\pint --dirty --format agent`, focused trace tests, full backend tests, full harness check, doc gardening, and PR verification all passed.
- Simplicity/readability review: The implementation keeps tracing centralized in `SubtitleRuntimeTracer`, uses existing Laravel queue/batch hooks and Artisan commands, and avoids public API or extension contract changes.
- Residual risk: Live Postgres + Redis multi-worker proof remains blocked on this machine until `pdo_pgsql` is enabled; keep `TD-009` open for the manual proof run.
- Follow-up debt: No new follow-up debt added beyond the existing `TD-009` live runtime proof blocker.
