# transcribed-subtitle-extension Architecture

Created: 2026-04-28

## Purpose

Describe the system shape in a way future agents can inspect, validate, and modify safely.

## Current State

- Application code lives in `app/backend` and `app/extension`.
- Repository knowledge lives in `docs/`.
- Agent harness scripts live in `scripts/agent/`.
- Execution plans live in `docs/exec-plans/`.
- Canonical API/data contracts live in `packages/contracts`.
- The canonical language catalog lives in `packages/contracts/languages.json`; `auto` is source-only and the real language choices use the provider WER-ranked transcription tags: Excellent, High Accuracy, Good, and Moderate.
- The backend exposes local `GET /up`, `POST /v1/subtitle-jobs`, `GET /v1/subtitle-jobs`, `GET /v1/subtitle-jobs/{jobId}`, and `POST /v1/learning-tokens` JSON APIs.
- Subtitle jobs, generated tracks, and short-lived job artifacts persist in Laravel SQLite tables. `POST /v1/subtitle-jobs` returns a running job immediately unless a compatible completed track is cached; the extension polls `GET /v1/subtitle-jobs/{jobId}` until a completed response includes `track`.
- Current subtitle generation runs through Laravel database queues on the dedicated `subtitle-ai` queue. The first queued job acquires YouTube audio, sends it to ElevenLabs Scribe v2 for word timestamps using the requested source language or provider auto-detect, normalizes provider language codes into the catalog when possible, stores transcript/draft-cue artifacts, and dispatches OpenAI cue batch jobs.
- Default generation is transcript-first: it stores timed subtitle cues, then runs a dedicated OpenAI/Laravel AI structured-output tokenizer for every transcript so learner-facing token boundaries are chosen before display. ElevenLabs Scribe words are normalized into timed transcript segments directly, including removal of provider-created character spacing for no-space scripts, and WebVTT is generated from those segments for browser track sync. The tokenizer prompt includes previous/current/next cue text, and the agent schema returns only cue identity plus token index/text. Backend validation checks cue identity, sequential token indexes, non-empty lexical token text, and source-order boundary safety; invalid multi-cue tokenization batches split and retry through the same tokenizer agent, while invalid single-cue output fails generation.
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
  - Laravel database queues and job batches
  - Laravel migrations and Eloquent
  - SQLite first
  - Laravel AI SDK for structured OpenAI agents
  - Laravel HTTP client for the ElevenLabs Scribe speech-to-text request
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
  -> proxy-facing Laravel API routes
      -> Laravel application services create/reuse a subtitle job
  <- running/completed job status
  -> polls GET /v1/subtitle-jobs/{jobId}
      -> Laravel database queue worker on `subtitle-ai`
      -> YouTube audio acquisition in controlled temporary storage
      -> ElevenLabs Scribe word-timestamp transcription
      -> Scribe word normalization into timed segments and WebVTT
      -> job artifacts for transcript, draft cues, and per-batch AI results
      -> OpenAI/Laravel AI cue tokenization batches
      -> Optional OpenAI/Laravel AI cue translation batches in parallel with tokenization
      -> Optional OpenAI/Laravel AI romanization batches preserving token boundaries after tokenization
      -> Optional OpenAI/Laravel AI word-card enrichment batches preserving token boundaries and cue translation
      -> SQLite track storage
  <- generated subtitle track
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
- External provider responses must be normalized before storage or extension exposure.
- Use Laravel AI SDK provider identity and primitives where they fit; keep narrow provider requests only for capabilities the SDK wrapper does not expose.
- Eloquent models are internal details, not API contracts.

## Mechanical Enforcement Targets

Current local enforcement:

- `packages/contracts`: `npm run check`
- `app/backend`: `php artisan test --compact`
- `app/extension`: `npm test`, `npm run compile`, and `npm run build`
- repository harness: `.\scripts\agent\check.ps1`

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
