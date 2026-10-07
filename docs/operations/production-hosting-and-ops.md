# Personal BYOK hosting and operations

Each backend is one personal workspace on a local computer or private server. Allowed clients share its keys, history and tracks. There are no accounts, subscriptions, payments, minute credits or tier queues.

## Runtime and setup

Use PHP 8.4, Composer, Postgres, Redis, FFmpeg and yt-dlp. Compose supplies Postgres and Redis with loopback-bound ports. The Windows launcher `scripts/runtime/start-local-backend-workers.ps1` sets the local runtime profile, migrates, and launches Laravel and workers. `start-local-dev.ps1` also starts WXT development.

On a private Linux server, install the same prerequisites, run Composer and migrations in `app/backend`, and supervise HTTP, workers and scheduler. Existing Ubuntu/managed deployment scripts remain available. Upload the repository root: contracts/localization in `packages/` are runtime dependencies.

Copy `.env.example` for a fresh installation and generate `APP_KEY` once. Back it up with the database: encrypted credentials and correction state require it.

## Provider configuration

Extension Settings has ElevenLabs, OpenAI and Cerebras key fields in one card. Models are fixed to `scribe_v2`, `gpt-6-luna` and `gpt-oss-120b` respectively. The backend encrypts credentials and returns only configured flags and models. Blank fields preserve keys; Remove key clears them. Environment keys work until overridden; model environment variables and old saved model overrides are ignored. HTTP and workers refresh settings, so changing keys does not require restarting workers.

Generation requires ElevenLabs plus the selected analysis provider. Cards, Quick Fix and full lyrics replacement use the saved generation provider/model and recheck its credentials before actual calls.

### Codex with ChatGPT

Codex is an optional text provider alongside the existing API providers. Install the official Codex CLI 0.123.0 or newer on the backend host, available to both PHP HTTP processes and queue workers. Set `CODEX_BINARY` to its executable or absolute path if it is not on their PATH. On Windows, use the native `codex.exe` when PHP cannot resolve the npm launcher. Select **Sign in with ChatGPT** in the extension's Settings tab to open the browser authorization page. Complete sign-in, then return to the extension; its connection status updates automatically. The **Open ChatGPT sign-in** link reopens a pending attempt. Keep the generation workers running while signing in; each attempt expires after ten minutes.

Browser OAuth returns to a loopback callback hosted by Codex on the backend computer (port 1455 with CLI 0.123.0). Run the backend on the same computer as the browser, or explicitly arrange access to that callback when hosting elsewhere; the ordinary backend HTTP port does not forward it. Finish or close another Codex browser sign-in before starting one here. Codex owns OAuth state, PKCE and token exchange; the extension receives only the authorization URL and connection status.

Use the current stable Codex CLI for the current model catalog. Version 0.123.0 is the protocol minimum; an older CLI can return its bundled older models even when sign-in succeeds. Update the runtime selected by `CODEX_BINARY`, restart the backend and workers, then refresh Codex Settings after the one-minute account-summary cache expires. Model choices are discovered from that runtime and account, rather than maintained as a fixed application list.

In Watch, choose **Codex**, select a model discovered from the connected account, and optionally enable **Fast mode** when the model supports it. Codex requests use that account's applicable Codex usage limits or credits. Fast mode can consume credits faster. ElevenLabs still performs audio transcription using its API key. The API option continues to offer OpenAI and Cerebras with their existing configuration.

Every generation saves its provider, model and Codex Fast preference. History displays those choices. Later word cards, Quick Fix and lyrics replacement keep the saved choice. Disconnecting Codex prevents new Codex calls, including edits to its saved generations; reconnect to continue them. A failed Codex call never falls back to API billing. Provider dollar estimates do not measure Codex credit consumption.

The backend starts Codex app-server over private standard input/output. It uses `app/backend/storage/app/private/codex`, separate from your personal Codex installation, and Codex manages token refresh. HTTP and all queue workers must share this private storage directory; mount it as persistent private storage if running in containers. Do not expose app-server on a public network or copy your personal Codex credentials into this application. Restrict access to its private credential directory like the database and application key. All allowed clients of this personal instance share the connected Codex account. This does not create application user accounts or make the backend suitable for public multi-user hosting.

Text requests use ephemeral threads, existing subtitle instructions and structured output schemas, restricted filesystem access and disabled command/network tools. The application does not persist Codex prompts, transcripts or raw errors. OAuth status responses contain only connection state, model metadata and the temporary authorization URL, never access or refresh tokens.

Apply pending database migrations and rebuild/reload the extension when upgrading. If Codex is not installed, API providers remain available and Codex Settings explains the missing runtime. See the official [app-server integration](https://learn.chatgpt.com/docs/app-server) and [authentication guide](https://learn.chatgpt.com/docs/auth).

## Private access

There is no user login. `INSTANCE_ALLOWED_NETWORKS` defaults to `127.0.0.1/32,::1/128`. For LAN/VPN access, set `APP_URL` to the exact backend origin and allow only trusted client CIDRs. Host and Origin checks reject unexpected browser origins and DNS-rebinding hosts. Keep firewall rules consistent.

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
| `SUBTITLE_AI_GLOBAL_RATE_LIMIT_PER_MINUTE` | 300 attempts per provider |

Tune processes to memory, CPU and provider quotas. HTTP and workers share Redis permits. Queue retry-after must exceed worker timeout (defaults 1260 and 1200 seconds). Eight-chunk transcription bounds, retries, overlap/run locks and stalled-worker detection remain technical safeguards.

`subtitles:runtime-check --json` supplies worker configuration to `scripts/ops/render-supervisor-config.ps1`. Restart workers for code/config changes; provider settings refresh automatically.

## Storage and scheduler

Tracks stay saved until deletion by default. Retention days recompute existing track/completed-job deadlines from generation time. Enabling retention can make older tracks eligible for the next prune; disabling it clears deadlines. Temporary audio and correction state are always cleaned up.

Run `php artisan schedule:work` locally or a minute scheduler on the server. It prunes expired records and old queue diagnostics and checks stalled jobs. Transcript-cache expiry is separate (`SUBTITLE_TRANSCRIPT_CACHE_TTL_DAYS`, default 30 days) and does not delete saved tracks.

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
