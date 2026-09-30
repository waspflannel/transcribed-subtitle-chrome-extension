# Reliability

## Reliability Expectations

- Define startup, shutdown, retry, timeout, and idempotency behavior before production use.
- Make failure modes observable through logs, metrics, traces, or explicit error states.
- Prefer deterministic checks over manual inspection.

## Startup And Runtime

- Chunked transcription jobs allow 720 seconds: up to 60 seconds for FFmpeg extraction, the existing 600-second Scribe request, and 60 seconds of slack. The per-chunk overlap lock expires after 780 seconds; queue retry-after remains 1260 seconds. A retry extracts the same slice again rather than trusting a possibly incomplete file. Completed/stale deliveries skip extraction. All members share the run workspace until failure or final transcript merge.

- The local/runtime profile uses Postgres for app data and Laravel batch metadata, and Redis for queued subtitle generation and AI batch jobs.
- The standard test entrypoints force in-memory SQLite, array cache/session/mail, sync queues, isolated storage and separate config/route/event cache paths before Laravel boots. A loaded-config guard runs before providers and migration traits. Composer tests never clear the host config cache. SQLite is test-only. It is not a supported app runtime or smoke profile.
- `php artisan subtitles:runtime-check` fails outside testing when `pdo_pgsql`, Postgres, Redis queue configuration, or Redis-backed subtitle concurrency bookkeeping is missing.
- `php artisan ops:production-check` verifies private deployment posture, application key, Postgres and Redis reachability, retry/worker timing, non-debug production logging, local/HTTPS application URL and media binaries. Provider keys can be added after setup through Settings.
- Local subtitle queue workers are started with `scripts/runtime/start-local-backend-workers.ps1`; production uses supervised workers.
- All work shares two queues: `subtitle-generation` (9 workers) and `subtitle-batch` (22 workers). Operators may tune worker counts for machine capacity.
- Production worker process heartbeat is monitored through Supervisor status for the rendered `tse-*` worker programs; app-level queue health remains visible through `php artisan subtitles:runtime --json`, `subtitles:slow --json`, and `subtitles:metrics --json`.
- Local dev workers and subtitle jobs allow unlimited release attempts because concurrency and overlap middleware intentionally release queued work. Transcription chunks and AI cue batches cap real exceptions at three with 15/60-second backoff; other generation stages cap them at one. Permanent transcription failures fail immediately. Release attempts must not exhaust the provider exception budget.
- Provider permit bookkeeping uses the dedicated Redis `subtitle_concurrency` store. Provider work runs outside its short lock; leases expire and saturation releases queued work. Redis queue blocking defaults to one second so workers revisit delayed jobs promptly.
- Add a startup smoke check to `scripts/agent/check.ps1`.

## Automatic Language Detection

- Detected language describes the available audio, not every cue. Progressive detection may change; final detection uses confidence-weighted owned speech across all chunks.
- AI requests retain `source_language=auto` and infer each cue/token language from its text. Automatic generation, lyric corrections and word cards must not skip translation or requested readings because the detected language matches the target. The explicit same-language optimization remains.
- Updating detected metadata must not change published cue text or timing. No separate language-detection request is needed.

## Failure Handling


Scribe connection failures, temporary 429s and 5xx responses retry only the affected chunk. Known quota/credit exhaustion, authentication, input and malformed-output failures remain terminal. Completed chunk artifacts and the audio workspace survive transient failures; permanent failure or exhausted retries uses the existing run-fenced cleanup and artifact cleanup. Starting a new guarded transcription attempt refreshes the stalled-job timestamp; stale or completed-chunk redelivery cannot extend an abandoned run's lifetime. Analysis uses per-run/batch overlap locks, existing-artifact reuse and transactional result/cost/completion recording.

Partial progress uses one run-scoped `partial_track` artifact containing lightweight preview cues. Draft/analysis writes invalidate it in the same job-lock transaction. The next progress read rebuilds it under that lock; unchanged reads select only the cached payload. Run reset and normal artifact cleanup remove it. HTTP responses still contain the full preview; this avoids repeated artifact decoding rather than reducing response bytes.

Failed and cancelled subtitle jobs receive a 30-day diagnostic expiry. A forward migration backfills existing null deadlines from the last update. Daily pruning removes expired terminal jobs and their trace rows, preserves active jobs, and does not read or mutate legacy billing records. Daily `queue:prune-batches --hours=720 --cancelled=720` and `queue:prune-failed --hours=720` retain completed/cancelled batch metadata and failed queue jobs for 30 days; unfinished batches are not pruned by this schedule.

Lyrics replacement wraps overlong aligned text server-side instead of spending another AI request solely to satisfy the 84-code-point cue limit. Word/grapheme boundaries preserve the text, and proportional subdivision stays inside the original timing slot with positive, contiguous durations. Other timing slots are unchanged. Full replacements return cue IDs and pasted segment ends; consecutive starts, separators and cue indexes are derived by the server. Partial merging is unavailable. Invalid source references, missing pasted parts and stale attempts fail before publication; semantic song matching is deliberately not enforced.

For each critical workflow, define:

- Expected failures.
- User-visible behavior.
- Retry or rollback behavior.
- Signals emitted for debugging.

Subtitle generation is asynchronous after input and required-key validation. New work returns a running job and dispatches immediately to the shared generation queue; compatible cached work is reused. The extension polls status and renders partial then completed tracks. Expected failures include unavailable/private/live video, invalid URLs/languages, missing keys, acquisition/encoding failure, malformed provider output, provider quotas/timeouts and unavailable queues. There is no duration product cap. Cleanup and run fencing apply to success, cancellation, failure, reset and deletion.

Extension playback sync is local and browser-native. It attaches generated WebVTT as a hidden `TextTrack`, listens for `cuechange`, clears the overlay when no cue is active, and logs diagnostics instead of trying to auto-correct track drift.

Each job pins one provider and exact model for generation, Quick Fix and cards: OpenAI by default or Cerebras. Workers pass that immutable selection explicitly to the SDK, including content retries, Quick Fix and cards; full replacement uses the same saved provider/model for both alignment and analysis; global configuration is never mutated for a job. Compatibility queries and the database unique key include both values, so provider/model variants coexist. Existing track access does not depend on the currently configured model. ElevenLabs remains the transcription provider. After all transcription chunks merge into fixed cues, one analysis call per batch produces tokens, requested translation and requested readings. Word cards are generated only when selected after completion. Run-scoped overlap locks, an existing-artifact check, and transactional artifact/cost/completion writes protect analysis redelivery. Finalization checks the exact expected batch indexes against the persisted plan.

Pasted-lyrics correction is an asynchronous continuation workflow over one attempt row. Alignment performs one provider call, then dispatches independent analysis batches on the shared analysis queue. The shared analysis revision stays stable while completed batch indices and their cue slices are merged into the latest encrypted state under lock. The last successful batch formats and atomically publishes the full track without another queued finalization step. Persisted serial attempts normalize their completed indices on claim; existing finalization deliveries remain supported. Requests reject obvious junk before queueing and retain technical install/IP throttles. Alignment must reference known timing slots in source order and consume every pasted part through increasing in-range integer endpoints; malformed allocations fail before derived work and preserve the published track. The server reconstructs text from pasted parts. Song-match, song-completeness, and derived-learning quality gates are disabled, and partial merging is unavailable. Missing analysis details are tolerated. A transient provider failure leaves the stage and revision unchanged so the same delivery retries safely, and a terminal failure updates only the matching nonterminal attempt, clears encrypted lyrics and work state, and leaves the track untouched. Queued or running attempts can be cancelled by incrementing the revision and clearing private state; late workers, failure callbacks, and stalled cleanup cannot change a cancelled attempt. The per-unit timeout is `provider timeout + 60` seconds (180 seconds with current defaults), enforced below the batch worker timeout and the selected queue connection's `retry_after`. Duplicate deliveries are prevented by an attempt-and-batch-keyed `WithoutOverlapping` middleware lock plus a claim that verifies attempt ID, status, stage, revision, and completed batch membership before any provider work and rechecks them before persisting progress; a stale or duplicate delivery no-ops without clearing state, failing the attempt, or recording cost. The required provider key is checked before each provider unit. Quick fix refreshes the edited cue through one synchronous AI call outside database locks. It validates the new translation, romanization, and word cards, rechecks track/run identity, and publishes fresh track/cue IDs with rebuilt WebVTT atomically. Provider failure or stale state preserves the old track.

Generation always uses the same analysis agent, with optional translation/reading schema fields. Validation checks cue ID/index, sequential token indexes, and non-empty lexical token text. The analysis prompt preserves supplied wording, spelling, contractions, slang, dialect and grammar without proofreading or adding words. Backend validation remains structural: spelling, script, source-substring and linguistic preferences do not trigger validation retries. Empty, malformed, punctuation-only, symbol-only, and combining-mark-only output remains invalid. Requested translation and readings must be non-empty strings. An active generation may repeat the same prompt once for malformed analysis; failure remains visible after that. Correction and clicked-card calls do not split or repair output. Output-token exhaustion and provider quota exhaustion are terminal, including SDK-wrapped HTTP 429s; transient transport/rate-limit failures retain queue backoff. All OpenAI agents use low reasoning and the configured Fast-mode option. Same-language requests skip translation. Word cards are generated only for clicked tokens; caching includes the owning job's provider and model, token, context, language and cache version.

Quick fix uses original transcript spans only when the full token sequence matches without skipping lexical text. Otherwise it rebuilds the edited cue from the accepted tokens. This keeps corrected words editable and avoids matching a later repeated word. Reconstruction preserves token text and attached punctuation; punctuation present only in the original transcript can be lost. Normal generation retains the original transcript in sourceText and WebVTT while the overlay displays model tokens.

The language catalog is limited to the WER-ranked transcription set used in the side panel. Catalog tiers are an internal transcription accuracy signal only and are not shown in the language picker; translation card quality can still vary by language pair, dialect, audio quality, and provider coverage.

Compatible completed tracks are reused immediately, compatible running jobs are reused without duplicate dispatch unless they are stale in `preparing`, failed compatible jobs are reset for retry, and Laravel route throttling enforces both per-install and per-IP limits. Reuse is shared across clients of the private instance. A unique reuse key protects concurrent generation; preserved legacy duplicates remain readable. Every created or reset generation gets a new `run_id`; queued subtitle jobs carry that run ID and stale queued work skips before provider calls and artifact writes. Public failures map by stable error code to popup and overlay messages.

Generation cost tracking uses configurable unit prices and safe units only: Scribe audio minutes and selected AI provider cue counts. The estimates are optional local cost telemetry and do not store prompts, transcripts, translations, token payloads, raw provider responses, or provider secrets.



Generated tracks have no expiry by default, with optional retention in Settings. The scheduled `subtitles:prune-expired` command deletes expired tracks and their now-empty expired jobs daily; related trace rows are removed by job deletion. Extension requests also ignore expired tracks and regenerate through the existing compatible job row. Intermediate subtitle artifacts are deleted on finalization, failure, retry reset, and job deletion. Cancelled Laravel batch jobs skip provider calls before execution, but cancellation does not interrupt provider calls already in progress. The scheduled `subtitles:fail-stalled-jobs` command (every five minutes) fails any job whose `running` state has exceeded its current stage timeout plus slack through `SubtitleJobFailureHandler::failJob` with `reason=stalled_timeout`, reclaiming temporary work; per-stage timeouts are explicit in `config/subtitles.php`. The same command never fails `queued` lyrics corrections (a long queue wait must not destroy the paste); an abandoned `running` correction fails once its `updated_at` passes the selected queue connection's `retry_after` plus the correction job's maximum backoff and the stalled-job slack, and the failure clears the encrypted lyrics and intermediate work state.

Production backups use Postgres custom-format dumps plus restore tests against a disposable restore-test database. A backup is not considered valid release evidence until restore succeeds and the restored database can run `migrate:status` and subtitle runtime inspection.

The side-panel local clear-state action removes local extension settings, installation ID and active-track state, then republishes defaults to the active YouTube tab. Backend keys, history and retention remain on the private instance.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.

Progressive subtitle runs serialize local prefix assembly under the job/run lock and never hold that lock across a provider call. Only a contiguous audio prefix publishes closed cues, excluding words that the next overlap can change. Trailing untimed words also hold back their preceding timed word and its cue: later speech can change their attachment, and a silent tail must retain them at finalization. Cue and batch bounds append without rewriting published data. Per-chunk overlap locks and durable chunk artifacts prevent repeat uploads on redelivery. Final merge requeues unfinished analysis, including a prefix whose queue publication was lost after its database commit; analysis locks and artifact checks prevent duplicate prompts and costs. Early completion callbacks wait for a full transcript and every persisted analysis index. Cancellation and run replacement reject late work.

Processing versions, including mode and feature suffixes, must fit the 64-character columns on both jobs and tracks. The unit test checks all eight combinations explicitly because the SQLite test database does not enforce this PostgreSQL limit.

Scribe words with strictly overlapping timestamps form an inseparable group before cue splitting and streaming cutoff filtering. Groups preserve every word and their combined provider time span; touching timestamps remain separate. An overlap group may exceed ordinary cue size limits because splitting it would create conflicting timings. A group crossing the stable boundary is held back in full, so final assembly does not rewrite already-published cues.

## Recovery and diagnostics (2026-09-15)

Optimization uses a per-run overlap lock (1260 seconds for a 1200-second job), an expected-stage guard, and a durable transcription plan. A missing publication can replay that plan without rerunning FFmpeg; an already-published plan or downstream-stage replay is a no-op. A crash immediately after batch publication can duplicate batch metadata; chunk locks, run fences and artifacts prevent duplicate completed work.

The shared provider boundary counts every outbound attempt, including malformed-output retries. `SUBTITLE_AI_GLOBAL_RATE_LIMIT_PER_MINUTE` counts actual calls per provider; `SUBTITLE_PROVIDER_GLOBAL_CONCURRENCY` defaults to 30 per provider. Each call requires its configured key. Local saturation releases work; provider overload/transport errors retain bounded retries and quota/auth errors remain terminal.

`subtitles:runtime` reports the full running-job count and at most 25 detailed rows. Every queue depth contains `total`, `ready`, `delayed`, and `reserved` counts from Laravel native queue APIs; unavailable reads report their exception class. These are diagnostic snapshots, not a transactional census.

## BYOK cancellation and storage

Cancellation uses the current job/run lock; stale workers cannot publish results after cancellation or reset. There is no application minute reservation, charge or refund. Provider requests already in flight may still complete, so temporary audio and intermediate work retain their existing cleanup and stale-write guards.

Tracks have nullable expiry. No expiry means keep until explicit deletion; configured retention recalculates deadlines from generation time for existing and future tracks. Disabling retention clears deadlines. The scheduler prunes only expired rows and never treats null as expired.
