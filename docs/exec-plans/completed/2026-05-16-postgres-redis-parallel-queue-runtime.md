# Plan: Postgres Redis Parallel Queue Runtime

Status: completed
Owner: agent
Created: 2026-05-16
Last updated: 2026-05-16

## Goal

Move subtitle generation to a real parallel runtime profile without changing the public API or completed-track UX. Postgres is the app data store for subtitle jobs, artifacts, tracks, failed jobs, cache rows, and Laravel batch metadata. Redis is the queue store for `subtitle-ai` work. Multiple `subtitle-ai` workers process cue-batch jobs concurrently after transcription.

Keep SQLite only as a test/dev-lite profile, not as an equal performance runtime. SQLite remains useful for PHPUnit in-memory tests and simple local smoke runs, but the branch's documented runtime path is Postgres + Redis. Any SQLite-specific code must stay small, explicit, and tied to a real current need such as the one-worker cap that prevents local database lock failures.

## Scope

- In scope:
  - Add Laravel Postgres and Redis connection configuration.
  - Add Redis queue configuration for subtitle jobs with explicit queue name, retry, and blocking wait env keys.
  - Add root Docker Compose services for Postgres and Redis on non-default host ports.
  - Add a parallel runtime env example and update backend/runtime docs so Postgres + Redis is the recommended performance profile.
  - Keep the existing AI job graph: transcription, tokenization and optional translation in the same first Laravel batch, optional romanization after tokenization, optional full enrichment, finalize.
  - Allow configured multi-worker auto-start for Redis-backed local runs; keep SQLite/database-queue auto-start capped at one worker.
  - Add cancellation guards to cue-batch jobs before provider calls.
  - Refactor subtitle request dispatch state so create, reuse, reset, and dispatch decisions are explicit.
  - Add sanitized timing logs for queue wait, transcription, each AI batch type, continuation/finalization, and total completed-track duration.
  - Add focused backend tests for the runtime/profile behavior and queue graph.
- Out of scope:
  - Public API or contract response shape changes.
  - Merging tokenization, translation, romanization, and enrichment agents.
  - Redis cache by default; use database cache unless a Redis cache profile is explicitly configured.
  - Laravel Horizon, object storage, WebSockets, accounts, cancellation API, provider failover, or new user-facing states.
  - Preserving SQLite as a true parallel runtime.

## Acceptance Criteria

- [x] `app/backend/config/database.php` defines `pgsql` and `redis` connections while retaining `sqlite` for tests/dev-lite.
- [x] `app/backend/config/queue.php` defines a `redis` queue connection using env-driven connection, queue name, `retry_after`, `block_for`, and `after_commit=false`.
- [x] The selected subtitle queue connection remains a single source of truth through `SubtitleGenerationPipeline::connection()`.
- [x] Root Docker Compose starts Postgres and Redis on non-conflicting local host ports and uses named volumes.
- [x] `app/backend/.env.parallel.example` documents the Postgres + Redis runtime: `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `SUBTITLE_QUEUE_CONNECTION=redis`, `CACHE_STORE=database`, Redis queue env keys, and `SUBTITLE_AUTO_WORKER_COUNT=3`.
- [x] Runtime docs clearly say Postgres + Redis is the performance profile and SQLite is only for tests/simple dev-lite smoke.
- [x] Redis local auto-start can launch the configured number of `subtitle-ai` workers; SQLite-backed database queue auto-start launches exactly one worker.
- [x] Worker lifecycle logs include queue connection, DB driver, worker count, queue name, worker PID when available, start result, and configured max lifetime.
- [x] Production docs say `SUBTITLE_AUTO_START_WORKERS=false` and run supervised `subtitle-ai` workers.
- [x] `TokenizeSubtitleCueBatch`, `TranslateSubtitleCueBatch`, `RomanizeSubtitleCueBatch`, and `EnrichSubtitleCueBatch` keep payloads limited to job ID, batch index, and at most scalar queue timing metadata if Laravel's native payload timestamp is not available.
- [x] Cancelled cue-batch jobs skip provider calls before doing AI work.
- [x] Tokenization and translation jobs are dispatched into the same first Laravel batch when translation is enabled.
- [x] Romanization waits for tokenized batch artifacts.
- [x] Full enrichment waits for merged tokenized/translated/romanized cues.
- [x] Duplicate running requests do not dispatch duplicate work.
- [x] Stale `preparing` requests reset and dispatch exactly once.
- [x] Timing logs do not contain transcripts, cue text, token text, prompts, translations, romanization text, or raw provider payloads.
- [x] Backend, extension, contracts, and harness validation commands pass or any blocker is recorded with the failing command and reason.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Build guidance: `how_to_build.txt`
- Guardrails: `docs/references/project-guardrails.md`
- Related plans:
  - `docs/exec-plans/completed/2026-05-14-async-ai-subtitle-pipeline-optimization.md`
  - `docs/exec-plans/completed/2026-05-14-optional-cue-translation-agent.md`
  - `docs/exec-plans/completed/2026-05-14-refactor-ai-prompt-ownership-to-agents.md`
- Current code anchors:
  - `app/backend/config/database.php` currently only defines SQLite.
  - `app/backend/config/queue.php` currently has sync, background, database, deferred, and failover, but no Redis queue connection.
  - `app/backend/config/cache.php` currently has array and database stores only.
  - `app/backend/app/Services/Subtitles/SubtitleQueueWorkerBootstrapper.php` currently auto-starts only the database queue and caps SQLite at one worker.
  - `app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php` already stores intermediate artifacts outside queue payloads and dispatches Laravel batches.
  - `app/backend/phpunit.xml` uses SQLite `:memory:` for tests.
- Documentation evidence:
  - `docs/RELIABILITY.md` already states SQLite-backed local queues are capped at one worker to avoid `database is locked` contention.
  - `docs/references/project-guardrails.md` allowed Redis/Postgres only after evidence that SQLite/local storage is insufficient.
  - The completed async queue plan left live multi-worker latency and provider limit evidence as residual risk.
- Laravel docs checked through Context7:
  - Redis queues support `retry_after` and `block_for`.
  - Batched jobs should share one connection and queue.
  - Batched jobs should check cancellation or use `SkipIfBatchCancelled`.
  - Redis requires either the PhpRedis extension or `predis/predis`.
- Known risks:
  - Adding Redis requires choosing a PHP Redis client. Prefer a predictable local setup: add `predis/predis` unless the branch explicitly standardizes on `ext-redis`.
  - Queue wait timing conflicts with strict "job ID and batch index only" payloads. First try to derive wait from Laravel queue metadata; only add a scalar timestamp if needed.
  - Batch cancellation prevents queued jobs from starting provider calls, but it cannot stop provider calls already in progress.
  - More workers can hit OpenAI provider limits; default to three workers until manual timing evidence proves a higher value.
  - Cross-platform worker PID capture is easy to get wrong. Implement the smallest explicit platform-specific PID capture needed for Windows and non-Windows local runs, and log when PID capture is unavailable.
  - Postgres migration behavior may expose SQLite-only migration assumptions, especially `dropUnique`, JSON columns, and timestamp defaults.

## Implementation Steps

- [x] Inspect current queue, database, cache, migration, worker bootstrap, and subtitle pipeline code before editing.
- [x] Decide and document the Redis PHP client: `predis/predis` for local predictability unless the environment already guarantees PhpRedis.
- [x] Add Postgres and Redis config in `config/database.php`; keep SQLite config only for tests/dev-lite.
- [x] Add the Redis queue connection in `config/queue.php` and keep batch/failed-job metadata on the selected database connection.
- [x] Add or adjust cache config only as needed to keep `CACHE_STORE=database` working on Postgres; do not default cache to Redis.
- [x] Add root Docker Compose services for Postgres and Redis with non-default host ports, stable credentials for local development, and named volumes.
- [x] Add `app/backend/.env.parallel.example` and update backend README/runtime docs with exact commands for compose up, migrations, server, and workers.
- [x] Update `SubtitleQueueWorkerBootstrapper` so Redis local auto-start honors configured worker count, SQLite/database queue is capped at one worker, production auto-start guidance remains explicit, and lifecycle logs include the required metadata.
- [x] Add focused worker-bootstrap tests for SQLite one-worker cap and Redis configured worker count.
- [x] Add cancellation guards to the four cue-batch jobs using Laravel's batch cancellation middleware or an early batch cancellation check, without adding a custom abstraction unless repeated code becomes meaningfully clearer.
- [x] Add tests proving cancelled tokenization, translation, romanization, and enrichment batch jobs do not call providers.
- [x] Refactor `SubtitleJobService::generate()` dispatch state from `$shouldDispatch` into explicit create/reuse/reset dispatch outcome names.
- [x] Add or adjust tests for duplicate running requests and stale `preparing` reset dispatch behavior.
- [x] Review concurrent duplicate request handling under Postgres. If the current query-then-create flow can race on the unique index, catch the unique constraint and reload the existing compatible job.
- [x] Add sanitized timing logs around transcription, each cue batch type, continuations, finalization, queue wait, and total completion duration.
- [x] Add logger tests that verify timing fields exist and sensitive text/prompt fields do not.
- [x] Verify tokenization and translation dispatch together in the first batch, romanization waits for tokenized artifacts, and full enrichment waits for merged cues.
- [x] Run the full validation plan and record evidence.
- [x] Update durable docs: `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, backend README, and `docs/QUALITY_SCORE.md` if the quality/risk posture changes.
- [x] Check the implementation against `docs/quality/golden-principles.md`, remove stale SQLite-as-runtime wording, and complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: backend runtime/worker/batch tests, extension test output, contracts check output, harness output.
- Screenshots or video: not expected unless frontend behavior unexpectedly changes.
- Logs:
  - SQLite/dev-lite run shows one auto-started worker.
  - Postgres + Redis run shows multiple worker PIDs processing `subtitle-ai`.
  - Timing logs contain sanitized stage and queue timing only.
- Metrics or traces:
  - One SQLite/dev-lite manual run and one Postgres + Redis manual run on the same or comparable public video.
  - Compare completed-track duration and queue wait time.
  - Record provider rate-limit observations if parallel workers trigger throttling.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-16 | Make Postgres + Redis the branch's real runtime profile. | SQLite/database queue cannot deliver true parallel cue-batch processing because local SQLite queue writes are capped at one worker to avoid lock contention. |
| 2026-05-16 | Keep SQLite only for tests and simple dev-lite smoke. | PHPUnit already uses SQLite `:memory:` and retaining it there avoids slow test infrastructure without preserving a second production/runtime path. |
| 2026-05-16 | Keep AI agents separate and preserve the existing batch graph. | The performance problem is queue/runtime parallelism, not agent ownership. Merging agents would reduce clarity and change behavior risk. |
| 2026-05-16 | Keep database cache by default. | Redis is being introduced to solve queue concurrency first; using it for cache too would expand blast radius without current evidence. |
| 2026-05-16 | Do not store transcript, cue, token, translation, romanization, or prompt payloads in queued jobs or logs. | The current artifact-store pattern keeps queue payloads small and protects sensitive video-derived text. |
| 2026-05-16 | Add cancellation guards, but do not promise cancellation stops already-running provider calls. | Laravel batch cancellation can skip jobs before processing; it cannot interrupt external provider calls already underway. |
| 2026-05-16 | Use `predis/predis` for the local Redis client unless a later deployment standard requires PhpRedis. | The Laravel docs require either PhpRedis or Predis; Predis gives the Windows/local developer profile a predictable Composer-managed dependency. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-16 | Plan created from the Postgres + Redis parallelization review and `how_to_build.txt` guidance. | `docs/exec-plans/active/2026-05-16-postgres-redis-parallel-queue-runtime.md` |
| 2026-05-16 | Harness context established and baseline validation passed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` passed with contracts, backend tests, extension tests, TypeScript compile, and WXT build. |
| 2026-05-16 | Loaded backend guidance for this slice. | `app/backend/AGENTS.md`; Boost skills listed: `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, `laravel-security`, `subtitle-pipeline`; Context7 Laravel and Docker Compose docs checked. |
| 2026-05-16 | Implemented Postgres/Redis config, Predis dependency, Docker Compose services, parallel env example, configurable queue name, Redis multi-worker auto-start, SQLite one-worker cap, cancellation guards, dispatch-state cleanup, and sanitized timing logs. | `app/backend/config/database.php`; `app/backend/config/queue.php`; `app/backend/config/subtitles.php`; `compose.yaml`; `app/backend/.env.parallel.example`; subtitle jobs/services/tests. |
| 2026-05-16 | Updated durable runtime, observability, security, architecture, quality, and backend operation docs. | `ARCHITECTURE.md`; `docs/RELIABILITY.md`; `docs/OBSERVABILITY.md`; `docs/SECURITY.md`; `docs/QUALITY_SCORE.md`; `app/backend/README.md`. |
| 2026-05-16 | Focused validation passed after implementation. | `vendor/bin/pint --dirty --format agent`; `php artisan test --compact` passed 122 tests / 639 assertions; extension `npm test` and `npm run compile` passed; contracts `npm run check` passed; `composer validate --strict` passed; `docker compose config` passed. |
| 2026-05-16 | Live Postgres proof is blocked in this desktop PHP runtime. | `php -m` shows `redis` but not `pdo_pgsql`; backend README now documents `pdo_pgsql` as a parallel runtime prerequisite. |
| 2026-05-16 | Final PR verification passed after archiving the plan and recording follow-up debt. | `.\scripts\agent\verify-pr.ps1` passed. |

## Completion Notes

- What changed:
- Added Postgres and Redis Laravel config, Redis queue config, `predis/predis`, root `compose.yaml`, `.env.parallel.example`, configurable subtitle queue name, Redis multi-worker auto-start, SQLite one-worker cap preservation, worker lifecycle logs, batched-job cancellation middleware, explicit subtitle dispatch state, duplicate-create unique-constraint handling, and sanitized queue/stage/completion timing logs.
- Updated backend tests for Redis worker count, SQLite cap, tokenization/translation first-batch dispatch, cancelled cue-batch provider skips, and sanitized timing logs.
- Updated runtime docs, architecture, reliability, observability, security, quality score, and backend README.
- Validation results:
- Baseline `.\scripts\agent\check.ps1` passed before implementation.
- `vendor/bin/pint --dirty --format agent` fixed PHP formatting.
- `php artisan test --compact` passed: 122 tests / 639 assertions.
- Extension `npm test` and `npm run compile` passed.
- Contracts `npm run check` passed.
- `composer validate --strict` passed.
- `docker compose config` passed.
- Final `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1` passed; `verify-pr.ps1` was rerun after plan archival and debt tracking.
- `.\scripts\agent\doc-gardening.ps1` reported no findings.
- Simplicity/readability review:
- Kept one runtime direction: Postgres + Redis is the performance profile; SQLite is retained only for tests/dev-lite and the one-worker lock-avoidance cap.
- Avoided Horizon, Redis cache by default, new public APIs, new cancellation UX, or AI-agent merging.
- Kept queue payloads small by adding only scalar timing metadata.
- Residual risk:
- Live Postgres + Redis public-video proof could not run because this PHP runtime does not have `pdo_pgsql` enabled.
- Worker PID capture is platform-specific and should be checked during the first real `.env.parallel.example` run.
- Follow-up debt:
- `TD-009` records the missing live Postgres + Redis proof and the `pdo_pgsql` prerequisite.
