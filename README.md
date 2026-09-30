# Transcribe

Generate synchronized subtitles and language-learning cards for public YouTube videos and Shorts. Run the backend on your computer or a private server and bring your own provider keys. There are no accounts, subscriptions, minute allowances or paid speed tiers.

## Requirements

- PHP 8.4 with Postgres support, Composer, Node.js/npm, Docker Compose, FFmpeg and yt-dlp.
- ElevenLabs for timestamped transcription and OpenAI or Cerebras for subtitle analysis.
- Chrome with the unpacked extension. The backend uses Postgres and Redis; SQLite is only used in isolated tests.

## Local setup

Install dependencies from the repository root:

```powershell
composer install --working-dir=app/backend
npm ci --prefix packages/contracts
npm ci --prefix app/extension
Copy-Item app/backend/.env.example app/backend/.env
```

Keep an existing `.env` when upgrading. From `app/backend`, run `php artisan key:generate` only for a new installation. Keep that application key: it encrypts saved provider credentials and correction state.

Start the local database, queue, backend and workers:

```powershell
.\scripts\runtime\start-local-backend-workers.ps1
```

Build the extension with `npm run build --prefix app/extension`. In Chrome, open `chrome://extensions`, enable Developer mode, choose **Load unpacked**, and select `app/extension/.output/chrome-mv3`.

Open the extension's **Settings** tab. Save your ElevenLabs key and the key for your chosen analysis provider, then open a YouTube video and select **Generate**. Saved keys are encrypted on the backend; the extension only receives configured status and model names. You can also supply credentials through the backend environment.

The default backend is `http://127.0.0.1:8001/v1`. For live extension development, use `scripts/runtime/start-local-dev.ps1` instead.

## Processing and storage

Every generation uses the same parallel pipeline. The default shared pools contain 9 audio/generation workers and 22 analysis workers. Adjust `SUBTITLE_GENERATION_WORKERS`, `SUBTITLE_BATCH_WORKERS` and provider concurrency/rate settings to your machine and provider quotas. Technical retries, input validation and cleanup remain enabled.

Videos over 30 minutes show a processing-time/cost warning. There is no hard video-duration cap. Your providers charge your API accounts directly.

Tracks stay saved until you delete them by default. Optional retention days apply to existing and future tracks from their generation date; disabling retention clears their deadlines. Temporary audio is always removed. Run the Laravel scheduler (`php artisan schedule:work` from `app/backend` in a separate terminal) for stalled-job recovery, temporary-cache cleanup and optional retention.

Quick Fix, full lyrics replacement and word cards use the generation's saved provider/model. Known Lyrics and display preferences remain browser-local.

## Private self-hosting and upgrades

See [the operations guide](docs/operations/production-hosting-and-ops.md). Each backend is one personal workspace: every allowed client shares its keys and history. Access defaults to loopback; configure only trusted LAN/VPN networks. This is not a public multi-user service.

Before upgrading from the account-based version, back up the database and application key and stop/drain the old workers. The migration preserves saved tracks, disables their old expiry deadlines, and marks interrupted tier-queue jobs cancelled so they can be retried. Historical account/billing records remain inert; the application no longer uses them. Rebuild the extension and restart the workers on the new shared queues.

## Validation

```powershell
.\scripts\agent\check.ps1
```

Tests use isolated storage, in-memory SQLite and fake provider calls. No real provider credentials are needed for the suite.
