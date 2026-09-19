# Transcribe Backend

Laravel API for generating YouTube subtitle tracks, learning-token metadata, and subtitle job history for the browser extension.

## Useful Commands

```powershell
composer install
php artisan migrate
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan serve --host=127.0.0.1 --port=8001
```

## Runtime Profiles

Default `.env.example` uses the Postgres + Redis runtime profile. SQLite is no longer a supported app runtime; it is retained only by PHPUnit's isolated test configuration.

Prerequisites: Docker, official PHP 8.4 from winget or another normal PHP build with `pdo_pgsql`, and either `phpredis` or the Composer-managed `predis/predis` client. The helper scripts use PATH PHP or the official winget PHP path; no PHP environment manager is required.

From the repository root, one command starts Postgres, Redis, Laravel, subtitle workers, and the WXT extension dev server:

```powershell
.\scripts\runtime\start-local-dev.ps1
```

The WXT dev server stays in the terminal for live reload. Press `Ctrl+C` to stop it; the containers, backend, and workers remain available. Rerun the command to reuse the containers and replace the local backend and worker processes.

```powershell
.\scripts\runtime\use-postgres-redis.ps1
.\scripts\runtime\artisan.ps1 serve --host=127.0.0.1 --port=8001
```

The script starts Postgres and Redis through Docker Compose, writes the ignored local `.env` to the Postgres + Redis profile, clears Laravel config, verifies `pdo_pgsql`, and runs migrations. Start the API server:

```powershell
.\scripts\runtime\artisan.ps1 serve --host=127.0.0.1 --port=8001
```

For local subtitle generation, prefer the runtime launcher from the repository root. It starts and waits for Postgres and Redis, selects the local runtime profile, runs migrations, replaces any existing local backend server and subtitle queue workers for this project, then starts a fresh backend plus the configured worker groups:

```powershell
.\scripts\runtime\start-local-backend-workers.ps1
```

The local launcher and development extension both use `http://127.0.0.1:8001` by default.

Script-managed workers run without a max-time limit by default; rerun the launcher when you want to replace the local backend and worker pool.

Use `.\scripts\runtime\start-local-backend-workers.ps1` from the repository root to start the local backend and configured worker groups. Production workers are owned by Supervisor. AI batch jobs allow unlimited release attempts with `maxExceptions=1`, so deliberate concurrency-delay releases do not fail as exhausted attempts while real exceptions still fail the job.

Generation admission uses authenticated `user_id` before dispatch. AI batch concurrency limiter bookkeeping uses the dedicated Redis-backed cache store configured by `SUBTITLE_CONCURRENCY_CACHE_STORE=subtitle_concurrency`, with Redis connection and lock connection both defaulting to `cache`. The global `CACHE_STORE` can remain `database`; `subtitles:runtime-check --strict` verifies that Redis queues are not paired with database-backed limiter locks.

Keep the queue `retry_after` value above the worker timeout; the example profiles use 1260 seconds for 1200 second subtitle workers. Auto-start defaults off when `APP_ENV=production` unless explicitly enabled; production can run supervised workers instead.

## Runtime Diagnostics

Subtitle generation writes sanitized trace rows and structured logs. Inspect local runs with:

```powershell
.\scripts\runtime\artisan.ps1 subtitles:runtime-check
.\scripts\runtime\artisan.ps1 subtitles:runtime
.\scripts\runtime\artisan.ps1 subtitles:trace <public-job-id>
.\scripts\runtime\artisan.ps1 subtitles:slow
```

Each command supports `--json` for agent-readable output. Trace rows intentionally exclude transcripts, cue text, token text, prompts, translations, romanization, raw provider payloads, raw audio paths, provider secrets, and install IDs.

The backend is API-only. Extension UI work lives in `../extension`.
