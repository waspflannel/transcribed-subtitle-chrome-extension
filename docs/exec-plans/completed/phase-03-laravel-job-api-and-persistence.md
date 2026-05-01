# Plan: Phase 03 - Laravel Job API And Persistence

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-30

## Goal

Implement the Laravel-side subtitle generation API, local persistence, and mock track path so the extension can exercise the full request/track loop before real audio and AI provider work.

This phase proves the product control path: extension to proxy layer, proxy layer to backend service, backend-owned cache reuse, persistence, and validated track response.

## Scope

- In scope:
  - Laravel API route for subtitle job creation/generation.
  - Proxy-facing request validation.
  - SQLite migrations and Eloquent models for jobs and tracks.
  - Synchronous mock subtitle generation that returns a completed job or stable API error.
  - Duplicate job coalescing by video/language/version.
  - Basic rate-limit hooks by anonymous install ID and IP.
  - Mock generated track response that conforms to the canonical schema.
  - Extension API client integration with the generate request.
- Out of scope:
  - Real YouTube audio acquisition.
  - Real transcription.
  - Real translation or token analysis.
  - Production cache/database services.
  - Server-sent events or WebSockets.

## Acceptance Criteria

- [x] Extension can call `POST /subtitle-jobs` through the proxy-facing API.
- [x] Laravel validates request shape against the canonical contract.
- [x] Laravel creates or reuses a job record.
- [x] Laravel stores job and mock track data in SQLite.
- [x] Laravel can synchronously generate a mock subtitle track.
- [x] Extension can receive a ready mock track from the completed job response and enter the ready overlay state.
- [x] Public errors use the stable error model.
- [x] Provider secrets are not required for this phase.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `phase-01-project-scaffold-and-contracts.md`, `phase-02-youtube-extension-shell.md`, `phase-05-generated-track-and-overlay-sync.md`
- Known risks:
  - If contracts are only validated in one language, PHP and TypeScript can drift.
  - Mock data must be clearly marked as mock and removed from release paths later.

## Implementation Steps

- [ ] Inspect canonical contract files from Phase 01.
- [ ] Create Laravel migrations for subtitle jobs and subtitle tracks.
- [ ] Create Eloquent models/repositories for jobs and tracks.
- [ ] Implement create job route with validation and idempotency.
- [ ] Return the completed generated track from the create route.
- [ ] Keep track lookup/reuse inside the job API instead of exposing separate track routes.
- [ ] Add synchronous mock generation that writes a validated mock track.
- [ ] Add extension API client methods.
- [ ] Add popup generate behavior.
- [ ] Add stable public error responses.
- [ ] Add Laravel and extension tests.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Run validation and record evidence.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
php artisan test
npm run build
```

Evidence to capture:

- Tests: Laravel route/regeneration tests, schema validation tests, extension API client tests.
- Screenshots or video: extension shows ready status using mock backend.
- Logs: no unexpected backend errors.
- Metrics or traces: not required.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-30 | Use the Phase 03 Laravel Boost lenses: `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, and `laravel-security`. | The phase touches API inputs, validation, Eloquent persistence, rate limits, and public error responses. Generic recommendations for auth, Sanctum, Horizon, Redis, and user accounts remain out of scope. |
| 2026-04-30 | Collapse ready-track lookup and track fetching into the job API. | The extension should ask for subtitles once; the backend should own cache reuse, regeneration, and completed track delivery. |
| 2026-04-30 | Derive popup/background video state from the active tab URL when the user opens the popup or clicks Generate. | The content script does not need to continuously publish page/video status; the user-triggered generation path already proves which active YouTube watch page is in scope. |
| 2026-04-30 | Return the finished generated job from `POST /v1/subtitle-jobs` instead of exposing job polling. | The current product goal is simpler as one request that either returns subtitles or a stable error; longer async delivery can be reconsidered only if real processing forces it. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-04-30 | Started Phase 03 on `codex/phase-03-laravel-job-api`; loaded project docs, Laravel guidance, and current Laravel 13 docs. Baseline harness check completed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` |
| 2026-04-30 | Implemented Laravel `/v1` generation API, SQLite migrations/models, mock track generation, rate-limit hooks, and stable API errors. | `php artisan route:list --path=v1`; focused Laravel tests |
| 2026-04-30 | Integrated the extension popup/background/content path with create-job and ready overlay rendering from completed job responses. | `npm run test`; `npm run compile`; `npm run build` |
| 2026-04-30 | Removed separate extension-facing track lookup/fetch routes to keep the workflow aligned with the product flow. | `.\scripts\agent\check.ps1` |
| 2026-04-30 | Removed proactive content page-status reporting and active video-element tracking from the extension flow. | `npm run compile`; `npm run test`; `.\scripts\agent\check.ps1` |
| 2026-04-30 | Removed extension polling and the backend queue job skeleton; the generate route now returns the completed mock track directly. | `npm run check`; focused Laravel tests |
| 2026-04-30 | Removed persisted job status/progress fields after the API contract settled on completed-job-or-error responses. | `.\scripts\agent\check.ps1` |

## Completion Notes

- What changed:
  - Added Laravel `POST /v1/subtitle-jobs`.
  - Added SQLite migrations, Eloquent models/factories, resources, form requests, stable API error rendering, install-ID middleware, and rate limits by install ID and IP.
  - Added synchronous mock generation that persists a canonical mock track and returns a completed response.
  - Replaced the popup's manual overlay-state selector with an explicit Generate subtitles action wired to one backend request.
  - Simplified extension state so the background reads the active tab URL on popup/generate, while content only renders settings and subtitle state.
  - Updated content overlay rendering to show stable errors and the first mock cue when ready.
  - Updated architecture, security, observability, contracts, and quality docs for the new runtime path.
- Validation results:
  - `.\scripts\agent\doctor.ps1` passed.
  - Baseline `.\scripts\agent\check.ps1` passed before implementation.
  - `npm run check` in `packages/contracts` passed.
  - `php artisan route:list --path=v1` showed the single Phase 03 generation route.
  - `php artisan test --compact` passed: 7 tests, 92 assertions.
  - `npm run test` in `app/extension` passed: 4 files, 9 tests.
  - `npm run compile` in `app/extension` passed.
  - `npm run build` in `app/extension` passed.
  - `php artisan migrate --force` applied the two Phase 03 migrations to local SQLite.
  - `php artisan migrate:status` showed both subtitle migrations as ran.
  - `.\scripts\agent\check.ps1` passed.
  - `.\scripts\agent\verify-pr.ps1` passed when run sequentially.
  - `git diff --check` reported no whitespace errors.
- Simplicity/readability review:
  - Kept one documented product path: YouTube video, local Laravel API, SQLite job/track rows, synchronous mock generation, ready overlay.
  - Avoided provider abstractions, auth systems, Redis/Horizon, WebSockets, and generalized platform handling.
  - Added only one small Laravel service because job creation/coalescing, cache reuse, and generation would otherwise crowd controllers.
  - Reused the canonical contracts package for TypeScript types and kept PHP validation directly aligned with the schema fields.
- Residual risk:
  - The generate request is synchronous; Phase 04 must keep real processing within acceptable request time or deliberately reintroduce an async delivery mechanism.
  - PHP response validation is covered by feature tests and canonical shape construction, not by a PHP JSON Schema validator.
  - Expired compatibility records are reset and regenerated when requested again.
- Follow-up debt:
  - Phase 04 should add real audio/provider failure paths, raw audio cleanup evidence, provider-timeout diagnostics, and stronger runtime observability.
