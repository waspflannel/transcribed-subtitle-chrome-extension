# Plan: Phase 07 - Hardening And Release Readiness

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-30

## Goal

Harden the end-to-end product for first release readiness: failure states, rate limits, expiration cleanup, diagnostics, privacy copy, visual QA, and real-video acceptance tests.

This phase should make the product explainable and diagnosable when real public YouTube videos, provider limits, network failures, and user behavior create edge cases.

## Scope

- In scope:
  - 30-day track expiration cleanup.
  - Duplicate job coalescing verification.
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

- [ ] Expired tracks are cleaned up or ignored after 30 days.
- [ ] Raw audio is never retained after processing.
- [ ] Duplicate compatible jobs are coalesced.
- [ ] Rate limits are enforced by install ID and IP.
- [ ] All public failure states are visible in popup or overlay.
- [ ] Public errors do not expose secrets, stack traces, raw transcript dumps, or local paths.
- [ ] Logs include stable event names and IDs for each major workflow stage.
- [ ] Privacy copy clearly explains video audio/text processing by backend and AI services.
- [ ] User can clear local extension state.
- [ ] Acceptance test set includes clear Arabic, noisy Arabic, dialect-heavy Arabic, background-noise/music, and long-video cases.
- [ ] Overlay remains usable during play, pause, seek, and video navigation.
- [ ] `.\scripts\agent\check.ps1` plus stack-specific validation pass.

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

- [ ] Inspect end-to-end behavior from Phases 01-06.
- [ ] Implement or verify expiration cleanup.
- [ ] Verify raw audio cleanup under success and failure.
- [ ] Finalize rate limits and duplicate job coalescing.
- [ ] Add user-facing failure copy for all stable error codes.
- [ ] Add local clear-state control.
- [ ] Add structured logging for required events.
- [ ] Add privacy copy to popup/generation flow.
- [ ] Define real public-video acceptance set.
- [ ] Run acceptance tests and capture evidence.
- [ ] Perform visual QA on popup and overlay.
- [ ] Update docs, quality score, and technical debt tracker.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Run final validation.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
php artisan test
npm run build
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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:
