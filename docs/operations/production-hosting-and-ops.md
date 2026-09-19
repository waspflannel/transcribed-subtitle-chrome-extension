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

## First Ubuntu / EC2 Boot

Artisan does not provision a server. `composer setup` only installs PHP packages and migrates a local app. `scripts/ops/deploy-managed-laravel.ps1` is the later release path; it expects PHP, Composer, Nginx, ffmpeg, and yt-dlp to already exist.

For a new Ubuntu host, upload the **repository root** (not only `app/backend`). Website translations and the language catalog live in `packages/` and Laravel reads them at runtime. The Chrome extension is not installed on EC2.

```bash
sudo ./scripts/ops/deploy-ubuntu.sh provision --user ubuntu --domain example.com
# copy app/backend/.env.example to app/backend/.env and set production values
sudo ./scripts/ops/deploy-ubuntu.sh deploy --user ubuntu
# after DNS resolves
sudo certbot --nginx -d example.com
curl --fail https://example.com/up
```

`provision` installs PHP 8.4, Composer, Nginx, Supervisor, ffmpeg, yt-dlp, and the scheduler cron. `deploy` runs `composer install --no-dev`, generates `APP_KEY` when missing, migrates, caches config, and writes Supervisor `tse-*` workers from `subtitles:runtime-check --json`.

Set `APP_URL` to the public HTTPS origin before deploy. The default worker pool is 31 processes (`SUBTITLE_GENERATION_PRIORITY_WORKERS=8`, `SUBTITLE_BATCH_PRIORITY_WORKERS=20`, plus 3 guarantee workers). Lower those values on a small instance before the first deploy. Keep Postgres and Redis on managed services; workers share local temp audio on this host.

After `https://<host>/v1` works, build the store ZIP on your computer with `scripts/ops/build-extension-release.ps1`. Do not put provider keys in that ZIP.

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

The deployment script now requires an explicit HTTPS `-HealthUrl` before it runs any install or migration. `-SkipHealthCheck` is an explicit operator exception; a missing URL no longer silently skips health verification. The `Project checks` GitHub workflow runs the full harness with disposable PostgreSQL/Redis, audits dependencies and checks fixture-origin packaging; it does not deploy.

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

## Public Website Launch Checks

- Set `APP_URL` to the final HTTPS website origin; canonicals, language alternates, the sitemap, and migration redirects use this configured origin. Verify `MARKETING_PRODUCT_NAME`, `SUPPORT_EMAIL`, and `CHROME_EXTENSION_URL` against the actual launch values.
- Keep private staging behind access controls. At public launch verify that the website is crawlable and that no deployment-wide `noindex` or robots block remains.
- Fetch `/robots.txt` and `/sitemap.xml`; the initial sitemap has 54 public page variants and excludes account routes and redirects. Inspect a homepage, pricing page, and guide in each locale. English is unprefixed; language home URLs such as `/es` omit a trailing slash.
- Verify the language switch preserves the page, old `?lang=` addresses redirect, and install/support links work. Auth and billing paths remain unprefixed.
- With owner access, verify the real domain in Search Console and submit `/sitemap.xml`. These account steps are not completed by merging code.
- Check the real install, signup, extension connection, and first successful generation journey. Use the basic event-counting procedure in `docs/OBSERVABILITY.md`; local tests do not establish live indexing or paid-provider availability.

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

The beta package version is `0.1.0`. The release script audits the complete dependency graph, runs tests/type checks unless explicitly skipped, builds the ZIP once, then verifies the manifest inside that exact ZIP. Only YouTube plus the chosen API origin are allowed; local hosts, malformed API paths, extra host permissions, version mismatches and bundled environment/private-key files fail. Recheck an existing artifact with `scripts/ops/check-extension-release.ps1 -ApiBaseUrl <actual-https-v1-url> -ArchivePath <zip-path>`. This does not establish that the host works or that no arbitrary secret could be embedded in JavaScript.

Do not distribute the ZIP from the fixture-origin CI packaging test. The real API domain, support address and hosting provider remain undecided by the owner's September 16 instruction. Once chosen, set `CHROME_EXTENSION_RELEASE_VERSION`, `CHROME_EXTENSION_API_HOST_PERMISSION` and the actual install/support values on the backend to match the distributed artifact.

The script requires a real extension version, audits shipped production dependencies, runs extension tests and TypeScript compile unless `-SkipTests` is provided, builds with WXT, verifies the manifest contains the configured production API host permission, rejects localhost backend permission, and creates the Chrome ZIP through `wxt zip`.

Chrome Web Store checklist:

- Version bumped in [app/extension/package.json](../../app/extension/package.json).
- Built manifest host permissions include YouTube and the exact production API origin.
- Provider and Stripe keys are not present in the ZIP.
- Privacy/support copy still matches provider usage and 30-day generated-track retention.
- Public-video release matrix and extension screenshots are captured for the release candidate.

## Staging Smoke

### Local Stripe sandbox

Local billing uses the **Transcribe-subtitle-app** sandbox (`acct_1UGAY62fCqu33Jv3`). Keep its secret key, price IDs and explicit portal configuration in the ignored `app/backend/.env`; Stripe object IDs from another sandbox cannot be reused. The current catalog is Base $9, Plus $19 and Pro $39 USD monthly. The portal permits price changes with prorations and cancellation at period end; quantity changes are disabled.

`scripts/runtime/start-local-dev.ps1` starts signed webhook forwarding when a local Stripe test key is configured. The Stripe CLI must be installed. To start forwarding separately, or after rotating the key, run:

```powershell
.\scripts\runtime\start-local-stripe.ps1 -Restart
```

The helper reads the key from the backend environment, updates `STRIPE_WEBHOOK_SECRET` without printing it, clears cached configuration, and runs the CLI in a hidden process. Keys are excluded from process arguments and listener logs. PID and sanitized logs live in ignored `app/backend/storage/logs/local-runtime/stripe-listener*` files. Forwarding stops when the computer restarts; run local startup again. The CLI uses the sandbox's default event API version; the webhook handler accepts legacy and Basil subscription periods. A deployed webhook endpoint must still use the pinned production version below.

Switching sandboxes also requires replacing local customer/subscription/checkout references. Preserve the old billing snapshot and usage ledger; use an actual subscription and signed events in the new sandbox to establish entitlement. Reloading the dashboard alone does not query Stripe or reconcile stale records.

### Hosted staging

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

Configure both `OPENAI_API_KEY` and `CEREBRAS_API_KEY` to enable both choices in the extension. `OPENAI_MODEL=gpt-5.6-luna` and `CEREBRAS_MODEL=gpt-oss-120b` are the defaults. The extension remembers Transcriber, Transcriber Spark or Auto for the next generation and sends `aiProvider`; the backend pins provider and exact model on each job. Analysis, cards and Quick Fix use that saved selection; full lyrics replacement always uses Transcriber. Changing the extension selector needs no backend restart. `AI_PROVIDER` remains the manual default for API requests without `aiProvider` and for evaluations. Missing explicitly selected provider credentials/model configuration is rejected before creating a job or reserving minutes. Completed tracks retain their existing content. Manual job and clicked-card reuse include provider and exact model, while transcript reuse stays shared.

For Auto, set the backend-only `TYPESAFE_API_KEY`; `TYPESAFE_MODEL=jev-latest` and `TYPESAFE_ROUTING_MIN_CONFIDENCE=0.7` are initial defaults. Definitions live in `config/typesafe.php`. Apply the Auto routing migration and rebuild/reload the extension. Refresh cached configuration and restart queue workers after deployment/config changes. Auto requires configured OpenAI as its fallback; missing TypeSafe or Spark credentials select Transcriber without blocking generation. Candidate model names, criteria and threshold are saved at submission. Auto cache identity includes this configuration, while force regeneration resets the decision. Trace event `model.auto_selected` records the choice, reason, confidence, classifier model, token counts and duration when available. `TYPESAFE_ROUTING_MICROUSD_PER_CALL=0` means no routing-cost estimate is configured; set an observed per-call estimate if cost totals should include routing. Rolling the migration back after Auto/manual generations coexist can violate the old uniqueness constraint; use a forward fix instead of deleting retained tracks.

Deploying code or changing backend keys/model configuration still requires the normal configuration refresh and worker restart. Drain active generations and corrections before deploying the recovered pipeline. Old serialized Tokenize/Romanize jobs must finish on the previous code. New jobs use output version v15; clicked-card cache uses v10 and includes provider/model identity. Transcript cache uses v5. Analysis v15 keeps automatic AI language selection per cue; detected language is provisional until all transcription chunks arrive. New compatible-job and transcript lookups use the new versions; saved tracks remain readable. The per-job selector additionally requires migration `2026_09_11_072602_add_ai_selection_to_subtitle_jobs.php`. Apply it and restart workers once when installing this change, then reload the extension build. Older jobs did not record provider history: the migration pins them to the configured deployment default once; this cannot recover their historical provider. New jobs always persist the explicit selection. A code rollback may retain these additive fields; restoring the old unique index fails if provider/model variants coexist, so do not delete user jobs to force rollback.

Redis queue blocking defaults to one second; change any older `REDIS_QUEUE_BLOCK_FOR=5` environment override to `1` to receive that improvement. Acquisition always reuses the just-validated yt-dlp metadata; the retired `SUBTITLE_YOUTUBE_REUSE_METADATA` setting is ignored. `SUBTITLE_AUDIO_DIRECT_CHUNKS` and `SUBTITLE_BALANCED_BATCHES` default to true on this branch. Existing `.env` overrides still win. Direct chunks are limited to chunked M4A sources; WebM and short audio keep whole-file preparation. Measure representative recordings before production rollout.

## Subtitle pipeline rollout (September 2026)

The subtitle changes require migration `2026_09_10_000000_add_transcription_options_to_subtitle_jobs.php` and the forward migration dropping vocabulary hints. Drain active generation work, deploy/migrate, and restart workers through the normal release procedure. The keyterm-removal job output version is analysis v16 and transcript cache version is v6. Old rows expire through existing retention.

`SUBTITLE_TRANSCRIPTION_INGESTION_MODE=upload` remains the default. The opt-in `youtube_url` route validates public/non-live metadata and duration with yt-dlp, then sends a canonical YouTube URL to Scribe through the existing single-chunk queue/merge path. It skips local download/FLAC preparation and does not automatically issue a second upload request if URL ingestion fails. Mode is pinned on each job. Compare representative videos before changing the default; fewer local steps do not establish a latency or recognition-quality gain.

Vocabulary hints were removed on 2026-09-14, including the input, provider keyterms, stored vocabulary, and surcharge estimate. Ingestion mode still distinguishes reuse. Cache model keys now use a short mode suffix that fits the original 64-character column. Customer minute accounting is unchanged. OpenAI reasoning effort remains low; no confidence-triggered transcription retries are enabled. Retain historical migrations and the compatibility index: old completed jobs can coexist without discarding user tracks.

## Progressive playback rollout

Drain active generations before switching to this branch and restarting workers. It changes queued chunk metadata and cue assembly. Rebuild/reload the extension with the backend change: previews are included in job status, and the old separate partial-track route is removed. No new database migration is needed beyond the existing migrations above.

Auto language detection describes the available speech prefix and can change as chunks arrive. Final detection uses all chunks. Automatic AI requests identify each cue and token from its text and honor the translation toggle even when detected language matches the target. Lyric replacement, Quick fix and clicked word cards follow the same rule. This adds no separate language-detection API call. The mixed-language fix needs a worker restart and no additional migration; regenerate older tracks to replace copied-source translations. Chunking retains overlap, but recognition quality and provider latency need representative live comparisons. Audio acquisition still completes before uploads start. Very long videos grow later chunks to honor the upload cap, so this does not guarantee uninterrupted playback or an instantaneous first response.

## Ponytail maintenance rollout (September 12)

This maintenance patch adds two forward migrations: `2026_09_12_065327_drop_unused_billing_dates_from_users_table.php` and `2026_09_12_065450_backfill_terminal_subtitle_job_expiry.php`. Use the normal drain/deploy/migrate/cache-refresh/worker-restart procedure, then reload the extension. Automated checks used test databases; runtime migrations and computer testing remain user-owned.

Failed/cancelled jobs receive 30-day diagnostic deadlines. The backfill can make old terminal rows eligible for the next daily prune; billing ledger entries remain. Completed/cancelled batch metadata and failed queue jobs are also pruned after 720 hours. The expiry backfill intentionally does not clear deadlines on rollback, since already-pruned rows cannot be recovered by reversing a migration.

The run-scoped preview uses existing artifact storage. Chunk retries now allow three transient exceptions, and retry-start timestamps protect them from the stalled-job watchdog. Finish old queued work before switching releases; serialized jobs can retain their old retry properties. The extension's saved-track recovery, polling, font and timing-binding changes require a rebuilt/reloaded extension. See the [implementation plan](../exec-plans/active/2026-09-12-implement-ponytail-application-review.md) for code-test evidence and deferred measurements.

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

The experiment branch targets a 15-second opening chunk for videos of at least 45 seconds, capped at half the video duration, with two seconds of overlap. The second chunk targets 20 seconds, capped at half the remaining duration; remaining chunks target 60 seconds and grow to keep at most eight uploads per job. Tune `SUBTITLE_TRANSCRIPTION_CHUNK_FIRST_SECONDS`, `SUBTITLE_TRANSCRIPTION_CHUNK_SECOND_SECONDS`, `SUBTITLE_TRANSCRIPTION_CHUNK_TARGET_SECONDS`, and `SUBTITLE_TRANSCRIPTION_CHUNK_MAX_CHUNKS` together. Set second seconds to zero to restore the first experiment; a two-upload cap also uses first-plus-remainder. Set first seconds to zero for an even-chunk comparison. Direct M4A-to-chunk preparation defaults on; WebM/Opus still normalizes the whole file first to preserve timing.

The experiment caps the opening analysis request at 10 seconds and two complete cues, with later requests capped at 30 seconds, alongside the existing character and cue-count limits. A single cue always stays intact. `SUBTITLE_ANALYSIS_FIRST_BATCH_SECONDS`, `SUBTITLE_ANALYSIS_FIRST_BATCH_MAX_CUES` and `SUBTITLE_ANALYSIS_BATCH_SECONDS` control these limits; zero disables a time limit. Balanced batches default on and retain these limits; balancing and content limits can produce shorter batches. Smaller requests may increase call count; compare first annotated cue latency, sustained ready coverage, total time and provider cost together.

## Reversible audio acquisition integration

`SUBTITLE_YOUTUBE_DIRECT_DOWNLOAD=false` and `SUBTITLE_YOUTUBE_METADATA_PREFETCH=false` preserve the existing acquisition path. Enable both for the experiment; enable `WXT_AUDIO_METADATA_PREFETCH=true` when building the extension and reload it. Opening the signed-in panel requests metadata preparation through an authenticated, write-scoped, rate-limited endpoint. Preparation runs on the existing base generation queue, returns no media URL, and makes no paid provider calls. Jobs older than 15 seconds are skipped. Generate never waits for prefetch completion.

Metadata is encrypted in the application cache, keyed by account/video, and expires after 60 seconds. Public/non-live/duration constraints are rechecked. Cache misses use ordinary metadata resolution. Supported M4A uses a complete FFmpeg stream copy with HTTPS host checks and TLS verification; other formats retain the existing downloader. Failed direct downloads fall back, and failed cached acquisition resolves metadata fresh once. No database migration is required.

Rollback: disable both backend flags, clear config and restart drained workers. Disable the extension flag, rebuild and reload to stop speculative traffic. The user accepted local playback and authorized merge on September 13. See the [completed integration plan](../exec-plans/completed/2026-09-12-integrate-reversible-audio-acquisition.md).

## Review remediation rollout requirements (2026-09-15)

- Run release tests in an isolated checkout/job before the deployment environment. `php artisan test`, direct PHPUnit and Composer tests now enforce disposable services and separate config/storage paths. Do not substitute manual `migrate:fresh` commands or point tests at an existing database. The harness does not clear deployment configuration.
- Build tooling now requires Node 22 or newer (WXT 0.21.4); use a supported patched Node release. Both full and production-only npm audits are clean at remediation time.
- Apply the forward `web_sessions_revoked_at` migration with the release. Password reset deletes database sessions and all extension tokens, while authenticated-session checks reject stale/passwordless pre-reset sessions for every supported driver. Reset users must log in again.
- Set the Stripe webhook endpoint to the tested `2025-03-31.basil` version before release, matching the pinned REST header. Legacy period/invoice fields remain readable for delayed events. Validate hosted checkout, plan changes, cancellation and expiry races in authorized Stripe test mode; local fixtures do not establish hosted behavior. Never replay grants blindly to reconcile existing accounts.
- Monitor unknown or completed prior checkout intents: the app intentionally blocks another payable session until the first outcome is reconciled. If a customer reports a timeout, retry the same plan/intent before changing plans.
- Launch-fix checkout recovery now looks up lost responses by customer and exact intent metadata, including pagination. If no session is found before the original expiry, the UI gives a UTC retry deadline (at most the original 31-minute window). A complete lookup after expiry permits a fresh intent or account deletion; completed payments still wait for signed webhook reconciliation. Never manually clear an uncertain intent just to remove the wait.
- Set actual provider-call limits in `config/subtitles.php` / environment for production capacity. The global request setting counts each provider attempt; account limits aggregate across devices/providers. Redis concurrency bookkeeping must be shared by HTTP and worker processes.
- Runtime queue JSON now exposes total/ready/delayed/reserved; update any external consumers that assumed a scalar depth. The displayed job list remains capped at 25; activeJobCount is the true total.
- R07's September 16 decision charges the full reserved minutes for user cancellation/deletion after paid generation admission, while early cancellation and ordinary failures restore their unsettled reservation. Pause new generation admission and drain queued/running work with the old workers before rolling out the policy. Deploy the paid-work marker migration together with the extension's Generate confirmation and updated website copy. Do not infer past provider calls from a null marker on a previously running job or charge historical work retroactively. Account-deletion retention is unchanged.

## Launch-fix rollout (September 16)

The launch-fix branch needs no additional migration or queue. Deploy the web code and drain/restart workers normally. Login now holds the user row lock through password validation and extension token creation, so password reset either rejects the old login or revokes its token. The analysis continuation publishes final tracks synchronously after commit instead of requeueing finalization behind audio work; old serialized finalizers remain supported until drained. Genuine stalled-work timeouts remain enabled.

Rebuild/reload extension `0.1.0` for monitor cleanup, including failed cancellation and recovered jobs. New billing recovery messages are included in all nine draft interface catalogs. Hosted email, Stripe, YouTube/browser lifecycle, provider cost and backup/rollback/alert evidence remain release gates in [release readiness](../product-specs/release-readiness.md#beta-launch-acceptance-record), not claims established by these code changes.
