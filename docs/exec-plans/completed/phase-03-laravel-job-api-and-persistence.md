# Plan: Phase 03 - Laravel Job API And Persistence

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-30

## Goal

Implement the Laravel-side job API, local persistence, queue skeleton, and mock track path so the extension can exercise the full request/status/track loop before real audio and AI provider work.

This phase proves the product control plane: extension to proxy layer, proxy layer to backend service, backend queue state, status polling, cache lookup, and validated track response.

## Scope

- In scope:
  - Laravel API routes for create job, get job status, lookup track, and get track.
  - Proxy-facing request validation.
  - SQLite migrations and Eloquent models for jobs and tracks.
  - Job lifecycle states through `ready` and `failed`.
  - Laravel queued job skeleton for subtitle processing.
  - Duplicate job coalescing by video/language/version.
  - Basic rate-limit hooks by anonymous install ID and IP.
  - Mock generated track response that conforms to the canonical schema.
  - Extension API client integration with status polling.
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
- [x] Laravel queue worker can process a mock subtitle job.
- [x] Extension can poll `GET /subtitle-jobs/{jobId}`.
- [x] Extension can lookup a ready mock track by video/language/version.
- [x] Extension can load a mock track and enter the ready overlay state.
- [x] Public errors use the stable error model.
- [x] Provider secrets are not required for this phase.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `phase-01-project-scaffold-and-contracts.md`, `phase-02-youtube-extension-shell.md`, `phase-05-generated-track-and-overlay-sync.md`
- Known risks:
  - If contracts are only validated in one language, PHP and TypeScript can drift.
  - Queue state must be persisted so browser reloads do not lose job status.
  - Mock data must be clearly marked as mock and removed from release paths later.

## Implementation Steps

- [ ] Inspect canonical contract files from Phase 01.
- [ ] Create Laravel migrations for subtitle jobs and subtitle tracks.
- [ ] Create Eloquent models/repositories for jobs and tracks.
- [ ] Implement create job route with validation and idempotency.
- [ ] Implement job status route.
- [ ] Implement track lookup route.
- [ ] Implement get track route.
- [ ] Add queued job skeleton that writes a validated mock track.
- [ ] Add extension API client methods.
- [ ] Add popup/status polling behavior.
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

- Tests: Laravel route tests, job state transition tests, schema validation tests, extension API client tests.
- Screenshots or video: extension shows queued/ready status using mock backend.
- Logs: queue job processed without unexpected errors.
- Metrics or traces: not required.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Use polling for first job status implementation. | Polling is simpler and adequate until measured load requires push updates. |
| 2026-04-30 | Add a canonical track lookup response contract before implementing lookup. | The active OpenAPI file exposes create/status/get-track but Phase 03 requires lookup by video/language/version; Laravel and the extension should share that boundary instead of using an undocumented endpoint. |
| 2026-04-30 | Use the Phase 03 Laravel Boost lenses: `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, and `laravel-security`. | The phase touches API inputs, validation, Eloquent persistence, queues, rate limits, and public error responses. Generic recommendations for auth, Sanctum, Horizon, Redis, and user accounts remain out of scope. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-04-30 | Started Phase 03 on `codex/phase-03-laravel-job-api`; loaded project docs, Laravel guidance, and current Laravel 13 docs. Baseline harness check completed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` |
| 2026-04-30 | Added canonical ready-track lookup contract and generated TypeScript types. | `npm run check` in `packages/contracts` |
| 2026-04-30 | Implemented Laravel `/v1` job and track API, SQLite migrations/models, queue job skeleton, mock track generation, rate-limit hooks, and stable API errors. | `php artisan route:list --path=v1`; focused Laravel tests |
| 2026-04-30 | Integrated the extension popup/background/content path with lookup, create-job, polling, track loading, and ready overlay rendering. | `npm run test`; `npm run compile`; `npm run build` |

## Completion Notes

- What changed:
  - Added the canonical `TrackLookupResponse` schema, OpenAPI lookup route, fixture validation, and generated TypeScript type.
  - Added Laravel `/v1/subtitle-jobs`, `/v1/subtitle-jobs/{jobId}`, `/v1/tracks/lookup`, and `/v1/tracks/{trackId}` routes.
  - Added SQLite migrations, Eloquent models/factories, resources, form requests, stable API error rendering, install-ID middleware, and rate limits by install ID and IP.
  - Added a mock `ProcessSubtitleJob` queue job that persists a canonical mock track and moves jobs to `completed`.
  - Replaced the popup's manual overlay-state selector with an explicit Generate subtitles action wired to backend lookup/create/poll/fetch flow.
  - Updated content overlay rendering to show processing state, stable errors, and the first mock cue when ready.
  - Updated architecture, security, observability, contracts, and quality docs for the new runtime path.
- Validation results:
  - `.\scripts\agent\doctor.ps1` passed.
  - Baseline `.\scripts\agent\check.ps1` passed before implementation.
  - `npm run check` in `packages/contracts` passed.
  - `php artisan route:list --path=v1` showed the four Phase 03 routes.
  - `php artisan test --compact` passed: 12 tests, 68 assertions.
  - `npm run test` in `app/extension` passed: 4 files, 10 tests.
  - `npm run compile` in `app/extension` passed.
  - `npm run build` in `app/extension` passed.
  - `php artisan migrate --force` applied the two Phase 03 migrations to local SQLite.
  - `php artisan migrate:status` showed both subtitle migrations as ran.
  - `.\scripts\agent\check.ps1` passed.
  - `.\scripts\agent\verify-pr.ps1` passed when run sequentially.
  - `git diff --check` reported no whitespace errors.
- Simplicity/readability review:
  - Kept one documented product path: YouTube video, local Laravel API, SQLite job/track rows, mock queue job, status polling, ready overlay.
  - Avoided provider abstractions, auth systems, Redis/Horizon, WebSockets, and generalized platform handling.
  - Added only one small Laravel service because job creation/coalescing, track lookup, and queue state transitions would otherwise crowd controllers/jobs.
  - Reused the canonical contracts package for TypeScript types and kept PHP validation directly aligned with the schema fields.
- Residual risk:
  - The extension polls from the background worker, which is adequate for Phase 03 but may need lifecycle hardening if Chrome suspends the worker during longer real processing.
  - PHP response validation is covered by feature tests and canonical shape construction, not by a PHP JSON Schema validator.
  - Expired jobs are marked expired but regeneration for the same compatibility key is deferred.
- Follow-up debt:
  - Phase 04 should add real audio/provider failure paths, raw audio cleanup evidence, provider-timeout diagnostics, and stronger runtime observability.
