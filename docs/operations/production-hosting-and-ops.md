# Production Hosting And Ops Runbook

Created: 2026-06-02

## Goal

Run the paid beta on a managed Laravel VPS-style host with managed Postgres and Redis. Keep the first production posture boring: one Laravel web/API app, Supervisor-managed queue workers, Laravel scheduler, managed database/cache services, external log/alert collection, backups, and repeatable releases.

This runbook is provider-neutral. Fill in the hosting provider, region, managed database/Redis vendor, domains, and alerting provider before a real staging or production deployment.

## Environment Shape

- Web/API host: managed VPS or Laravel-oriented host running PHP 8.4, Composer, Nginx or equivalent, HTTPS, and Supervisor.
- Database: managed Postgres with private networking or IP restrictions where the provider supports it.
- Cache/queue: managed Redis with separate logical DBs or equivalent isolation for default/cache/queue/concurrency use.
- Runtime: `APP_DEBUG=false`, `APP_URL=https://...`, `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `SUBTITLE_QUEUE_CONNECTION=redis`, `SUBTITLE_AUTO_START_WORKERS=false`, configured `yt-dlp`, configured `ffmpeg`, and bounded database/Redis connection timeouts. ElevenLabs Audio Isolation stays disabled until TD-014 evidence supports enabling it.
- Secrets: keep `APP_KEY`, provider keys, Stripe keys, database credentials, and Redis credentials in host/provider environment settings only. Do not put them in extension builds.
- Extension: build with `WXT_BACKEND_API_BASE_URL=https://<api-host>/v1`; the built manifest should contain only the production API origin plus YouTube host permission.

## Required Decisions

Record these in the active phase plan before the first real staging deploy:

- Hosting provider and region.
- Managed Postgres and Redis vendor.
- Logging and alerting provider.
- Staging and production domains.
- Deploy mode: manual approved deploy, push-to-main deploy, or tagged release.
- Backup and generated-track retention windows.

## First Provisioning Checklist

1. Create staging and production Laravel host projects.
2. Attach managed Postgres and Redis with least-privilege credentials.
3. Configure HTTPS and set `APP_URL` to the exact public API/web origin.
4. Configure Laravel environment variables from [app/backend/.env.example](../../app/backend/.env.example).
5. Set `SUBTITLE_AUTO_START_WORKERS=false`; production workers are owned by Supervisor.
6. Install and configure `yt-dlp` and `ffmpeg`; set `YOUTUBE_AUDIO_BINARY` and `FFMPEG_BINARY` when the binaries are not available on the host `PATH`.
7. Configure provider and audio-preparation environment variables:

```env
ELEVENLABS_API_KEY=<server-side ElevenLabs API key>
ELEVENLABS_URL=https://api.elevenlabs.io/v1
ELEVENLABS_TRANSCRIPTION_MODEL=scribe_v2
ELEVENLABS_TRANSCRIPTION_TIMEOUT_SECONDS=600
ELEVENLABS_AUDIO_ISOLATION_ENABLED=false
ELEVENLABS_AUDIO_ISOLATION_TIMEOUT_SECONDS=600
ELEVENLABS_AUDIO_ISOLATION_FAIL_OPEN=true
FFMPEG_BINARY=ffmpeg
SUBTITLE_AUDIO_PREP_FFMPEG_TIMEOUT_SECONDS=600
```

Keep the ElevenLabs key only in backend host/provider secret storage. The extension build must never contain provider keys.

Voice isolation stays disabled until the clean/noisy/music-heavy A/B comparison (TD-014) proves it improves transcript quality for its added provider cost and latency; when enabling it, keep `ELEVENLABS_AUDIO_ISOLATION_FAIL_OPEN=true`.
8. Run readiness checks:

```powershell
.\scripts\runtime\check-production-readiness.ps1 -Target staging
.\scripts\runtime\check-production-readiness.ps1 -Target production
```

9. Render Supervisor worker config from the checked-in queue group configuration:

```powershell
.\scripts\runtime\render-supervisor-config.ps1 `
  -ApplicationPath "/var/www/transcribed-subtitle-extension/app/backend/current" `
  -WorkerUser "forge" `
  -OutputPath ".\storage\ops\transcribed-subtitle-extension-workers.conf"
```

10. Copy the rendered config to `/etc/supervisor/conf.d/transcribed-subtitle-extension-workers.conf`, then run:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart 'tse-*:*'
sudo supervisorctl status 'tse-*:*'
```

11. Install the Laravel scheduler cron on the host:

```cron
* * * * * cd /var/www/transcribed-subtitle-extension/app/backend/current && php artisan schedule:run >> /dev/null 2>&1
```

12. Confirm pruning is scheduled:

```bash
php artisan schedule:list
php artisan subtitles:prune-expired --no-ansi
```

## Deploy Flow

The deploy script encodes the release order confirmed by Laravel 13 deployment docs: run the repository checks, audit the locked Composer runtime and shared contracts package, clear stale config, check production posture, check the runtime profile, migrate with `--force`, optimize caches, restart queue workers gracefully, and smoke `/up`.

```powershell
.\scripts\runtime\deploy-managed-laravel.ps1 `
  -Target staging `
  -HealthUrl "https://staging-api.example.com/up"
```

Use `-SkipRepositoryChecks` only when CI has already run the full harness for the exact commit being deployed.

The deploy process must complete these checks before a paid-beta production release:

- `.\scripts\agent\check.ps1`
- `composer audit --locked --no-dev --no-interaction`
- `npm audit --audit-level=high` in `packages/contracts`
- `php artisan ops:production-check --target=<staging|production>`
- `php artisan subtitles:runtime-check --strict`
- `php artisan migrate --force`
- `php artisan optimize`
- `php artisan queue:restart`
- `GET /up`

## Rollback Flow

Use the host's release directory rollback or Git revision rollback, then run:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:clear
php artisan migrate:status
php artisan optimize
php artisan queue:restart
curl --fail https://<api-host>/up
sudo supervisorctl status 'tse-*:*'
```

If a migration has already mutated production data, do not run an automatic destructive rollback. Restore the previous release, leave the database forward-compatible where possible, and apply a forward-fix migration after review.

The first staging rollback must be tested and recorded in the active phase plan before production beta traffic.

## Backups And Restore Tests

Create backups with `pg_dump` custom format:

```powershell
.\scripts\runtime\backup-postgres.ps1 `
  -DatabaseUrl "postgres://user:password@host:5432/database" `
  -OutputDirectory ".\storage\ops\backups"
```

Test restores against a disposable restore-test database only:

```powershell
.\scripts\runtime\restore-postgres-backup.ps1 `
  -BackupPath ".\storage\ops\backups\transcribed-subtitle-extension-YYYYMMDD-HHMMSS.dump" `
  -DatabaseUrl "postgres://user:password@host:5432/restore_test_database" `
  -ConfirmRestore
```

Backup proof is not complete until a restore succeeds and the restored database can run:

```bash
php artisan migrate:status
php artisan subtitles:runtime --json
```

## Monitoring And Alerts

Dashboards should use the existing sanitized commands and structured logs:

- Runtime state: `php artisan subtitles:runtime --json`
- Slow stages and queue waits: `php artisan subtitles:slow --json`
- Generation timing and cost: `php artisan subtitles:metrics --json`
- Billing usage/cost: `php artisan billing:usage-report --json`
- Per-job trace: `php artisan subtitles:trace <public-job-id> --json`
- Failed Laravel jobs: `php artisan queue:failed`
- Scheduler visibility: `php artisan schedule:list`
- Worker process heartbeat: `sudo supervisorctl status 'tse-*:*'`

Create alerts for:

- `/up` failure.
- Any `tse-*` Supervisor process not running.
- Queue depth increasing for more than one check interval.
- Slow queue wait above `SUBTITLE_TRACE_SLOW_QUEUE_WAIT_MS`.
- Slow stage above `SUBTITLE_TRACE_SLOW_STAGE_MS`.
- New `queue.failed`, `batch.failed`, or `job.failed` trace events.
- Provider rate-limit or timeout errors.
- Disk space below the host's safe threshold.
- Backup missing, failed, or restore-test evidence older than the chosen retention window.
- Scheduler/pruning evidence missing from the expected daily window.

Logs and traces must remain sanitized: no provider secrets, bearer tokens, raw audio paths, prompts, transcripts, translations, romanization, token text, raw provider payloads, raw install IDs, or account emails.

## Extension Production Release

Build Chrome release artifacts with an HTTPS production API base URL:

```powershell
.\scripts\runtime\build-extension-release.ps1 -ApiBaseUrl "https://api.example.com/v1"
```

The script requires a real extension version, audits shipped production dependencies, runs extension tests and TypeScript compile unless `-SkipTests` is provided, builds with WXT, verifies the manifest contains the configured production API host permission, rejects localhost backend permission, and creates the Chrome ZIP through `wxt zip`.

Chrome Web Store checklist:

- Version bumped in [app/extension/package.json](../../app/extension/package.json).
- Built manifest host permissions include YouTube and the exact production API origin.
- Provider and Stripe keys are not present in the ZIP.
- Privacy/support copy still matches provider usage and 30-day generated-track retention.
- Public-video release matrix and extension screenshots are captured for the release candidate.

## Staging Smoke

Run this before production:

- `GET /up`.
- Login through the web app.
- Stripe checkout in test mode.
- Extension login against staging API.
- Create a subtitle job with a public video.
- Confirm queue worker processing through `subtitles:runtime --json`.
- Inspect the job with `subtitles:trace <public-job-id> --json`.
- Confirm scheduler visibility with `schedule:list`.
- Run `subtitles:prune-expired --no-ansi`.
- Build extension ZIP with the staging API URL and verify the manifest host permission.

## Security Checklist

- `APP_DEBUG=false`.
- `APP_URL` is HTTPS and matches the public host.
- Provider and Stripe keys live only in backend environment configuration.
- Extension ZIP contains no provider keys, Stripe keys, backend passwords, or localhost backend permission.
- CORS/host permissions are exact enough for the Chrome review path.
- Stripe webhook signing secret is configured and test-mode webhook replay proof exists.
- Logs/traces exclude generated content and credentials.
- Database and Redis are not publicly reachable unless provider controls enforce source restrictions.
- Backups are encrypted or protected by the provider's managed storage controls.
