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

The script starts Postgres and Redis through Docker Compose, writes the ignored local `.env` to the Postgres + Redis profile, clears Laravel config, verifies `pdo_pgsql`, and runs migrations.

Local generation auto-starts the configured `subtitle-ai` worker pool when `SUBTITLE_AUTO_START_WORKERS=true`, so normal browser testing does not require manually running workers. Run explicit workers only when you intentionally disable auto-start or want visible worker consoles:

```powershell
.\scripts\runtime\artisan.ps1 queue:work redis --queue=subtitle-ai,default --tries=1 --timeout=1200 --sleep=1
```

Keep the queue `retry_after` value above the worker timeout; the example profiles use 1260 seconds for 1200 second subtitle workers.

Production should set `SUBTITLE_AUTO_START_WORKERS=false` and run supervised `subtitle-ai` workers.

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
