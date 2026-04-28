# Plan: Phase 03 - Laravel Job API And Persistence

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-28

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

- [ ] Extension can call `POST /subtitle-jobs` through the proxy-facing API.
- [ ] Laravel validates request shape against the canonical contract.
- [ ] Laravel creates or reuses a job record.
- [ ] Laravel stores job and mock track data in SQLite.
- [ ] Laravel queue worker can process a mock subtitle job.
- [ ] Extension can poll `GET /subtitle-jobs/{jobId}`.
- [ ] Extension can lookup a ready mock track by video/language/version.
- [ ] Extension can load a mock track and enter the ready overlay state.
- [ ] Public errors use the stable error model.
- [ ] Provider secrets are not required for this phase.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |

## Completion Notes

- What changed:
- Validation results:
- Residual risk:
- Follow-up debt:
