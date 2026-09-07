# transcribed-subtitle-extension Architecture

Created: 2026-04-28

## Purpose

Describe the system shape in a way future agents can inspect, validate, and modify safely.

## Current State

- Application code lives in `app/backend` and `app/extension`.
- Repository knowledge lives in `docs/`.
- Agent harness scripts live in `scripts/agent/`.
- Local runtime scripts live in `scripts/runtime/`; production and release scripts live in `scripts/ops/`, with the production runbook in `docs/operations/production-hosting-and-ops.md`.
- Execution plans live in `docs/exec-plans/`.
- Canonical API/data contracts live in `packages/contracts`.
- The canonical language catalog lives in `packages/contracts/languages.json`; `auto` is source-only and the real language choices use the provider WER-ranked transcription tags: Excellent, High Accuracy, Good, and Moderate.
- The backend exposes local `GET /up`, `POST /v1/extension-auth/login`, `GET /v1/extension-auth/account`, `POST /v1/extension-auth/logout`, `POST /v1/subtitle-jobs`, `GET /v1/subtitle-jobs`, `GET /v1/subtitle-jobs/{jobId}`, the pasted-lyrics `POST|GET|DELETE /v1/subtitle-jobs/{jobId}/lyrics` resource, `PATCH /v1/subtitle-jobs/{jobId}/cues/{cueId}/tokens/{tokenIndex}`, and `POST /v1/learning-tokens` JSON APIs.
- The extension detects YouTube watch URLs and YouTube Shorts URLs, normalizes both to the canonical 11-character YouTube video ID, and reuses the same subtitle job pipeline and generated-track cache.
- The Laravel web app exposes server-rendered beta marketing and account pages: a single-page marketing home (product mock, features, how-it-works, language coverage, pricing, FAQ as anchored sections), a thin pricing page, privacy, terms, support, `robots.txt`, `sitemap.xml`, authenticated dashboard, and owner-scoped public-safe job detail pages. Retired standalone pages (`/extension`, `/languages`, `/how-it-works`, `/faq`, `/desktop`) 301-redirect to home anchors. A plan chosen on the marketing site is carried through `register?plan=` into the session and surfaces as a continue-to-checkout banner on the dashboard after email verification.
- Web billing uses Stripe-hosted checkout and billing portal routes plus a verified `POST /stripe/webhook` endpoint. The app stores Cashier-style customer/subscription fields locally without adding Cashier as a dependency.
- SaaS identity is email/password through Laravel Fortify plus scoped Laravel Sanctum personal access tokens for extension API requests. Verified users own subtitle jobs; extension install IDs remain on requests and rows as device/abuse signals, not ownership boundaries.
- Subtitle jobs, per-run trace events, generated tracks, Laravel batch metadata, failed jobs, cache rows, and short-lived job artifacts persist in Postgres. SQLite is test-only through PHPUnit's isolated in-memory profile.
- Current subtitle generation runs through Redis queues on tier/work-type named queues. Generation orchestration uses `subtitle-generation-{tier}` queues, AI cue/token/translation/romanization/enrichment batches use `subtitle-batch-{tier}` queues, and local workers are started explicitly by the runtime launcher or `php artisan subtitles:dev-workers`. Generation runs as chained stage jobs so no worker is held through external I/O waits: `AcquireSubtitleAudio` downloads YouTube audio into a per-run workspace directory (or short-circuits on the per-video transcript cache), `OptimizeSubtitleAudio` prepares 16 kHz mono FLAC with FFmpeg (optionally ElevenLabs Audio Isolation) and splits long audio into overlapping chunks, a generation-family batch of `TranscribeSubtitleAudioChunk` jobs uploads each chunk to ElevenLabs Scribe v2 for word timestamps (short audio rides the same path as one whole-file chunk), and the `MergeSubtitleTranscript` completion merges chunk payloads by word-timestamp midpoint (language detection canonical to the first chunk), normalizes provider language codes into the catalog when possible, stores transcript/draft-cue artifacts, and dispatches OpenAI cue batch jobs on the account tier batch queue. Tier priority therefore applies at every stage boundary, and the per-run audio workspace is reclaimed by the merge stage, the failure handler, and job resets. Transcripts are also cached per video, keyed by requested source language and transcription model, so a repeat generation of the same video skips acquire/optimize/transcribe and starts at analysis dispatch.
- Each created or reset subtitle generation has a `run_id` that is carried by queued work. Workers skip stale queued payloads before provider calls or artifact writes when the queued run no longer matches the current job row, and the skip is recorded in sanitized trace events.
- Each subtitle job stores an internal `generation_tier` and `estimated_provider_cost_microusd`. Tier selection is server-side only until account auth exists; anonymous extension requests cannot choose paid-tier behavior.
- Authenticated generation is gated by active subscription state, current-period minute credits, plan feature flags, and per-tier queue capacity before queue dispatch. `user_id` is the capacity owner; extension install IDs remain device/abuse diagnostics. Submissions up to the tier's `generation_concurrency` start processing immediately; submissions beyond it (up to the tier's `submission_limit`) are accepted as status `queued` and promoted FIFO by `SubtitleJobAdmission` whenever one of the user's jobs leaves `running` (completion, failure, or dashboard deletion, with a stalled-job-command sweep as the crash backstop). A full queue rejects with `queue_full`. New, reset, or queued jobs reserve generated-video minutes, completed tracks debit the reservation, and failures release the reservation when no track is produced. Compatible running/queued jobs and cached tracks are reused without another queue slot or charge. AI batch members are windowed into at most `batch_concurrency` chains per job at dispatch, so the batch queues only hold work a job is allowed to run.
- Default generation is transcript-first: it stores timed subtitle cues, then runs one OpenAI/Laravel AI structured-output analysis call per cue batch so learner-facing token boundaries are chosen before display -- a merged agent that returns token boundaries plus cue translations when translation is requested, or a tokenize-only agent otherwise. ElevenLabs Scribe words are normalized into timed transcript segments directly, including removal of provider-created character spacing for no-space scripts, and WebVTT is generated from those segments for browser track sync. The analysis prompt includes previous/current/next cue text, and the agent schema returns only cue identity plus token index/text (plus translated text on the merged agent). Backend validation checks cue identity, sequential token indexes, non-empty lexical token text, and source-order boundary safety; invalid multi-cue batches split and retry through the same agent, while an invalid single-cue output retries the agent once and then degrades to deterministic tokenization (whitespace-split for spaced scripts, grapheme-cluster grouping for no-space scripts) rather than failing the whole job.
- Romanization is optional and controlled by `includeRomanization`; each romanization batch is chained directly after its own cue batch's analysis job, annotates existing tokenizer boundaries, cannot retokenize, and fails generation when enabled output is invalid. Languages with reliable algorithmic transliterations (Cyrillic, Greek) are romanized deterministically with ICU instead of an LLM call. Cue translation is controlled by `includeTranslation` and is produced by the same merged analysis call that tokenizes; it never changes token boundaries or learning metadata. Full word-card mode is opt-in and runs after tokenization, translation, and romanization have merged, enriching every existing token without changing cue translation, token count, indexes, or text. While a job is still running, already-available cues are served through a partial-track endpoint so the extension renders source text as soon as transcription lands and patches in translations and romanization as batches complete.
- Same-language source/target requests keep transcript subtitles, set translated text to the source text, and skip cue translation/card enrichment while keeping tokenizer output and optional romanization where applicable.
- On-click word cards call the backend one token at a time, use OpenAI structured output with the effective source and selected target language, cache by token/context/language/model, and patch the stored track for reuse.
- Completed tracks expose the narrow lyrics editing exception: full replacement runs through the revisioned encrypted correction continuation with public stages, completeness validation, cancellation, and atomic publication; Quick fix changes one source token and refreshes its cue translation, enabled romanization, and word cards in one backend AI request before publishing fresh track/cue identities.

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
      -> FFmpeg Scribe audio preparation to 16 kHz mono WAV
      -> Optional ElevenLabs Audio Isolation with fail-open normalized WAV fallback
      -> ElevenLabs Scribe word-timestamp transcription
      -> Scribe word normalization into timed segments and WebVTT
      -> Postgres job artifacts for transcript, draft cues, and per-batch AI results (each artifact carries the job's current `run_id` so a stale batch cannot overwrite current-run data after a reset)
      -> Postgres job trace events for queue, batch, timing, artifact, budget, cost, and failure diagnostics
      -> OpenAI/Laravel AI cue tokenization batches on the job tier queue
      -> Optional OpenAI/Laravel AI cue translation batches in parallel with tokenization
      -> Optional OpenAI/Laravel AI romanization batches preserving token boundaries after tokenization
      -> Optional OpenAI/Laravel AI word-card enrichment batches preserving token boundaries and cue translation
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
