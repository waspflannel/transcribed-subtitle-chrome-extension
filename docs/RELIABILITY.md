# Reliability

## Reliability Expectations

- Define startup, shutdown, retry, timeout, and idempotency behavior before production use.
- Make failure modes observable through logs, metrics, traces, or explicit error states.
- Prefer deterministic checks over manual inspection.

## Startup And Runtime

- The local/runtime profile uses Postgres for app data and Laravel batch metadata, and Redis for queued subtitle generation and AI batch jobs.
- SQLite is test-only through PHPUnit's in-memory configuration. It is not a supported app runtime or smoke profile.
- `php artisan subtitles:runtime-check` fails outside testing when `pdo_pgsql`, Postgres, Redis queue configuration, or Redis-backed subtitle concurrency bookkeeping is missing.
- `php artisan ops:production-check` verifies paid-beta deployment posture: `APP_DEBUG=false`, HTTPS `APP_URL`, configured `APP_KEY`, Postgres, Redis subtitle queues, retry-after greater than worker timeout, disabled production worker auto-start, Redis concurrency cache, configured provider/Stripe keys, enabled fail-open Audio Isolation, disabled billing test switcher, non-debug logging, and configured `yt-dlp`/`ffmpeg`.
- Local generate requests auto-start subtitle queue workers when `SUBTITLE_AUTO_START_WORKERS=true`; auto-start defaults off when `APP_ENV=production` unless explicitly enabled, so production can run supervised workers instead.
- Workers listen through shared worker groups. Defaults are `generation-priority` for all generation queues in `ultimate,pro,plus,base` order, `batch-priority` for all batch queues in the same order, plus `base-generation-guarantee` and `base-batch-guarantee` pools that listen only to base queues.
- Production worker process heartbeat is monitored through Supervisor status for the rendered `tse-*` worker programs; app-level queue health remains visible through `php artisan subtitles:runtime --json`, `subtitles:slow --json`, and `subtitles:metrics --json`.
- Auto-started workers and subtitle batch jobs default to unlimited release attempts because account-scoped AI batch concurrency throttling intentionally releases queued batch jobs for a later attempt; subtitle jobs cap real exceptions with `maxExceptions=1`, while capped one-attempt jobs would turn normal delays into `MaxAttemptsExceededException` failures.
- Conservative beta concurrency limits are configurable by tier: generation limits are base 1, plus 2, pro 3, ultimate 5; active AI batch limits are base 3, plus 8, pro 14, ultimate 20.
- Account generation admission is enforced before queue dispatch by authenticated `user_id`. AI batch concurrency caps are enforced for database/Redis queue workers through the dedicated `subtitle_concurrency` cache store, keyed by hashed user ID, tier, and limiter type. This keeps limiter locks and counters on Redis DB 1 while the global `CACHE_STORE` can remain database-backed. The sync queue driver bypasses batch caps so feature tests and local synchronous proofs still complete inline.
- Add a startup smoke check to `scripts/agent/check.ps1`.
- Generation performance budgets are internal telemetry gates, not user-visible promises: base short/medium/near-limit p95 targets are 4/10/30 minutes, plus 3/7/22 minutes, and pro 2/5/15 minutes.

## Failure Handling

For each critical workflow, define:

- Expected failures.
- User-visible behavior.
- Retry or rollback behavior.
- Signals emitted for debugging.

Subtitle generation is asynchronous after request validation. `POST /v1/subtitle-jobs` returns a running job unless a compatible completed track is cached; the extension polls `GET /v1/subtitle-jobs/{jobId}` for running, completed, or failed status. Expected backend failures include unsupported YouTube URLs, videos over 60 minutes, non-public or unavailable videos, audio acquisition command failures, FFmpeg audio preparation failures, invalid language catalog codes, missing ElevenLabs/OpenAI configuration, provider timeouts, malformed Scribe word output, unusable cue timing or empty cue text, and queue storage outages. Failed queued work marks the job failed with a stable public message; raw audio cleanup runs in `finally` after successful transcription, provider failure, and thrown exceptions. Audio Isolation is enabled by default and remains fail-open by default; HTTP failures, empty output, or undecodable isolation output fall back to a normalized 16 kHz mono WAV from the original source audio before Scribe. Provider failures are not automatically retried across requests; users can submit the generation request again after fixing configuration or choosing a supported public video. A stale `preparing` job is reset and dispatched again because it indicates work never started. Structured logs identify the failed stage without dumping raw audio paths, cue text, prompts, or full transcripts.

Extension playback sync is local and browser-native. It attaches generated WebVTT as a hidden `TextTrack`, listens for `cuechange`, clears the overlay when no cue is active, and logs diagnostics instead of trying to auto-correct track drift.

Provider failover is intentionally deferred for this proof. ElevenLabs is the only transcription provider and remains sequential inside the first queued job. Scribe input is prepared locally as a 16 kHz mono WAV, with ElevenLabs Audio Isolation as the default quality stage rather than a separate provider failover path. OpenAI is used for cue tokenization, optional romanization, optional cue translation, full-track word-card enrichment, and clicked-token word-card generation. Tokenization and cue translation run as independent queue batches after transcription; romanization waits for tokenized boundaries; full-track word-card enrichment waits for merged tokenized/translated/romanized cues.

Default generation tokenizes every transcript with a narrow structured-output tokenizer agent. ElevenLabs Scribe word output is converted directly into timed segments and WebVTT, with provider-created character spacing collapsed for no-space scripts before display or tokenization. The tokenization prompt includes previous/current/next cue text; token boundary decisions live in the agent, while backend validation only checks cue ID/index, sequential token indexes, non-empty lexical token text, and source-order boundary safety. Invalid multi-cue tokenization output is retried by splitting the batch with the same tokenizer agent; invalid single-cue output fails immediately with a stable public error instead of retrying with a second model or storing tokenless cues. Romanization is optional per request; when enabled for non-Latin-script tracks, invalid romanization output fails generation instead of silently dropping pronunciation metadata. Cue translation is optional per request and must preserve cue identity. Full word-card mode enriches tokenized cues and must preserve cue translation, token count, indexes, and text. Same-language source/target requests skip cue translation and card enrichment and persist transcript text as the translated text. On-click token enrichment caches successful metadata by token/context/language/model/cache-version and patches the stored track for the remaining 30-day track lifetime.

The language catalog is limited to the WER-ranked transcription set used in the popup. The tier is a transcription accuracy signal only; translation card quality can still vary by language pair, dialect, audio quality, and provider coverage.

Compatible completed tracks are reused immediately, compatible running jobs are reused without duplicate dispatch unless they are stale in `preparing`, failed compatible jobs are reset for retry, and Laravel route throttling enforces both per-install and per-IP limits. Reuse is scoped to the authenticated account; cross-account public-video caching is deferred. Every created or reset generation gets a new `run_id`; queued subtitle jobs carry that run ID and stale queued work skips before provider calls and artifact writes. Public failures map by stable error code to popup and overlay messages.

Generation cost tracking uses configurable unit prices and safe units only: Scribe audio minutes and OpenAI cue counts. The estimates are internal margin telemetry and do not store prompts, transcripts, translations, token payloads, raw provider responses, or provider secrets.

Billing state is updated through Stripe-hosted checkout, Stripe billing portal, and signed Stripe webhooks. Webhook event IDs are recorded before local mutation so retries and replays are idempotent. Subscription webhooks grant current-period minutes up to the active plan allowance; plan upgrades grant only the delta needed to reach the new period allowance. Failed payments move the local subscription to `past_due`, and canceled subscriptions stop entitlement checks from authorizing new generation.

Subtitle generation reserves public generated-video minutes before dispatch. After audio acquisition measures the actual duration, the reservation is topped up or reduced before transcription starts. Completed tracks debit the active reservation; failures release reserved minutes when no completed track was produced. Compatible completed tracks return without another reservation or debit.

Generated tracks expire after 30 days. The scheduled `subtitles:prune-expired` command deletes expired tracks and their now-empty expired jobs daily; related trace rows are removed by job deletion. Extension requests also ignore expired tracks and regenerate through the existing compatible job row. Intermediate subtitle artifacts are deleted on finalization, failure, retry reset, and job deletion. Cancelled Laravel batch jobs skip provider calls before execution, but cancellation does not interrupt provider calls already in progress.

Production backups use Postgres custom-format dumps plus restore tests against a disposable restore-test database. A backup is not considered valid release evidence until restore succeeds and the restored database can run `migrate:status` and subtitle runtime inspection.

The popup local clear-state action removes local extension settings and anonymous install ID, clears in-memory tab subtitle state, and republishes default settings/no-track state to the active YouTube tab. It does not delete backend tracks because the first release has no user account or ownership model.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
