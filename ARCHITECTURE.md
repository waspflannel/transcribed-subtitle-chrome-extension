# transcribed-subtitle-extension Architecture

Created: 2026-04-28

## Purpose

Describe the system shape in a way future agents can inspect, validate, and modify safely.

## Current State

- Saved-generation deletion uses owner-scoped `DELETE /v1/subtitle-generations/{jobId}` and shares the dashboard's locked deletion service, preserving usage history and cleanup. The extension revalidates its remembered generation on YouTube page entry; a missing generation falls back to another readable saved track or generation setup, without deletion polling. The existing job DELETE endpoint still cancels queued/running generation.

- First-subtitle experiment: chunked uploads use a 15-second opening and a 20-second second chunk plus the existing overlap; remaining chunks share the rest within the eight-upload cap. Set second seconds to zero to restore the first experiment; a two-upload cap also retains first-plus-remainder behavior. Optimization registers all planned transcription jobs without extracting every slice first; each job extracts its own slice and uploads it immediately. The first analysis batch is capped at two cues and a 10-second span (one longer cue stays intact), retaining available neighboring context. Later batches and correction work keep their existing limits. Full audio acquisition and WebM/Opus whole-file normalization still precede this handoff. All members remain in one transcription batch so early analysis cannot finalize an incomplete transcript.

- Draft cue generation excludes segments containing no Unicode letters or numbers before AI analysis, including cached transcripts. Remaining cues keep their text and timing and receive consecutive identities. Filtering never rewrites already published cues.

- The extension's generation selector chooses Luna (`openai`, default) or Cerebras per job. The backend resolves `OPENAI_MODEL` or `CEREBRAS_MODEL` once and persists `ai_provider` and `ai_model` on the job. Generation, later cards and Quick Fix use those saved values. Full lyrics replacement uses only the configured OpenAI/Luna model for alignment and parallel analysis of the fixed cues. Replacement does not change the saved generation selection; its logs and cost estimates identify the actual stage provider. Provider switches need no worker restart. `AI_PROVIDER` supplies the default for API requests that omit the selector and for evaluations. Job reuse includes provider and exact model; Scribe transcript reuse remains independent. Job history shows the saved provider, while the account tab shows the next selection. ElevenLabs remains the transcription provider.

- Transcription ingestion mode is pinned on the job: `upload` is the default; opt-in `youtube_url` validates public/non-live video metadata and duration before Scribe fetches the canonical video URL. Both routes converge on the same chunk artifacts and merge/analysis continuation. Optional vocabulary hints are normalized and included in job reuse identity; transcript-cache variants include ingestion mode and hints, and hinted entries are additionally scoped to the owner. All OpenAI agents use low reasoning effort through the shared provider configuration.
- Job output uses analysis version v13; transcript-cache version v3 is unchanged. Requested translations and readings must be usable strings. Invalid output fails visibly after at most one identical analysis retry while the run remains active.

- Application code lives in `app/backend` and `app/extension`.
- Repository knowledge lives in `docs/`.
- Agent harness scripts live in `scripts/agent/`.
- Local runtime scripts live in `scripts/runtime/`; production and release scripts live in `scripts/ops/`, with the production runbook in `docs/operations/production-hosting-and-ops.md`.
- Execution plans live in `docs/exec-plans/`.
- Canonical API/data contracts live in `packages/contracts`.
- The canonical language catalog lives in `packages/contracts/languages.json`; `auto` is source-only. Internal WER-ranked transcription tags remain on catalog entries for ops, but the website and extension language pickers list supported languages without quality tiers.
- The backend exposes local `GET /up`, `POST /v1/extension-auth/login`, `GET /v1/extension-auth/account`, `POST /v1/extension-auth/logout`, `POST /v1/subtitle-jobs`, `GET /v1/subtitle-jobs`, `GET /v1/subtitle-jobs/{jobId}`, the pasted-lyrics `POST|GET|DELETE /v1/subtitle-jobs/{jobId}/lyrics` resource, `PATCH /v1/subtitle-jobs/{jobId}/cues/{cueId}/tokens/{tokenIndex}`, and `POST /v1/learning-tokens` JSON APIs.
- The extension detects YouTube watch URLs and YouTube Shorts URLs, normalizes both to the canonical 11-character YouTube video ID, and reuses the same subtitle job pipeline and generated-track cache.
- The Laravel web app exposes server-rendered beta marketing and account pages: a single-page marketing home (product mock, features, how-it-works, language coverage, pricing, FAQ as anchored sections), a thin pricing page, privacy, terms, support, `robots.txt`, `sitemap.xml`, authenticated dashboard, and owner-scoped public-safe job detail pages. Retired standalone pages (`/extension`, `/languages`, `/how-it-works`, `/faq`, `/desktop`) 301-redirect to home anchors. A plan chosen on the marketing site is carried through `register?plan=` into the session and surfaces as a continue-to-checkout banner on the dashboard after email verification.
- Web billing uses Stripe-hosted checkout and billing portal routes plus a verified `POST /stripe/webhook` endpoint. The app stores Cashier-style customer/subscription fields locally without adding Cashier as a dependency.
- SaaS identity is email/password through Laravel Fortify plus scoped Laravel Sanctum personal access tokens for extension API requests. Verified users own subtitle jobs; extension install IDs remain on requests and rows as device/abuse signals, not ownership boundaries.
- Subtitle jobs, per-run trace events, generated tracks, Laravel batch metadata, failed jobs, cache rows, and short-lived job artifacts persist in Postgres. SQLite is test-only through PHPUnit's isolated in-memory profile.
- Current subtitle generation runs through Redis queues on tier/work-type named queues. Generation orchestration uses `subtitle-generation-{tier}` queues, AI cue/token/translation/romanization batches use `subtitle-batch-{tier}` queues, and local workers are started explicitly by the runtime launcher or `php artisan subtitles:dev-workers`. `AcquireSubtitleAudio` downloads YouTube audio into a per-run workspace directory (or short-circuits on the per-video transcript cache). `OptimizeSubtitleAudio` normalizes WebM/Opus or short audio and plans overlapping chunks; chunked M4A goes directly to per-slice preparation. A generation-family batch of `TranscribeSubtitleAudioChunk` jobs independently extracts 16 kHz mono FLAC slices and uploads them to ElevenLabs Scribe v2 for word timestamps (short audio rides the same path as one prepared whole-file chunk). Stable completed prefixes dispatch analysis while other members continue. `MergeSubtitleTranscript` reconciles unique, temporally overlapping boundary words before applying midpoint ownership (automatic language uses confidence-weighted owned speech across chunks), stores the final transcript/draft cues, and reconciles missing analysis work. Tier priority applies at every stage boundary. The per-run audio workspace is reclaimed by merge, failure, and job resets. Transcripts are cached per video, requested language and transcription model, so repeat generation can skip acquisition/preparation/transcription and start at analysis dispatch.
- Each created or reset subtitle generation has a `run_id` that is carried by queued work. Workers skip stale queued payloads before provider calls or artifact writes when the queued run no longer matches the current job row, and the skip is recorded in sanitized trace events.
- Each subtitle job stores an internal `generation_tier` and `estimated_provider_cost_microusd`. Tier selection is server-side only until account auth exists; anonymous extension requests cannot choose paid-tier behavior.
- Authenticated generation is gated by active subscription state, current-period minute credits, plan feature flags, and per-tier queue capacity before queue dispatch. `user_id` is the capacity owner; extension install IDs remain device/abuse diagnostics. Submissions up to the tier's `generation_concurrency` start processing immediately; submissions beyond it (up to the tier's `submission_limit`) are accepted as status `queued` and promoted FIFO by `SubtitleJobAdmission` whenever one of the user's jobs leaves `running` (completion, failure, or dashboard deletion, with a stalled-job-command sweep as the crash backstop). A full queue rejects with `queue_full`. New, reset, or queued jobs reserve generated-video minutes, completed tracks debit the reservation, and failures release the reservation when no track is produced. Compatible running/queued jobs and cached tracks are reused without another queue slot or charge. AI batch members are windowed into at most `batch_concurrency` chains per job at dispatch, so the batch queues only hold work a job is allowed to run.
- Default generation is transcript-first: Scribe words become fixed timed cues and WebVTT, then one Laravel AI analysis call per batch returns learner tokens, requested translations, and requested readings. The backend validates cue identity, sequential token indexes, lexical text and required strings. The prompt preserves spoken wording, spelling, dialect and grammar while choosing token boundaries. Validation continues to accept model wording without source-substring checks. Shared neighboring cues appear once in `contextCues`; timestamps are never delegated to the model. Completed batches use one `ANALYZED_CUES` artifact. There are no tokenization-only agents, script-based token fallbacks, split repairs, or separate romanization jobs.
- Romanization is controlled by `includeRomanization` and returned by the same analysis call for non-Latin tracks. Full word-card mode runs afterward and annotates existing tokens without changing their text, identity, translation or reading. The job status response includes a growing partial track. A short opening audio chunk publishes closed cues before the unresolved overlap; analysis starts while later chunks transcribe. Cue IDs, timings and analysis batch bounds stay fixed as cues append. Completed annotations overlay in any order; `readyThroughMs` advances only through contiguous analyzed cues. Partial responses do not expose interactive tokens. Per-batch overlap locks and artifact checks prevent duplicate analysis; finalization requires every persisted batch index.
- Same-language source/target requests keep transcript subtitles, set translated text to the source text, and skip cue translation/card enrichment while keeping tokenizer output and optional romanization where applicable.
- On-click word cards call the backend one token at a time, use OpenAI structured output with the effective source and selected target language, cache by token/context/language/model, and patch the stored track for reuse.
- Completed tracks expose the narrow lyrics editing exception: full replacement aligns once, runs independent analysis batches concurrently within existing account limits, and publishes on the last successful batch through the revisioned encrypted correction state with public stages, cancellation, and atomic publication. Replacement content/output validators and the partial-merge mode are removed; model allocations are assembled directly and missing analysis details are tolerated. Generation retains strict analysis validation; Quick fix changes one source token and refreshes its cue translation, enabled romanization, and word cards in one backend AI request before publishing fresh track/cue identities.

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
  - Laravel AI SDK for structured OpenAI agents
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
Chrome Extension
  -> proxy-facing Laravel API routes with bearer extension token
      -> Laravel application services create/reuse a subtitle job
  <- running/completed job status
  -> polls GET /v1/subtitle-jobs/{jobId}
      -> Laravel Redis queue workers on `subtitle-generation-{tier}` and `subtitle-batch-{tier}`
      -> YouTube audio acquisition in controlled temporary storage
      -> FFmpeg Scribe audio preparation to 16 kHz mono FLAC

      -> ElevenLabs Scribe word-timestamp transcription
      -> Scribe word normalization into timed segments and WebVTT
      -> Postgres job artifacts for transcript, draft cues, and per-batch AI results (each artifact carries the job's current `run_id` so a stale batch cannot overwrite current-run data after a reset)
      -> Postgres job trace events for queue, batch, timing, artifact, budget, cost, and failure diagnostics
      -> One Luna or Cerebras analysis call per cue batch: tokens plus requested translation/readings


      -> Optional Luna or Cerebras word-card enrichment batches preserving token boundaries and cue translation
      -> Tier budget checks and configurable provider cost estimates
      -> Billing entitlement checks and append-only minute ledger events
      -> Postgres track storage
  <- generated subtitle track

Browser Dashboard
  -> Laravel web auth
  -> Stripe-hosted checkout / billing portal redirects
  <- plan, subscription, minute usage, and support-visible billing state

Stripe
  -> POST /stripe/webhook with signed subscription and invoice events
      -> idempotent local subscription state
      -> monthly minute grants
```

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
- Extension code must not decide generation tier before authenticated entitlements exist.
- Extension code stores only scoped backend-issued Sanctum bearer tokens and safe account summaries; raw credentials stay in side-panel-to-background login messages and are not persisted.
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

Progressive upload runs report a provisional language for the available speech prefix. Final merge chooses the dominant language from confidence-weighted owned speech across all chunks. AI requests retain the requested source language: automatic mode identifies each cue and token from its text, so an English intro cannot disable translation of later Punjabi cues. Only an explicitly selected source language matching the target skips translation. Final merge reconciles all missing analysis dispatches and waits for every result before persisting a track or debiting minutes. Word-card details are generated only when a user clicks a token, then cached and persisted into that track. Silent chunks are allowed; an entirely silent transcript still fails visibly. Transcription cache output v5, job output v15 and clicked-card cache v10 separate mixed-language handling from older output. The URL ingestion option remains a single whole-video request.
