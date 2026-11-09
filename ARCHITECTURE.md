# Transcribe Architecture

Created: 2026-04-28

## Purpose

Describe the system shape in a way future agents can inspect, validate, and modify safely.

## Current State


- Interface localization uses a shared nine-locale registry in `packages/localization`, independent of the transcription-language contract. Laravel loads website JSON catalogs through its native translator; web-only middleware selects the locale from validated query, encrypted cookie or browser preferences and restores the prior locale after the response. Localized URL variants preserve existing routes. The extension bundles its smaller catalogs and stores `interfaceLocale` in existing settings; panel and content contexts update together through the existing settings broadcast. Translation happens locally at rendering time and never modifies subtitle data or invokes a provider.

- Saved-generation deletion uses instance-scoped `DELETE /v1/subtitle-generations/{jobId}` and uses the locked deletion service, preserving cleanup. The extension revalidates its remembered generation on YouTube page entry; a missing generation falls back to another readable saved track or generation setup, without deletion polling. The existing job DELETE endpoint still cancels queued/running generation.

- First-subtitle experiment: chunked uploads use a 15-second opening and a 20-second second chunk plus the existing overlap; remaining chunks share the rest within the eight-upload cap. Set second seconds to zero to restore the first experiment; a two-upload cap also retains first-plus-remainder behavior. Optimization registers all planned transcription jobs without extracting every slice first; each job extracts its own slice and uploads it immediately. The first analysis batch is capped at two cues and a 10-second span (one longer cue stays intact), retaining available neighboring context. Later batches and correction work keep their existing limits. Full audio acquisition and WebM/Opus whole-file normalization still precede this handoff. All members remain in one transcription batch so early analysis cannot finalize an incomplete transcript.

- Cue segmentation for no-space scripts (Japanese, Chinese, Thai and similar): Scribe times these one character at a time, and held sung notes leave long gaps inside words. A pause between two such characters can break a cue only at an ICU (`intl`) word boundary; it forces a break before a kanji or katakana word and is otherwise a soft candidate for the 6-second/84-character limits. Sentence-final `。！？` always closes its cue; timing jitter and point timestamps cannot glue the next sentence onto it. Decisions read only the current cue and the incoming word, so published streaming cues never change.

- Draft cue generation excludes segments containing no Unicode letters or numbers before AI analysis, including cached transcripts. Remaining cues keep their text and timing and receive consecutive identities. Filtering never rewrites already published cues.

- The extension's generation selector chooses OpenAI (`openai`, default), Cerebras or a connected Codex account per job. Codex sessions share one login, so they are capped (default 3) and a 401 inside a turn clears the login only after a fresh session confirms it is gone. The backend persists the resolved provider/model and Codex Fast preference on the job. Generation, later cards and Quick Fix use those saved values. Full lyrics replacement defaults to that selection and lets the user choose another provider/model/speed for the correction. The correction row pins its effective selection for alignment, parallel analysis, retries and cost attribution without changing the original job. Requests that omit correction selection and older rows inherit the original job. Provider switches need no worker restart. `AI_PROVIDER` supplies the default for generation API requests that omit the selector and for evaluations. Job reuse includes provider, exact model and Codex speed; Scribe transcript reuse remains independent. Job history shows the saved generation provider, while correction status identifies the correction provider. ElevenLabs remains the transcription provider.

- Transcription ingestion mode is pinned on the job: `upload` is the default; opt-in `youtube_url` validates public/non-live video metadata and duration before Scribe fetches the canonical video URL. Both routes converge on the same chunk artifacts and merge/analysis continuation. Ingestion mode distinguishes job and transcript reuse. Vocabulary hints were removed; Scribe requests contain no keyterms. All OpenAI agents use low reasoning effort through the shared provider configuration.
- Processing versions live in `SubtitleProcessingVersion`: job analysis v19, transcript chunks v9 and learning-token cache v11. New work does not reuse earlier output that lost Korean spaces, moved zero-duration suffixes into the next cue, or split Japanese words at sung pauses; retained tracks remain readable until expiry. Requested translations and readings must be usable strings. Invalid output fails visibly after at most one identical analysis retry while the run remains active.

- Application code lives in `app/backend` and `app/extension`.
- Repository knowledge lives in `docs/`.
- Agent harness scripts live in `scripts/agent/`.
- Local runtime scripts live in `scripts/runtime/`; production and release scripts live in `scripts/ops/`, with the production runbook in `docs/operations/production-hosting-and-ops.md`.
- Execution plans live in `docs/exec-plans/`.
- Canonical API/data contracts live in `packages/contracts`.
- The canonical language catalog lives in `packages/contracts/languages.json`; `auto` is source-only. Internal WER-ranked transcription tags remain on catalog entries for ops, but the website and extension language pickers list supported languages without quality tiers.
- The backend exposes `GET /up`, `GET|PUT /v1/settings`, subtitle generation/history/status/deletion, lyrics correction/cancellation, Quick Fix and learning-token APIs under `/v1`. All API requests carry a validated extension installation ID.
- The extension detects YouTube watch URLs and YouTube Shorts URLs, normalizes both to the canonical 11-character YouTube video ID, and reuses the same subtitle job pipeline and generated-track cache.
- The Laravel web app exposes local information, installation/help, privacy and terms. The extension Settings tab configures encrypted backend provider keys/models and optional track retention through `GET|PUT /v1/settings`. No API returns stored keys. Environment configuration remains available; workers refresh provider settings before every job.
- This is a personal local/self-hosted BYOK application. The installation owns jobs, tracks and corrections. There are no application accounts, subscriptions, billing ledger operations or paid tiers. Existing legacy account/billing database records are inert and retained for upgrade safety.
- HTTP access is restricted to loopback or explicitly configured trusted networks, expected hosts and same-origin/extension requests; `INSTANCE_ALLOWED_EXTENSION_IDS` optionally pins the extension IDs. External tools (yt-dlp, its JS runtime, ffmpeg) receive an allowlisted environment from `App\Support\ChildProcessEnvironment`. The install ID remains a device signal, not ownership or authentication. The backend is not a public multi-user service.
- Subtitle jobs, per-run trace events, generated tracks, Laravel batch metadata, failed jobs, cache rows, and short-lived job artifacts persist in Postgres. SQLite is test-only through PHPUnit's isolated in-memory profile.
- Current subtitle generation runs through Redis queues on work-type queues. Generation work (acquire, optimize, transcription chunks) uses the `redis` connection (retry 1380s, worker timeout 1320s); batch work (analysis, merge, prepare/publish, lyrics correction) uses `redis-batch` (retry 360s, worker timeout 330s) so crashed work is redelivered before the stalled-job check. A redelivered acquisition re-claims its run under a per-run lock; a redelivered merge re-dispatches pending analysis; Prepare assembles and publishes the track in one locked transaction (no separate finalize job). The transcript cache is upserted after the merge commit and is best-effort. A transcript with only symbols fails as `no_speech`. Trace events older than 30 days are pruned daily. Generation orchestration uses `subtitle-generation` queues, AI cue/token/translation/romanization batches use `subtitle-batch` queues, and local workers are started explicitly by the runtime launcher or `php artisan subtitles:dev-workers`. `AcquireSubtitleAudio` downloads YouTube audio into a per-run workspace directory (or short-circuits on the per-video transcript cache). `OptimizeSubtitleAudio` normalizes WebM/Opus or short audio and plans overlapping chunks; chunked M4A goes directly to per-slice preparation. A generation-family batch of `TranscribeSubtitleAudioChunk` jobs independently extracts 16 kHz mono FLAC slices and uploads them to ElevenLabs Scribe v2 for word timestamps (short audio rides the same path as one prepared whole-file chunk). Stable completed prefixes dispatch analysis while other members continue. `MergeSubtitleTranscript` reconciles unique, temporally overlapping boundary words before applying midpoint ownership (automatic language uses confidence-weighted owned speech across chunks), stores the final transcript/draft cues, and reconciles missing analysis work. The per-run audio workspace is reclaimed by merge, failure, and job resets. Transcripts are cached per video, requested language and transcription model, so repeat generation can skip acquisition/preparation/transcription and start at analysis dispatch.
- Each created or reset subtitle generation has a `run_id` that is carried by queued work. Workers skip stale queued payloads before provider calls or artifact writes when the queued run no longer matches the current job row, and the skip is recorded in sanitized trace events.
- Default generation is transcript-first: Scribe words become fixed timed cues and WebVTT, then one Laravel AI analysis call per batch returns learner tokens, requested translations, and requested readings. The backend validates cue identity, sequential token indexes, lexical text and required strings. The prompt preserves spoken wording, spelling, dialect and grammar while choosing token boundaries. If tokens do not spell the cue text in order (ignoring case, spacing and punctuation), the batch gets its one retry; the retry's wording is then accepted. Shared neighboring cues appear once in `contextCues`; timestamps are never delegated to the model. Completed batches use one `ANALYZED_CUES` artifact. There are no tokenization-only agents, script-based token fallbacks, split repairs, or separate romanization jobs.
- Romanization is controlled by `includeRomanization` and returned by the same analysis call for non-Latin tracks. Word cards are generated only on demand after completion. The job status response includes a growing partial track. A short opening audio chunk publishes closed cues before the unresolved overlap; analysis starts while later chunks transcribe. Cue IDs, timings and analysis batch bounds stay fixed as cues append. Completed annotations overlay in any order; `readyThroughMs` advances only through contiguous analyzed cues. Partial responses do not expose interactive tokens. Per-batch overlap locks and artifact checks prevent duplicate analysis; finalization requires every persisted batch index.
- Same-language source/target requests keep transcript subtitles, set translated text to the source text, and skip cue translation/card enrichment while keeping tokenizer output and optional romanization where applicable.
- On-click word cards call the backend one token at a time, use the saved job provider/model with the effective source and selected target language, cache by token/context/language/model, and patch the stored track for reuse.
- Completed tracks expose the narrow lyrics editing exception: full replacement validates obvious junk before queueing, aligns once, checks ordered known cue references and complete in-range pasted-part consumption, then runs independent analysis batches concurrently on the shared analysis worker pool. It publishes on the last successful batch through revisioned encrypted correction state with public stages, cancellation, and atomic publication. Song-match/completeness and derived-output quality gates remain removed; missing analysis details are tolerated and partial merging is unavailable. Generation retains strict analysis validation; Quick fix changes one source token and refreshes its cue translation, enabled romanization, and word cards in one backend AI request before publishing fresh track/cue identities. It checks only the edited cue before the paid call and fails with 422 `token_span_not_found`, leaving the track unchanged, when the stored tokens cannot locate the word. Lyrics replacement drops aligned chunks without letters or numbers, splits pasted no-space scripts at ICU word boundaries (NFC text), aligns with compact `{cueId, endPartIndex}` output, and gives a cue with no usable tokens one whole-line token.

## Selected Stack

```text
Extension
  - WXT 0.20.x
  - TypeScript 5.9.x

Backend
  - Laravel 13.x
  - Laravel scheduler
  - Laravel Redis queues and database-backed job batches
  - Laravel migrations and Eloquent
  - Postgres + Redis runtime profile
  - SQLite for PHPUnit in-memory tests only
  - Laravel AI SDK for structured OpenAI/Cerebras agents
  - Laravel HTTP client for the ElevenLabs Scribe speech-to-text request
  - Laravel HTTP client for optional ElevenLabs Audio Isolation
  - Configurable `ffmpeg` binary for Scribe audio preparation
  - Configurable `yt-dlp` binary for the first YouTube audio acquisition proof
  - Laravel Boost 2.x as development tooling
  - Local Boost skills routed by `docs/references/boost-skill-routing.md`

Contracts
  - OpenAPI / JSON Schema canonical files
  - TypeScript extension types generated or checked from schemas
  - Laravel request/response validation checked against schemas
```

## Product Runtime Shape

```text
Chrome extension (Watch / Study / History / Settings)
  -> private Laravel /v1 API (installation ID, no account)
  -> Postgres jobs, saved tracks and encrypted instance settings
  -> Redis subtitle-generation workers (default 9)
      YouTube audio -> FFmpeg -> parallel ElevenLabs Scribe chunks
  -> Redis subtitle-batch workers (default 22)
      saved OpenAI/Cerebras model -> tokens, translation and readings
  <- partial subtitles, then completed synchronized track
  -> on-demand cards and Quick Fix with saved generation provider/model
  -> lyrics replacement with its own saved provider/model/speed
```

All jobs share one performance class. Machine/provider concurrency, timeouts and retry bounds remain configurable technical controls. A nonblocking warning appears above 30 minutes; there is no product duration cap. Tracks are kept indefinitely by default. Setting retention days recalculates existing track deadlines from generation time; disabling retention clears them. Temporary audio and correction state are always cleaned up.

## Boundary Model

Use this directional model unless a later decision record changes it:

```text
Contracts -> Config -> Persistence -> Services -> HTTP/UI
```

Rules:

- Dependencies should move in one direction through the layers.
- Cross-cutting concerns should enter through explicit provider services.
- Boundary inputs should be parsed or validated before internal use.
- Runtime side effects should be isolated from pure domain logic.
- Generated or external schemas should be documented under `docs/generated/`.
- Extension code must not call AI providers directly.
- Provider keys pass from the Settings form to the private backend and are encrypted there. The extension clears key inputs and never persists or reads back secrets.
- External provider responses must be normalized before storage or extension exposure.
- Use Laravel AI SDK provider identity and primitives where they fit; keep narrow provider requests only for capabilities the SDK wrapper does not expose.
- Eloquent models are internal details, not API contracts.

## Mechanical Enforcement Targets

Current local enforcement:

- `packages/contracts`: `npm run check`
- `app/backend`: `php artisan test --compact`
- `app/extension`: `npm test`, `npm run compile`, and `npm run build`
- repository harness: `.\scripts\agent\check.ps1`
- local generation metrics: `php artisan subtitles:metrics --json`
- production readiness: `php artisan ops:production-check --target=production --json`
- managed Laravel deploy helper: `.\scripts\ops\deploy-managed-laravel.ps1`
- first Ubuntu/EC2 host boot: `./scripts/ops/deploy-ubuntu.sh`

Promote these into CI when a remote repository workflow is introduced.

Future enforcement targets:

- Disallow cross-layer imports.
- Require validation at external input boundaries.
- Require structured logging in runtime code.
- Limit file size in shared and runtime directories.
- Require tests for shared helpers and business rules.
- Require remediation-oriented messages for custom lints.

## Decision Log

Record architecture decisions in `docs/design-docs/index.md` or a dedicated decision file when they affect future work.

Progressive upload runs report a provisional language for the available speech prefix. Final merge chooses the dominant language from confidence-weighted owned speech across all chunks. AI requests retain the requested source language: automatic mode identifies each cue and token from its text, so an English intro cannot disable translation of later Punjabi cues. Only an explicitly selected source language matching the target skips translation. Final merge reconciles all missing analysis dispatches and waits for every result before persisting a track. Word-card details are generated only when a user clicks a token, then cached and persisted into that track. Silent chunks are allowed; an entirely silent transcript still fails visibly. The URL ingestion option remains a single whole-video request.

- Optimization is serialized per run. It accepts only the optimization stage, saves a transcription continuation before publishing, and recovers missing publication without preparing audio again. Advanced-stage redelivery leaves analysis untouched. Duplicate recovered chunks use existing locks/artifacts.
- Actual outbound provider calls share technical provider request/concurrency limits across queues, interactive cards and corrections. Identical card misses serialize and recheck the cache; conflicting track edits reject before paid work. Requests rejected by local admission release queued work without consuming provider exception retries.

## Review fixes (2026-11-08)

Job polling uses a one-second interval with cross-tab pacing and a 30-second timeout. Contract errors stop immediately; transient failures retry at five seconds, at most six consecutive times. sinceRevision omits unchanged partial tracks. The background pushes state through panel ports, with a 30-second fallback poll. Rendering preserves the saved source language. Overlapping cues select the latest start. Ads hide the overlay until playback resumes.

Reasoning defaults to low. Codex and Claude concurrency defaults to 3. Direct full download defaults on. Existing environment overrides remain effective. Stage starts refresh the active run heartbeat even without progress changes. Claude prompts hold shared runtime locks; disconnect and account replacement require exclusive ownership.
