# Personal BYOK hosting and operations

Each backend is one personal workspace on a local computer or private server. Allowed clients share its keys, history and tracks. There are no accounts, subscriptions, payments, minute credits or tier queues.

## Runtime and setup

### Windows desktop download

Extract the complete desktop ZIP and open `Transcribe.exe`. The panel has three actions: **Install all requirements**, **Start backend** (changes to **Stop backend**), and **Install browser extension**. Setup downloads and verifies Docker Desktop when absent, then loads the bundled Linux images. Docker's first-time prompts, Windows features and a requested reboot must be completed before startup can succeed. Windows x64 is the supported desktop target.

The package includes the backend, PHP, media tools and their runtime dependencies, PostgreSQL, Redis and the built `extension/` folder. No Composer/npm build is needed on the user's computer. The extension button opens the how-to-use guide; Chrome's manual installation uses the included `extension/` folder. Set provider keys in the extension Settings tab.

Desktop state lives in `%LOCALAPPDATA%\Transcribe`. `runtime.env` holds the generated encryption key (`APP_KEY`) and random database password. Saved provider keys and data cannot be decrypted without it. Keep a copy outside `%LOCALAPPDATA%\Transcribe`: the copy in `backups/` is lost with that folder. Repeated setup preserves the file and only adds settings that are missing. The `transcribe-desktop` Compose project keeps database, queue and backend storage in named volumes outside the download.

Start copies `runtime.env` to `backups/`, saves a database snapshot as `backups/before-start-<image tag>.dump`, applies migrations, and starts the backend, worker groups and scheduler. Each package version has its own snapshot, refreshed on each start of that version, so the last snapshot from an older version stays after an upgrade. Delete old snapshots by hand when they are no longer needed. If a migration fails, `backups/migration-pending.txt` stays and the next Start keeps the existing snapshot instead of dumping half-migrated data. The marker is removed after a successful migration. Readiness includes Supervisor processes, HTTP and database/Redis connectivity.

New installs run 3 generation workers and 6 batch workers (`SUBTITLE_GENERATION_WORKERS`, `SUBTITLE_BATCH_WORKERS` in `runtime.env`), instead of the server defaults of 9 and 22. Repeated setup adds these lines to older files and never changes values you set. HTTP runs six PHP server workers, so a slow word card request does not block polling or the healthcheck.

Stop keeps volumes and Docker Desktop running. When subtitles are being generated, the panel first asks before stopping (`runtime.ps1 -Action Stop -Force` skips the check). Stopping allows 30 seconds for shutdown and then ends remaining work, which is much shorter than generation job timeouts. Generations still running are interrupted and may need to be retried after restart. Closing the panel leaves the backend running.

Codex is not available in the desktop package. The image does not include the Codex CLI, and its sign-in callback (port 1455) is not published. Use the OpenAI or Cerebras API providers.

Claude Code is not available in the desktop package either; the image does not include the Claude CLI.

Only backend port `127.0.0.1:8001` is published. Database/Redis stay on the private bridge. Its gateway is the only non-loopback address added to the instance allowlist; Host and Origin checks remain enabled. The fixed `172.31.251.0/24` bridge can conflict with another Docker/VPN subnet; startup reports the Docker error instead of changing unrelated networks. The port is fixed because the packaged extension and guide are built for `http://127.0.0.1:8001`. A backend already using port 8001 must be stopped first.

Build on Windows with `scripts/ops/build-desktop-release.ps1`. Each default build uses a distinct image tag and output directory in ignored `dist/desktop/`. `-GuideUrl` may point to the public HTTPS guide; the default opens the packaged backend's local guide. The ZIP includes images for offline runtime loading, while installing Docker Desktop and using providers still need network access. It excludes internal docs, agent tooling, source tests and local credentials. Publishing, installer/code signing and clean Windows VM acceptance are separate release steps.

Run `scripts/desktop/tests.ps1` for setup/config/compiler checks. Add `-Smoke -PackageDirectory <extracted-package>` to use a disposable Compose project and verify migrations, health, encrypted settings, repeated setup and restart. The smoke test uses port 8001, so stop any other backend first. It removes only its own disposable volumes. The root harness includes basic desktop checks on Windows. It skips the Compose and launcher-compile checks when Docker or the .NET Framework compiler is not installed.

Use PHP 8.4, Composer, Postgres, Redis, FFmpeg and yt-dlp. Compose supplies Postgres and Redis with loopback-bound ports. The Windows launcher `scripts/runtime/start-local-backend-workers.ps1` sets the local runtime profile, migrates, and launches Laravel and workers. `start-local-dev.ps1` also starts WXT development.

Local `-Port` updates the backend URL and the WXT API base URL. `-DryRun` leaves configuration and processes untouched. `-SkipBackend` and `-SkipWorkers` preserve the skipped processes and their saved identities. Worker-only startup refuses profile/URL changes; restart both services to apply them. Unchanged environment values are not rewritten, avoiding Laravel's automatic HTTP reload. Restart cleanup requires an absolute Artisan path for this checkout or matching saved process creation time, executable and command line. Relative-command processes from older PID files without identity metadata must be stopped manually once.

On a private Linux server, install the same prerequisites, run Composer and migrations in `app/backend`, and supervise HTTP, workers and scheduler. Existing Ubuntu/managed deployment scripts remain available. Upload the repository root: contracts/localization in `packages/` are runtime dependencies.

Copy `.env.example` for a fresh installation, then run `php artisan config:clear` and `php artisan instance:ensure-key --no-interaction`. Composer setup and Ubuntu deployment use the same command: an existing key is preserved, and only an absent key is generated. Back it up with the database: encrypted credentials and correction state require it.

## Provider configuration

Extension Settings has ElevenLabs, OpenAI and Cerebras key fields in one card. Models are fixed to `scribe_v2`, `gpt-6-luna` and `gpt-oss-120b` respectively. The backend encrypts credentials and returns only configured flags and models. Blank fields preserve keys; Remove key clears them. Environment keys work until overridden; model environment variables and old saved model overrides are ignored. HTTP and workers refresh settings, so changing keys does not require restarting workers.

Generation requires ElevenLabs plus the selected analysis provider. Cards, Quick Fix and full lyrics replacement use the saved generation provider/model and recheck its credentials before actual calls.

### Codex with ChatGPT

Codex is an optional text provider alongside the existing API providers. Install the official Codex CLI 0.161.0 or newer on the backend host, available to both PHP HTTP processes and queue workers. Set `CODEX_BINARY` to its executable or absolute path if it is not on their PATH. On Windows, use the native `codex.exe` when PHP cannot resolve the npm launcher. Select **Sign in with ChatGPT** in the extension's Settings tab to open the browser authorization page. Complete sign-in, then return to the extension; its connection status updates automatically. The **Open ChatGPT sign-in** link reopens a pending attempt. Keep the generation workers running while signing in; each attempt expires after ten minutes.

Browser OAuth returns to a loopback callback hosted by Codex on the backend computer (port 1455). Run the backend on the same computer as the browser, or explicitly arrange access to that callback when hosting elsewhere; the ordinary backend HTTP port does not forward it. Finish or close another Codex browser sign-in before starting one here. Codex owns OAuth state, PKCE and token exchange; the extension receives only the authorization URL and connection status.

Use the current stable Codex CLI for the current model catalog. Version 0.161.0 is the supported minimum; subtitle requests disable execution environments with `environments: []` on both thread and turn creation. The removed `readOnly.access` field is not sent. Update the runtime selected by `CODEX_BINARY`, restart the backend and workers, then refresh Codex Settings after the one-minute account-summary cache expires. Model choices are discovered from that runtime and account, rather than maintained as a fixed application list.

In Watch, choose **Codex**, select a model discovered from the connected account, and optionally enable **Fast mode** when the model supports it. Codex requests use that account's applicable Codex usage limits or credits. Fast mode can consume credits faster. ElevenLabs still performs audio transcription using its API key. The API option continues to offer OpenAI and Cerebras with their existing configuration.

Every generation saves its provider, model and Codex Fast preference. History displays those choices. Later word cards and Quick Fix keep the saved choice. **Lyric correction** has a separate API/Codex selection, Codex model and Fast mode controls. It starts with the generation's selection and saves the chosen settings for the whole correction without changing the original generation. Disconnecting Codex prevents new Codex calls; reconnect or explicitly select an API provider for a new lyrics correction. A failed Codex call never falls back to API billing. Provider dollar estimates do not measure Codex credit consumption.

The backend starts Codex app-server over private standard input/output. It uses `app/backend/storage/app/private/codex`, separate from your personal Codex installation, and Codex manages token refresh. HTTP and all queue workers must share this private storage directory; mount it as persistent private storage if running in containers. Do not expose app-server on a public network or copy your personal Codex credentials into this application. Restrict access to its private credential directory like the database and application key. All allowed clients of this personal instance share the connected Codex account. This does not create application user accounts or make the backend suitable for public multi-user hosting.

Text requests use ephemeral threads, existing subtitle instructions and structured output schemas, restricted filesystem access and disabled command/network tools. The application does not persist Codex prompts, transcripts or raw errors. OAuth status responses contain only connection state, model metadata and the temporary authorization URL, never access or refresh tokens.

Apply pending database migrations and rebuild/reload the extension when upgrading. If Codex is not installed, API providers remain available and Codex Settings explains the missing runtime. See the official [app-server integration](https://learn.chatgpt.com/docs/app-server) and [authentication guide](https://learn.chatgpt.com/docs/auth).

### Claude Code subscription

Install Claude Code CLI 2.1.280 or newer on the backend host, available to PHP HTTP processes and queue workers. Set `CLAUDE_BINARY` if it is not on their PATH. On any computer, run `claude setup-token`, then paste the token into **Settings → Claude Code**. The backend stores the token encrypted and never returns it.

The model (`opus`, `sonnet`, `haiku`; default `sonnet`) is picked on the generation page, like the Codex model. The extension saves it and sends it with each job as `aiModel`, so every job keeps its own model. The backend pins these aliases to `claude-opus-5-5`, `claude-sonnet-5-5` and `claude-haiku-5-5` with `ANTHROPIC_DEFAULT_*_MODEL`, which also covers the CLI's own background Haiku calls. Fast mode is intentionally unsupported (the backend sets `CLAUDE_CODE_DISABLE_FAST_MODE=1`) because it bills paid usage credits on subscriptions.

Each AI request starts one `claude -p` process with:

- tools, MCP servers, settings files, skills and session saving turned off
- working directory and `CLAUDE_CONFIG_DIR` set to `app/backend/storage/app/private/claude-code`
- no inherited environment variables except platform basics
- `--effort low` (fixed, not a setting), and `CLAUDE_CODE_DISABLE_FAST_MODE=1` in the environment. It is a plain prompt-to-JSON call, like Codex. On Haiku 5.5 an 8-cue batch takes about 10s at `low`; `max` took about 70s and risks the 120s timeout.

No `ANTHROPIC_API_KEY` reaches the CLI, so requests never bill an API account. ElevenLabs still transcribes audio.

A rejected token shows as "provider not configured". Spaces in a pasted token (from terminal line wraps) are removed on save. Rate limits come from your Claude subscription. Each call adds a few seconds of CLI startup, so large jobs are slower than API providers.

This is for your own self-hosted instance with your own subscription. Do not offer it to other people as a way to use their Claude subscription.

## Private access

There is no user login. `INSTANCE_ALLOWED_NETWORKS` defaults to `127.0.0.1/32,::1/128`. For LAN/VPN access, set `APP_URL` to the exact backend origin and allow only trusted client CIDRs. Host and Origin checks reject unexpected browser origins and DNS-rebinding hosts. Keep firewall rules consistent.

By default any Chrome extension origin passes the Origin check, so another installed extension with access to the backend address can call the API. Set `INSTANCE_ALLOWED_EXTENSION_IDS` to a comma-separated list of extension IDs to admit only those. An unpacked extension's ID depends on its folder unless its manifest has a fixed `key`.

yt-dlp, its JavaScript runtime and ffmpeg run with an allowlisted environment (system paths and a private temp folder). `APP_KEY`, database passwords and provider keys are never passed to them.

Do not expose an unauthenticated public backend. A reverse proxy must enforce private client access itself; it must not present public traffic as trusted loopback clients. Use TLS remotely and HTTP only on loopback.

The extension address is selected at build time; permissions cover only YouTube and that origin. Set `WXT_BACKEND_API_BASE_URL=https://subtitles.internal/v1` before building for a server. Rebuild/reload when changing backend. Settings displays the address. Local default: `http://127.0.0.1:8001/v1`.

## Workers

| Setting | Default |
| --- | --- |
| `SUBTITLE_GENERATION_QUEUE` | `subtitle-generation` |
| `SUBTITLE_BATCH_QUEUE` | `subtitle-batch` |
| `SUBTITLE_GENERATION_WORKERS` | 9 |
| `SUBTITLE_BATCH_WORKERS` | 22 |
| `SUBTITLE_PROVIDER_GLOBAL_CONCURRENCY` | 30 calls per provider |
| `SUBTITLE_CODEX_CONCURRENCY` | 3 Codex sessions (they share one login) |
| `SUBTITLE_CLAUDE_CONCURRENCY` | 3 Claude Code processes |
| `SUBTITLE_AI_GLOBAL_RATE_LIMIT_PER_MINUTE` | 300 attempts per provider |
| `SUBTITLE_BATCH_QUEUE_CONNECTION` | `redis-batch` |
| `REDIS_QUEUE_RETRY_AFTER` / `SUBTITLE_WORKER_TIMEOUT_SECONDS` | 1380 / 1320 seconds (generation) |
| `REDIS_BATCH_QUEUE_RETRY_AFTER` / `SUBTITLE_BATCH_WORKER_TIMEOUT_SECONDS` | 360 / 330 seconds (batch) |

Tune processes to memory, CPU and provider quotas. HTTP and workers share Redis permits. Generation work (download, audio preparation, transcription chunks) runs on the `redis` connection. Batch work (analysis, transcript merge, track publication, lyrics correction) runs on `redis-batch` with a short retry window, so a killed worker's job returns before the stalled-job check fails the run. On each connection, retry-after must exceed its workers' timeout; `ops:production-check` verifies both. Eight-chunk transcription bounds, retries, overlap/run locks and stalled-worker detection remain technical safeguards.

`subtitles:runtime-check --json` supplies each worker group's connection, queues, count and timeout to `scripts/ops/render-supervisor-config.ps1` and the other launchers. Restart workers for code/config changes; provider settings refresh automatically.

## Storage and scheduler

Tracks stay saved until deletion by default. Retention days recompute existing track/completed-job deadlines from generation time. Enabling retention can make older tracks eligible for the next prune; disabling it clears deadlines. Temporary audio and correction state are always cleaned up.

Run `php artisan schedule:work` locally or a minute scheduler on the server. It prunes expired records and old queue diagnostics and checks stalled jobs. Trace events older than `SUBTITLE_TRACE_EVENT_RETENTION_DAYS` (default 30; 0 keeps them) are pruned daily, even for tracks that are kept. Transcript-cache expiry is separate (`SUBTITLE_TRANSCRIPT_CACHE_TTL_DAYS`, default 30 days) and does not delete saved tracks.

## Upgrade from the account-based version

The Auto-selection removal migration preserves resolved provider/model choices and saved tracks, removes obsolete routing metadata and encrypted TypeSafe credentials, and cancels unresolved Auto jobs for explicit retry. Stop workers, apply pending migrations, and rebuild/reload the extension when upgrading an existing BYOK installation. Old extension Auto preferences become OpenAI; source-language Auto detect is unchanged.

1. Back up Postgres and `APP_KEY`. Stop incoming work and stop/drain old tier workers before changing code/schema.
2. Install dependencies and migrate. Saved tracks are preserved and old expiry cleared. Active/queued generations are cancelled with a retry message and new run identity.
3. Replace old Supervisor queues with generation/batch groups. Do not restart old serialized tier jobs.
4. Rebuild/reload the extension and configure keys. History now belongs to the personal instance. Retry interrupted generations explicitly.

Legacy account/billing records remain inert; no runtime feature uses them. Code-edit/test workflows do not migrate the live database. Downgrading requires the backup because original ownership cannot be inferred for new instance-owned work.

## Verification and diagnostics

Run `scripts/agent/check.ps1` before release. Tests use isolated storage and fake providers. `ops:production-check` checks private runtime readiness without requiring provider credentials before setup.

Use `subtitles:runtime`, `subtitles:trace`, `subtitles:slow` and `subtitles:metrics` with `--json`. Monitor backlog, provider limits, failures, stalled stages, disk space, scheduler and backups. Logs exclude keys, prompts, lyrics and raw provider data. Cost estimates remain diagnostics.

Backup/restore scripts under `scripts/ops` remain available. Retain the matching application key separately. Release archives accept exact HTTPS or loopback HTTP origins and reject embedded secrets and broad permissions.

Retired paid-beta procedures: [historical operations](../history/paid-beta-hosting-and-ops.md).
