# transcribed-subtitle-extension Architecture

Created: 2026-04-28

## Purpose

Describe the system shape in a way future agents can inspect, validate, and modify safely.

## Current State

- Application code lives in `app/backend` and `app/extension`.
- Repository knowledge lives in `docs/`.
- Agent harness scripts live in `scripts/agent/`.
- Production/runtime operation scripts live in `scripts/runtime/`, with the production runbook in `docs/operations/production-hosting-and-ops.md`.
- Execution plans live in `docs/exec-plans/`.
- Canonical API/data contracts live in `packages/contracts`.
- The canonical language catalog lives in `packages/contracts/languages.json`; `auto` is source-only and the real language choices use the provider WER-ranked transcription tags: Excellent, High Accuracy, Good, and Moderate.
- The backend exposes local `GET /up`, `POST /v1/extension-auth/login`, `GET /v1/extension-auth/account`, `POST /v1/extension-auth/logout`, `POST /v1/subtitle-jobs`, `GET /v1/subtitle-jobs`, `GET /v1/subtitle-jobs/{jobId}`, and `POST /v1/learning-tokens` JSON APIs.
- The extension detects YouTube watch URLs and YouTube Shorts URLs, normalizes both to the canonical 11-character YouTube video ID, and reuses the same subtitle job pipeline and generated-track cache.
- The Laravel web app exposes server-rendered beta marketing and account pages: home, pricing, language coverage, how it works, FAQ, privacy, terms, support, `robots.txt`, `sitemap.xml`, authenticated dashboard, and owner-scoped public-safe job detail pages.
- Web billing uses Stripe-hosted checkout and billing portal routes plus a verified `POST /stripe/webhook` endpoint. The app stores Cashier-style customer/subscription fields locally without adding Cashier as a dependency.
- SaaS identity is email/password through Laravel Fortify plus scoped Laravel Sanctum personal access tokens for extension API requests. Verified users own subtitle jobs; extension install IDs remain on requests and rows as device/abuse signals, not ownership boundaries.
- Subtitle jobs, per-run trace events, generated tracks, Laravel batch metadata, failed jobs, cache rows, and short-lived job artifacts persist in Postgres. SQLite is test-only through PHPUnit's isolated in-memory profile.
- Current subtitle generation runs through Redis queues on tier/work-type named queues. Generation orchestration uses `subtitle-generation-{tier}` queues, AI cue/token/translation/romanization/enrichment batches use `subtitle-batch-{tier}` queues, and local workers are started explicitly by the runtime launcher or `php artisan subtitles:dev-workers`. The first queued generation job acquires YouTube audio, prepares it as a 16 kHz mono WAV with FFmpeg, optionally runs ElevenLabs Audio Isolation when enabled, sends the prepared WAV to ElevenLabs Scribe v2 for word timestamps using the requested source language or provider auto-detect, normalizes provider language codes into the catalog when possible, stores transcript/draft-cue artifacts, and dispatches OpenAI cue batch jobs on the account tier batch queue.
- Each created or reset subtitle generation has a `run_id` that is carried by queued work. Workers skip stale queued payloads before provider calls or artifact writes when the queued run no longer matches the current job row, and the skip is recorded in sanitized trace events.
- Each subtitle job stores an internal `generation_tier` and `estimated_provider_cost_microusd`. Tier selection is server-side only until account auth exists; anonymous extension requests cannot choose paid-tier behavior.
- Authenticated generation is gated by active subscription state, current-period minute credits, plan feature flags, and account-owned generation concurrency before queue dispatch. `user_id` is the generation concurrency owner; extension install IDs remain device/abuse diagnostics. New or reset jobs reserve generated-video minutes, completed tracks debit the reservation, and failures release the reservation when no track is produced. Compatible running jobs and cached tracks are reused without another generation slot or charge.
- Default generation is transcript-first: it stores timed subtitle cues, then runs a dedicated OpenAI/Laravel AI structured-output tokenizer for every transcript so learner-facing token boundaries are chosen before display. ElevenLabs Scribe words are normalized into timed transcript segments directly, including removal of provider-created character spacing for no-space scripts, and WebVTT is generated from those segments for browser track sync. The tokenizer prompt includes previous/current/next cue text, and the agent schema returns only cue identity plus token index/text. Backend validation checks cue identity, sequential token indexes, non-empty lexical token text, and source-order boundary safety; invalid multi-cue tokenization batches split and retry through the same tokenizer agent, while an invalid single-cue output retries the agent once and then degrades to deterministic tokenization (whitespace-split for spaced scripts, per-character grouping for no-space scripts) rather than failing the whole job.
- Romanization is a separate optional queued stage controlled by `includeRomanization`; it starts after tokenization, annotates existing tokenizer boundaries, cannot retokenize, and fails generation when enabled output is invalid. Cue translation is a separate optional queued stage controlled by `includeTranslation`; it can run alongside tokenization from draft source cues and later writes cue `translatedText` without changing token boundaries or learning metadata. Full word-card mode is opt-in and runs after tokenization, translation, and romanization have merged, enriching every existing token without changing cue translation, token count, indexes, or text.
- Same-language source/target requests keep transcript subtitles, set translated text to the source text, and skip cue translation/card enrichment while keeping tokenizer output and optional romanization where applicable.
- On-click word cards call the backend one token at a time, use OpenAI structured output with the effective source and selected target language, cache by token/context/language/model, and patch the stored track for reuse.

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
- managed Laravel deploy helper: `.\scripts\runtime\deploy-managed-laravel.ps1`

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
