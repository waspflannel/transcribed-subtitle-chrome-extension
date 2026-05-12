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
- The backend exposes local `POST /v1/subtitle-jobs`, `GET /v1/subtitle-jobs`, and `POST /v1/learning-tokens` JSON APIs.
- Subtitle jobs and generated tracks persist in Laravel SQLite tables; successful requests return a completed job with its generated track.
- Current subtitle generation acquires YouTube audio, sends it to ElevenLabs Scribe v2 for word timestamps using the requested source language or provider auto-detect, normalizes provider language codes into the catalog when possible, and persists subtitle-focused tracks for 30 days.
- Default generation is transcript-first: it stores timed subtitle cues with token stubs and best-effort OpenAI romanization for Arabic-script cues. Full word-card mode is opt-in and enriches every cue into the selected target language before returning.
- Same-language source/target requests keep transcript subtitles, set translated text to the source text, and skip translation/card enrichment while keeping transcript-first romanization where applicable.
- On-click word cards call the backend one token at a time, use OpenAI structured output with the effective source and selected target language, cache by token/context/language/model, and patch the stored track for reuse.

## Selected Stack

```text
Extension
  - WXT 0.20.x
  - TypeScript 5.9.x

Backend
  - Laravel 13.x
  - Laravel scheduler
  - Laravel migrations and Eloquent
  - SQLite first
  - Laravel AI SDK for provider identity and future enrichment primitives
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
      -> Laravel application services
      -> YouTube audio acquisition in controlled temporary storage
      -> ElevenLabs Scribe word-timestamp transcription
      -> Scribe word normalization into WebVTT-backed cue validation
      -> Optional OpenAI/Laravel AI romanization or word-card enrichment
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
- Cross-cutting concerns should enter through explicit provider interfaces.
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
