# Plan: Phase 07 - Hardening And Release Readiness

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-05-05

## Goal

Harden the end-to-end product for first release readiness: failure states, rate limits, expiration cleanup, diagnostics, privacy copy, visual QA, and real-video acceptance tests.

This phase should make the product explainable and diagnosable when real public YouTube videos, provider limits, network failures, and user behavior create edge cases.

## Scope

- In scope:
  - 30-day track expiration cleanup.
  - Duplicate job coalescing verification for compatible completed tracks and retried incomplete jobs.
  - Final rate limits by install ID and IP.
  - User-facing failure states.
  - Structured logs for extension, proxy layer, backend generation, providers, and overlay sync.
  - Privacy copy explaining backend and AI processing.
  - Local clear-state control.
  - Real public-video acceptance test set.
  - Visual QA for popup and overlay.
  - Quality score and docs updates.
- Out of scope:
  - User accounts.
  - Backend deletion endpoint implementation unless explicitly pulled forward.
  - Production autoscaling.
  - Chrome Web Store submission.
  - Multi-platform video support.
  - Subtitle editing.

## Acceptance Criteria

- [x] Expired tracks are cleaned up or ignored after 30 days.
- [x] Raw audio is never retained after processing.
- [x] Duplicate compatible jobs are coalesced.
- [x] Rate limits are enforced by install ID and IP.
- [x] All public failure states are visible in popup or overlay.
- [x] Public errors do not expose secrets, stack traces, raw transcript dumps, or local paths.
- [x] Logs include stable event names and IDs for each major workflow stage.
- [x] Privacy copy clearly explains video audio/text processing by backend and AI services.
- [x] User can clear local extension state.
- [x] Acceptance test set includes clear Arabic, noisy Arabic, dialect-heavy Arabic, background-noise/music, and long-video cases.
- [x] Overlay remains usable during play, pause, seek, and video navigation.
- [x] `.\scripts\agent\check.ps1` plus stack-specific validation pass.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/OBSERVABILITY.md`, `docs/RELIABILITY.md`, `docs/QUALITY_SCORE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: all prior phases
- Known risks:
  - Long videos can increase provider cost and latency.
  - Real YouTube pages can change in ways local fixtures do not cover.
  - Privacy expectations must be explicit because video-derived audio/text leaves the browser.

## Implementation Steps

- [x] Inspect end-to-end behavior from Phases 01-06.
- [x] Slice 1 - backend retention and abuse controls:
  - Add scheduled expired-track/job pruning using the existing 30-day `expires_at` fields.
  - Keep compatible completed-track reuse and incomplete-job retry behavior direct in `SubtitleJobService`.
  - Cover install-ID and IP rate limits with feature tests.
  - Verify raw audio cleanup remains covered for success, transcription failure, and enrichment failure.
- [x] Slice 2 - backend diagnostics and stable public errors:
  - Keep stage logs structured and free of secrets, raw paths, prompts, full transcripts, translations, and token payloads.
  - Add request/proxy diagnostics before a subtitle job exists, especially rate limiting and invalid install IDs.
  - Ensure extension-facing errors stay contract-shaped.
- [x] Slice 3 - extension release UX:
  - Map stable backend error codes to user-facing popup/overlay copy.
  - Add a local clear-state control that clears install/settings/local track state without requiring a backend delete endpoint.
  - Add privacy copy to the popup generation flow.
  - Keep overlay usable for loading, error, ready, play, pause, seek, and navigation states.
- [x] Slice 4 - release evidence and memory:
  - Define a real public-video acceptance set for clear Arabic, noisy Arabic, dialect-heavy Arabic, background-noise/music, and long-video cases.
  - Capture local validation evidence and record any manual real-video gaps as follow-up debt.
  - Update observability, reliability, security, frontend, quality score, and technical debt docs as needed.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Run final validation.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: expiration, rate limits, duplicate jobs, error states, cleanup, extension settings.
- Screenshots or video: popup and overlay in ready, error, and compact states.
- Logs: successful job, provider failure, unsupported video, rate limit, sync diagnostics.
- Metrics or traces: processing latency and failure counts for acceptance videos.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Keep backend deletion endpoint planned but not required for first release. | Local clear-state covers MVP privacy control while avoiding user account and ownership complexity. |
| 2026-05-05 | Keep Phase 07 synchronous and SQLite-first. | Laravel docs support named multi-key rate limiters and scheduled pruning without introducing queues, Redis, auth, or extra infrastructure. |
| 2026-05-05 | Treat local clear-state as browser-local only. | The first release has no user account or ownership model, and the active scope explicitly defers backend deletion endpoints. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-05-05 | Refined Phase 07 into backend retention/abuse controls, diagnostics/errors, extension release UX, and release evidence slices. | `AGENTS.md`; `ARCHITECTURE.md`; `docs/references/project-guardrails.md`; `docs/SECURITY.md`; `docs/OBSERVABILITY.md`; Context7 Laravel 13 docs for rate limiting and scheduler; `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` |
| 2026-05-05 | Implemented scheduled expiration pruning, configurable install/IP throttles, request IDs on API errors, proxy diagnostics, job retry/reuse logs, public extension error copy, privacy copy, and local clear-state control. | `php artisan test --compact tests/Feature/SubtitleJobApiTest.php tests/Feature/PruneExpiredSubtitleTracksTest.php`; `npm test -- api.test.ts`; `npm run compile` |
| 2026-05-05 | Added release acceptance matrix, first-release threat model, updated observability/reliability/frontend/contract docs, quality score, and release acceptance debt. | `docs/product-specs/release-readiness.md`; `docs/SECURITY.md`; `docs/QUALITY_SCORE.md`; `docs/exec-plans/tech-debt-tracker.md` |
| 2026-05-05 | Final validation and self-review completed; Phase 07 archived. | `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`; `.\scripts\agent\doc-gardening.ps1` |

## Completion Notes

- What changed: Added scheduled expired-subtitle pruning, configurable install/IP rate limits, request IDs on public API errors, proxy diagnostics, job creation/retry logs, extension generation lifecycle logs, public error copy, popup privacy copy, local clear-state control, release acceptance matrix, and first-release threat model.
- Validation results: `.\scripts\agent\doctor.ps1` passed; `.\scripts\agent\check.ps1` passed with contracts validation/build, 41 Laravel tests, 21 extension tests, TypeScript compile, and WXT build; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` passed with no findings.
- Simplicity/readability review: Kept the synchronous SQLite path, reused Laravel scheduler/rate-limiter primitives, avoided backend delete endpoints, queues, accounts, Redis, provider failover, and generic UI state machines.
- Residual risk: Full public-video matrix and browser screenshot QA still require a credentialed/manual release run because the harness does not yet automate YouTube extension screenshots or provider-backed real-video checks.
- Follow-up debt: `TD-003` remains open for automated extension screenshot smoke coverage; `TD-007` tracks release acceptance automation for public-video runs and sanitized evidence capture.
