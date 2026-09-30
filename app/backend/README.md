# Transcribe backend

Private local/self-hosted Laravel backend for the BYOK YouTube subtitle extension. No application accounts or billing.

See [setup](../../README.md) and [operations](../../docs/operations/production-hosting-and-ops.md).

Runtime: PHP 8.4, Postgres, Redis, FFmpeg and yt-dlp. Shared generation/analysis workers serve every job. Provider keys are encrypted backend settings or environment values and are never returned to the extension. Saved-track expiry is optional; temporary audio cleanup is mandatory.

From the repository root, `scripts/runtime/start-local-backend-workers.ps1` starts the database, queue, backend and workers; `start-local-dev.ps1` also starts extension development. Set keys in the extension Settings tab. Run `php artisan schedule:work` for local pruning/stall recovery.

Diagnostics: `subtitles:runtime-check`, `subtitles:runtime`, `subtitles:trace`, `subtitles:slow`, `subtitles:metrics` (all support `--json`).

`php artisan test --compact` uses isolated SQLite/array/sync configuration and fake providers. SQLite is test-only.
