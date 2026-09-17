# Plan: Audit launch readiness for live beta

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Assess whether this checkout is ready to host and invite real users. Separate confirmed bugs, release configuration work, missing operational proof, and optional polish.

## Scope

Inspect this project only: accounts/billing/security, subtitle pipeline, extension, onboarding, deployment and release evidence. Run isolated checks and read-only dependency audits. Do not deploy, modify application behavior, call paid providers, send mail, or change live accounts. Save a source-backed review and beta launch sequence.

## Acceptance Criteria

- [x] Review core flows and verify findings against current code and tests.
- [x] Run repository checks and report passed, failed and skipped checks honestly.
- [x] Deliver staging/private-beta/public-paid-launch verdict and ordered gaps.

## Relevant Context

- `docs/product-specs/release-readiness.md`
- `ARCHITECTURE.md`
- `docs/operations/production-hosting-and-ops.md`
- `docs/SECURITY.md`, `docs/RELIABILITY.md`, `docs/REVIEW.md`
- `docs/QUALITY_SCORE.md`, `docs/exec-plans/tech-debt-tracker.md`

## Implementation Steps

- [x] Inspect baseline and release gates; start independent backend, extension and pipeline reviews.
- [x] Run isolated validation and inspect deployment/public onboarding.
- [x] Recheck findings and save review.

## Validation Plan

Run `scripts/agent/check.ps1` and relevant read-only release checks. Normal backend tests force in-memory SQLite and disposable storage; external-service suites skip without disposable service configuration. No runtime migrations, tests against runtime services or live provider purchases are part of this audit.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Review only; application behavior unchanged. | User requested a readiness assessment. |
| 2026-09-16 | Apply Ponytail principles and three independent reviews. | Focus on concrete launch risks without unnecessary features. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Clean baseline at c4c1cbb; existing docs retain staging, billing, browser and recovery evidence gates. | Git status and release docs. |
| 2026-09-16 | Full harness passed: 691 backend tests / 38,562 assertions, 355 extension tests, contracts, compile and Chrome build. | Ignored `app/backend/storage/logs/launch-readiness-check.log`. |
| 2026-09-16 | All nine skipped service-dependent tests passed separately / 92 assertions using fresh disposable PostgreSQL and Redis containers. Containers removed after validation. | Ignored `app/backend/storage/logs/launch-readiness-integration.log`. |
| 2026-09-16 | Dependency audits clean; strict runtime check passed. Production check flagged ten local/release configuration settings. | Read-only commands; no secrets captured. |
| 2026-09-16 | Independent reviews confirmed checkout recovery and password-reset race; traced extension polling leak and load-dependent finalizer timeout. | [Launch review](../../launch-readiness-review-2026-09-16.md). |

## Completion Notes

- Deliverable: [launch-readiness review](../../launch-readiness-review-2026-09-16.md), with source references, evidence, four findings and ordered launch gates.
- Verdict: ready for private staging; focused fixes and hosted acceptance required before a paid beta.
- Only review documentation added. No application changes, deployments, account changes, paid provider calls or runtime database mutations.
- Existing tests passed, including service-dependent checks on disposable services. Review probes used isolated SQLite and fake HTTP.
- Hosted Stripe/provider/browser, recovery and load evidence remain explicit follow-up work; this completed audit does not close those release gates or unrelated plans.
