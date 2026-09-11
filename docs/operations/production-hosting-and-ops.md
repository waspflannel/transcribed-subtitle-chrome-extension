# Production Hosting And Ops Runbook

Created: 2026-06-02

## Goal

Run the paid beta on a managed Laravel VPS-style host with managed Postgres and Redis. Keep the first production posture boring: one Laravel web/API app, Supervisor-managed queue workers, Laravel scheduler, managed database/cache services, external log/alert collection, backups, and repeatable releases.

This runbook is provider-neutral. Fill in the hosting provider, region, managed database/Redis vendor, domains, and alerting provider before a real staging or production deployment.

## Environment Shape

- Web/API host: managed VPS or Laravel-oriented host running PHP 8.4, Composer, Nginx or equivalent, HTTPS, and Supervisor.
- Database: managed Postgres with private networking or IP restrictions where the provider supports it.
- Cache/queue: managed Redis with separate logical DBs or equivalent isolation for default/cache/queue/concurrency use.
- Runtime: `APP_DEBUG=false`, `APP_URL=https://...`, `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `SUBTITLE_QUEUE_CONNECTION=redis`, configured `yt-dlp`, configured `ffmpeg`, and bounded database/Redis connection timeouts.
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
5. Configure production workers under Supervisor.
6. Install and configure `yt-dlp` and `ffmpeg`; set `YOUTUBE_AUDIO_BINARY` and `FFMPEG_BINARY` when the binaries are not available on the host `PATH`.
7. Configure provider and audio-preparation environment variables:

```env
ELEVENLABS_API_KEY=<server-side ElevenLabs API key>
ELEVENLABS_URL=https://api.elevenlabs.io/v1
ELEVENLABS_TRANSCRIPTION_MODEL=scribe_v2
ELEVENLABS_TRANSCRIPTION_TIMEOUT_SECONDS=600
FFMPEG_BINARY=ffmpeg
SUBTITLE_AUDIO_PREP_FFMPEG_TIMEOUT_SECONDS=600
```

Keep the ElevenLabs key only in backend host/provider secret storage. The extension build must never contain provider keys.
8. Run readiness checks:

```powershell
.\scripts\ops\check-production-readiness.ps1 -Target staging
.\scripts\ops\check-production-readiness.ps1 -Target production
```

9. Render Supervisor worker config from the checked-in queue group configuration:

```powershell
.\scripts\ops\render-supervisor-config.ps1 `
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
.\scripts\ops\deploy-managed-laravel.ps1 `
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
.\scripts\ops\backup-postgres.ps1 `
  -DatabaseUrl "postgres://user:password@host:5432/database" `
  -OutputDirectory ".\storage\ops\backups"
```

Test restores against a disposable restore-test database only:

```powershell
.\scripts\ops\restore-postgres-backup.ps1 `
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
.\scripts\ops\build-extension-release.ps1 -ApiBaseUrl "https://api.example.com/v1"
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

## Text AI provider selection

Configure both `OPENAI_API_KEY` and `CEREBRAS_API_KEY` to enable both choices in the extension. `OPENAI_MODEL=gpt-5.6-luna` and `CEREBRAS_MODEL=gpt-oss-120b` are the defaults. The extension remembers Luna or Cerebras for the next generation and sends `aiProvider`; the backend pins provider and exact model on each job. Analysis, cards, Quick Fix and lyrics alignment use that saved selection. Changing the extension selector needs no backend restart. `AI_PROVIDER` remains the default for API requests without `aiProvider` and for evaluations. Missing provider credentials/model configuration is rejected before creating a job or reserving minutes. Completed tracks retain their existing content. Job and clicked-card reuse include provider and exact model, while transcript reuse stays shared.

Deploying code or changing backend keys/model configuration still requires the normal configuration refresh and worker restart. Drain active generations and corrections before deploying the recovered pipeline. Old serialized Tokenize/Romanize jobs must finish on the previous code. New jobs use output version v15; clicked-card cache uses v10 and includes provider/model identity. Transcript cache uses v5. Analysis v15 keeps automatic AI language selection per cue; detected language is provisional until all transcription chunks arrive. New compatible-job and transcript lookups use the new versions; saved tracks remain readable. The per-job selector additionally requires migration `2026_09_11_072602_add_ai_selection_to_subtitle_jobs.php`. Apply it and restart workers once when installing this change, then reload the extension build. Older jobs did not record provider history: the migration pins them to the configured deployment default once; this cannot recover their historical provider. New jobs always persist the explicit selection. A code rollback may retain these additive fields; restoring the old unique index fails if provider/model variants coexist, so do not delete user jobs to force rollback.

Redis queue blocking defaults to one second; change any older `REDIS_QUEUE_BLOCK_FOR=5` environment override to `1` to receive that improvement. Acquisition always reuses the just-validated yt-dlp metadata; the retired `SUBTITLE_YOUTUBE_REUSE_METADATA` setting is ignored. `SUBTITLE_AUDIO_DIRECT_CHUNKS` and `SUBTITLE_BALANCED_BATCHES` default to true on this branch. Existing `.env` overrides still win. Direct chunks are limited to chunked M4A sources; WebM and short audio keep whole-file preparation. Measure representative recordings before production rollout.

## Subtitle pipeline rollout (September 2026)

The subtitle changes require migration `2026_09_10_000000_add_transcription_options_to_subtitle_jobs.php`. Drain active generation work, deploy/migrate, and restart workers through the normal release procedure; existing worker payloads must not mix the old separate romanization stage with the combined stage. The job output version is analysis v15 and transcript cache version is v5. Old rows expire through existing retention.

`SUBTITLE_TRANSCRIPTION_INGESTION_MODE=upload` remains the default. The opt-in `youtube_url` route validates public/non-live metadata and duration with yt-dlp, then sends a canonical YouTube URL to Scribe through the existing single-chunk queue/merge path. It skips local download/FLAC preparation and does not automatically issue a second upload request if URL ingestion fails. Mode is pinned on each job. Compare representative videos before changing the default; fewer local steps do not establish a latency or recognition-quality gain.

Vocabulary hints add the [documented Scribe keyterm surcharge](https://elevenlabs.io/docs/api-reference/speech-to-text/convert); provider cost estimates include 20% when hints are present. Customer minute accounting is unchanged. OpenAI reasoning effort is low following the user's requested configuration change; model IDs and Fast mode are unchanged, and no confidence-triggered transcription retries are enabled. Refresh cached configuration and restart workers through the normal release procedure to apply the new effort. A code rollback can leave the additive columns in place; dropping the migration's unique-key extension can fail if multiple hint/mode variants exist, so do not discard user jobs to force it.

## Progressive playback rollout

Drain active generations before switching to this branch and restarting workers. It changes queued chunk metadata and cue assembly. Rebuild/reload the extension with the backend change: previews are included in job status, and the old separate partial-track route is removed. No new database migration is needed beyond the existing migrations above.

Auto language detection describes the available speech prefix and can change as chunks arrive. Final detection uses all chunks. Automatic AI requests identify each cue and token from its text and honor the translation toggle even when detected language matches the target. Lyric replacement, Quick fix and clicked word cards follow the same rule. This adds no separate language-detection API call. The mixed-language fix needs a worker restart and no additional migration; regenerate older tracks to replace copied-source translations. Chunking retains overlap, but recognition quality and provider latency need representative live comparisons. Audio acquisition still completes before uploads start. Very long videos grow later chunks to honor the upload cap, so this does not guarantee uninterrupted playback or an instantaneous first response.

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

Worker launchers default to `--sleep=0`. Redis keeps its one-second blocking pop, which waits for ready work without an additional idle sleep and revisits delayed jobs promptly.

Upload transcription targets a 40-second opening chunk for videos of at least 45 seconds, capped at half the video duration, with two seconds of overlap. This leaves room for a 30-second opening subtitle batch and unfinished boundary cues. Later chunks target 60 seconds and grow to keep at most eight uploads per job. Tune `SUBTITLE_TRANSCRIPTION_CHUNK_FIRST_SECONDS`, `SUBTITLE_TRANSCRIPTION_CHUNK_TARGET_SECONDS`, and `SUBTITLE_TRANSCRIPTION_CHUNK_MAX_CHUNKS` together. Set first seconds to zero for an even-chunk comparison. Direct M4A-to-chunk preparation defaults on; WebM/Opus still normalizes the whole file first to preserve timing.

Analysis batches cap both the opening request and later requests at 30 seconds of cues, alongside the existing character and cue-count limits. A single cue always stays intact. `SUBTITLE_ANALYSIS_FIRST_BATCH_SECONDS` and `SUBTITLE_ANALYSIS_BATCH_SECONDS` control these limits; zero disables that limit. Balanced batches default on and retain these time limits; balancing and content limits can produce shorter batches. Smaller requests may increase call count; compare first annotated cue latency, total time and provider cost together.
