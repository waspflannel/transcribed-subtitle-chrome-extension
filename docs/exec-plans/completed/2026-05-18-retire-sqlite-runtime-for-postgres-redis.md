# Plan: Retire SQLite runtime for Postgres Redis

Status: completed
Owner: agent
Created: 2026-05-18
Last updated: 2026-05-18

## Goal

Retire SQLite as a backend runtime profile and make Postgres + Redis the normal local and deployment path. A developer copying the default environment should get `DB_CONNECTION=pgsql`, Redis-backed queues, and the `subtitle-ai` multi-worker path without discovering a separate `.env.parallel.example`.

Keep SQLite only where it is still deliberately useful for fast isolated PHPUnit tests. Any remaining SQLite references must be named as test-only or historical docs, not as a supported app runtime. Local cutover should be scripted so the app can fail loudly when Postgres PDO, Postgres, or Redis are missing instead of silently falling back to SQLite.

## Scope

- In scope:
- Change backend runtime defaults and examples from SQLite/database queues to Postgres/Redis.
- Add a local cutover script that starts/checks Postgres + Redis, selects a PHP binary with `pdo_pgsql`, writes the ignored local `.env`, clears config, and runs migrations.
- Add a runtime guard command or check so local/prod app startup diagnostics expose accidental SQLite/database-queue usage.
- Update docs, quality score, generated schema docs, and tech-debt status to reflect SQLite as test-only.
- Validate code paths still pass with PHPUnit's test-only SQLite profile.
- Out of scope:
- Removing historical completed phase references that accurately describe old decisions.
- Migrating production data from an existing SQLite file; this local ignored file is development state only.
- Requiring Redis cache by default; cache can remain database-backed on Postgres.
- Moving PHPUnit to Postgres in this slice unless the codebase already has a fast test database harness.

## Acceptance Criteria

- [x] `app/backend/.env.example` defaults to Postgres + Redis, not SQLite/database queue.
- [x] Runtime docs stop presenting SQLite as a current local smoke profile.
- [x] Laravel config fallbacks prefer `pgsql` and `redis` when env keys are absent.
- [x] A local script can prepare the Postgres + Redis profile and refuses to proceed without `pdo_pgsql`.
- [x] A diagnostics command or harness-visible check reports runtime database/queue drivers and flags SQLite outside testing.
- [x] SQLite remains only in PHPUnit configuration and historical/completed-plan text.
- [x] Local ignored `.env` is switched to the Postgres + Redis profile when the required runtime is available.
- [x] The existing local SQLite database file is not used by the app after cutover.
- [x] Validation passes or any infrastructure blocker is recorded with the exact command and reason.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`, `docs/generated/db-schema.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
- `docs/exec-plans/completed/2026-05-16-postgres-redis-parallel-queue-runtime.md`
- `docs/exec-plans/completed/2026-05-18-subtitle-runtime-trace-logging.md`
- Known risks:
- The initial PATH PHP lacked `pdo_pgsql`; local tooling now uses official PHP 8.4.21 with a normal `php.ini`.
- Docker Desktop is installed but was not running at plan creation, so container startup may require the desktop service.
- The worktree already contains the previous trace-logging implementation; preserve it and do not revert unrelated files.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: switch runtime defaults/examples/config fallbacks to Postgres + Redis.
- [x] Slice 2: add a local cutover/check script for Postgres PDO, Docker services, env writing, config clearing, and migrations.
- [x] Slice 3: add runtime diagnostics/guardrails and tests where practical.
- [x] Slice 4: update durable docs and debt status.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: backend tests plus full harness.
- Screenshots or video:
- Logs: cutover script output showing selected PHP binary and runtime profile, if Docker can start locally.
- Metrics or traces: `subtitles:runtime --json` or equivalent runtime config output after cutover.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-18 | Keep SQLite only for PHPUnit in this slice. | It avoids slowing every test run while still retiring SQLite from local/runtime app operation. |
| 2026-05-18 | Prefer official PHP 8.4 with a normal `php.ini` for local runtime scripts. | The app should not depend on a PHP environment manager; `pdo_pgsql` is enabled directly in the PHP install. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-18 | Plan created after confirming local runtime still uses SQLite/database queues. | `.env`; `php -m`; Laravel config output. |
| 2026-05-18 | Found the initial PATH PHP did not load `pdo_pgsql`, and Docker Desktop was installed but not running. | `php -m`; `docker compose ps` failed because Docker engine was unavailable. |
| 2026-05-18 | Switched runtime defaults to Postgres + Redis and added runtime readiness checks. | `config/database.php`; `config/queue.php`; `.env.example`; `subtitles:runtime-check`; `SubtitleRuntimeProfileTest`. |
| 2026-05-18 | Added cutover and Artisan wrapper automation for a normal PHP runtime with `pdo_pgsql`. | `.\scripts\runtime\use-postgres-redis.ps1 -SkipDocker -SkipMigrate` selected PHP with `pdo_pgsql` and wrote ignored `.env`; `.\scripts\runtime\artisan.ps1 subtitles:runtime-check --json` passed. |
| 2026-05-18 | Replaced local PHP tooling with official PHP 8.4.21 and a standalone Composer wrapper. | `where.exe php` resolved to the official winget PHP path; `php -m` showed `pdo_pgsql`; `composer --version` used PHP 8.4.21. |
| 2026-05-18 | Started local Postgres + Redis and migrated the runtime database. | `.\scripts\runtime\use-postgres-redis.ps1`; Docker Compose Postgres and Redis healthy; `php artisan migrate --force` completed all migrations on Postgres. |
| 2026-05-18 | Runtime diagnostics prove the app is no longer using SQLite locally. | `subtitles:runtime-check --json` returned `databaseDriver=pgsql`, `subtitleQueueDriver=redis`, `pdoPgsqlLoaded=true`; `subtitles:runtime --json` returned `connection=redis`, `queueDepth=0`. |
| 2026-05-18 | Removed stale ignored local SQLite database file after cutover. | `Test-Path app\backend\database\database.sqlite` returned `False`; focused runtime profile test still passed. |
| 2026-05-18 | Validation passed. | `vendor\bin\pint --dirty --format agent`; focused runtime tests passed 9 tests / 34 assertions; `php artisan test --compact` passed 134 tests / 679 assertions; `.\scripts\agent\check.ps1` passed; `docker compose config --quiet` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings. |

## Completion Notes

- What changed: Runtime defaults now prefer Postgres + Redis, the local ignored `.env` was cut over, Postgres/Redis Docker services are running and migrated, `subtitles:runtime-check` verifies runtime readiness, the stale ignored SQLite database file was removed, and docs/debt no longer present SQLite as a local app profile.
- Validation results: Pint, focused runtime tests, full backend tests, full harness check, Docker Compose config, runtime readiness JSON, runtime state JSON, and doc gardening all passed.
- Simplicity/readability review: Kept one app runtime direction and isolated the only remaining SQLite usage to PHPUnit's in-memory test profile instead of introducing another runtime branch.
- Residual risk: The real provider-backed public-video multi-worker timing proof still needs credentials and a representative video run, so `TD-009` remains open with a narrower scope.
- Follow-up debt: `TD-009` was updated to remove the `pdo_pgsql` blocker and track only the credentialed real-video multi-worker proof.
