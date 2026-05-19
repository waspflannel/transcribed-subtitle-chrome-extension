# Reliability

## Reliability Expectations

- Define startup, shutdown, retry, timeout, and idempotency behavior before production use.
- Make failure modes observable through logs, metrics, traces, or explicit error states.
- Prefer deterministic checks over manual inspection.

## Startup And Runtime

- The local/runtime profile uses Postgres for app data and Laravel batch metadata, and Redis for queued `subtitle-ai` jobs.
- SQLite is test-only through PHPUnit's in-memory configuration. It is not a supported app runtime or smoke profile.
- `php artisan subtitles:runtime-check` fails outside testing when `pdo_pgsql`, Postgres, or Redis queue configuration is missing.
- Local and production runtimes run explicit `subtitle-ai` queue workers; web requests dispatch jobs but do not manage worker processes.
- A conservative worker count is six `subtitle-ai` workers while OpenAI provider limits are still being observed.
- Add a startup smoke check to `scripts/agent/check.ps1`.
- Track startup targets and performance budgets here.

## Failure Handling

For each critical workflow, define:

- Expected failures.
- User-visible behavior.
- Retry or rollback behavior.
- Signals emitted for debugging.

Subtitle generation is asynchronous after request validation. `POST /v1/subtitle-jobs` returns a running job unless a compatible completed track is cached; the extension polls `GET /v1/subtitle-jobs/{jobId}` for running, completed, or failed status. Expected backend failures include unsupported YouTube URLs, videos over 60 minutes, non-public or unavailable videos, audio acquisition command failures, invalid language catalog codes, missing ElevenLabs/OpenAI configuration, provider timeouts, malformed Scribe word output, unusable cue timing or empty cue text, and queue storage outages. Failed queued work marks the job failed with a stable public message; raw audio cleanup runs in `finally` after successful transcription, provider failure, and thrown exceptions. Provider failures are not automatically retried across requests; users can submit the generation request again after fixing configuration or choosing a supported public video. A stale `preparing` job is reset and dispatched again because it indicates work never started. Structured logs identify the failed stage without dumping raw audio paths, cue text, prompts, or full transcripts.

Extension playback sync is local and browser-native. It attaches generated WebVTT as a hidden `TextTrack`, listens for `cuechange`, clears the overlay when no cue is active, and logs diagnostics instead of trying to auto-correct track drift.

Provider failover is intentionally deferred for this proof. ElevenLabs is the only transcription provider and remains sequential inside the first queued job. OpenAI is used for cue tokenization, optional romanization, optional cue translation, full-track word-card enrichment, and clicked-token word-card generation. Tokenization and cue translation run as independent queue batches after transcription; romanization waits for tokenized boundaries; full-track word-card enrichment waits for merged tokenized/translated/romanized cues.

Default generation tokenizes every transcript with a narrow structured-output tokenizer agent. ElevenLabs Scribe word output is converted directly into timed segments and WebVTT, with provider-created character spacing collapsed for no-space scripts before display or tokenization. The tokenization prompt includes previous/current/next cue text; token boundary decisions live in the agent, while backend validation only checks cue ID/index, sequential token indexes, non-empty lexical token text, and source-order boundary safety. Invalid multi-cue tokenization output is retried by splitting the batch with the same tokenizer agent; invalid single-cue output fails immediately with a stable public error instead of retrying with a second model or storing tokenless cues. Romanization is optional per request; when enabled for non-Latin-script tracks, invalid romanization output fails generation instead of silently dropping pronunciation metadata. Cue translation is optional per request and must preserve cue identity. Full word-card mode enriches tokenized cues and must preserve cue translation, token count, indexes, and text. Same-language source/target requests skip cue translation and card enrichment and persist transcript text as the translated text. On-click token enrichment caches successful metadata by token/context/language/model/cache-version and patches the stored track for the remaining 30-day track lifetime.

The language catalog is limited to the WER-ranked transcription set used in the popup. The tier is a transcription accuracy signal only; translation card quality can still vary by language pair, dialect, audio quality, and provider coverage.

Compatible completed tracks are reused immediately, compatible running jobs are reused without duplicate dispatch unless they are stale in `preparing`, failed compatible jobs are reset for retry, and Laravel route throttling enforces both per-install and per-IP limits. Every created or reset generation gets a new `run_id`; queued subtitle jobs carry that run ID and stale queued work skips before provider calls and artifact writes. Public failures map by stable error code to popup and overlay messages.

Generated tracks expire after 30 days. The scheduled `subtitles:prune-expired` command deletes expired tracks and their now-empty expired jobs daily; related trace rows are removed by job deletion. Extension requests also ignore expired tracks and regenerate through the existing compatible job row. Intermediate subtitle artifacts are deleted on finalization, failure, retry reset, and job deletion. Cancelled Laravel batch jobs skip provider calls before execution, but cancellation does not interrupt provider calls already in progress.

The popup local clear-state action removes local extension settings and anonymous install ID, clears in-memory tab subtitle state, and republishes default settings/no-track state to the active YouTube tab. It does not delete backend tracks because the first release has no user account or ownership model.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
