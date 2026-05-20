# Transcribed Subtitle Extension Backend

Laravel API for generating YouTube subtitle tracks, learning-token metadata, and subtitle job history for the browser extension.

## Useful Commands

```powershell
composer install
php artisan migrate
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan serve
```

## Runtime Profiles

Default `.env.example` uses the Postgres + Redis runtime profile. SQLite is no longer a supported app runtime; it is retained only by PHPUnit's isolated test configuration.

Prerequisites: Docker, official PHP 8.4 from winget or another normal PHP build with `pdo_pgsql`, and either `phpredis` or the Composer-managed `predis/predis` client. The helper scripts use PATH PHP or the official winget PHP path; no PHP environment manager is required.

```powershell
.\scripts\runtime\use-postgres-redis.ps1
.\scripts\runtime\artisan.ps1 serve
```

The script starts Postgres and Redis through Docker Compose, writes the ignored local `.env` to the Postgres + Redis profile, clears Laravel config, verifies `pdo_pgsql`, and runs migrations. Start the API server:

```powershell
.\scripts\runtime\artisan.ps1 serve
```

Local generate requests auto-start subtitle queue workers by default through `SUBTITLE_AUTO_START_WORKERS=true`. The spawned workers listen to the tier-priority queue list, currently `subtitle-ai-ultimate,subtitle-ai-pro,subtitle-ai-plus,subtitle-ai`, run with `SUBTITLE_AUTO_WORKER_TRIES=0` by default, and exit after `SUBTITLE_AUTO_WORKER_MAX_TIME_SECONDS`. Subtitle queue jobs also allow unlimited release attempts with `maxExceptions=1`, so deliberate concurrency-delay releases do not fail as exhausted attempts while real exceptions still fail the job. For local maximum parallelism testing, set `SUBTITLE_DEFAULT_GENERATION_TIER=ultimate`, `SUBTITLE_ULTIMATE_PER_INSTALL_CONCURRENCY=20`, and `SUBTITLE_AUTO_WORKER_COUNT=20`.

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
