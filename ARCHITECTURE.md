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
- The backend exposes the local `POST /v1/subtitle-jobs` JSON API.
- Subtitle jobs and generated tracks persist in Laravel SQLite tables; successful requests return a completed job with its generated track.
- Phase 04 replaces the mock generation path with backend YouTube audio acquisition, raw audio cleanup, and timestamped transcription proof output.

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
  - Laravel AI SDK 0.x
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
      -> transcription provider normalized to timestamped transcript segments
      -> cue segmentation
      -> Laravel AI SDK enrichment
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
- Laravel AI SDK/provider responses must be normalized before storage or extension exposure.
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
