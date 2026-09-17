# Plan: Fix launch readiness findings

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Fix all code findings from the launch-readiness review on `codex/launch-readiness-fixes`, prepare a real beta version and repeatable release validation, and identify the remaining hosted acceptance work truthfully.

## Scope

- R1: recover unresolved Stripe checkout creation without duplicate subscriptions or permanent lockout.
- R2: serialize extension password validation/token issuance against password reset.
- R3: remove completed generation monitors from polling-rate accounting without losing recovery state.
- R4: prevent healthy finalization backlog from looking like stalled execution while retaining dead-worker recovery.
- Release: version 0.1.0, stricter artifact validation, repeatable CI checks including disposable PostgreSQL/Redis, and actionable hosted acceptance checklist.
- Hosting domain/API origin, support email and hosting provider are undecided by user instruction. Leave values unset and remind the user at handoff. No deployment, real payment, provider purchase or email send in this branch work.

## Acceptance Criteria

- [x] Four findings fixed with meaningful regressions, including real disposable database concurrency where needed.
- [x] Release artifact cannot quietly target localhost; version and API permissions checked.
- [x] CI/release checks cover the actual repository harness and service-dependent tests.
- [x] Local checks pass; independent review addresses regressions. First remote CI run is checked after pushing.
- [x] Changes grouped for commits on the requested branch; unresolved hosted acceptance is documented.

## Relevant Context

- `docs/launch-readiness-review-2026-09-16.md`
- `docs/product-specs/release-readiness.md`
- `docs/operations/production-hosting-and-ops.md`
- `docs/SECURITY.md`, `docs/RELIABILITY.md`, `docs/quality/golden-principles.md`

## Implementation Steps

- [x] Create branch and assign independent fixes (billing, extension, pipeline); root owns authentication and release tooling.
- [x] Implement and test R1–R4.
- [x] Tighten release tooling and add CI.
- [x] Review, run full harness plus disposable integrations and update release docs. Commit/push follow as the final handoff.

## Validation Plan

Use isolated normal test harness; fresh disposable PostgreSQL/Redis for concurrency tests. Run targeted tests first, then `scripts/agent/check.ps1`, Pint on changed PHP, dependency audits, release packaging checks and documentation lint. Never run migrations/tests against runtime data. No assertion of real hosted/browser/provider acceptance from mocked tests.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Use Ponytail, Laravel best-practices/security and Git commit-grouping guidance. | Direct fixes, trusted boundaries, reviewable history. |
| 2026-09-16 | Boost MCP unavailable; use Context7 Laravel 13 docs and installed source for exact framework behavior. | Do not block authorized fixes on missing optional tooling. |
| 2026-09-16 | Keep deployment values unset. | User explicitly deferred domain/API address, support email and hosting provider; remind at handoff. |

## Commit Plan

1. Preserve audit and execution context.
2. Recover unresolved checkout safely, with billing tests.
3. Serialize extension authentication and password reset, with concurrency tests.
4. Clean up extension generation monitors and set beta version.
5. Fix queued finalization/watchdog behavior with regressions.
6. Add release/CI checks and update launch evidence and outstanding gates.

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Branch created from c4c1cbb; prior audit docs were the only untracked files. | Git status. |
| 2026-09-16 | Installed Laravel skills confirmed; current transaction docs fetched. | Boost CLI and Context7. |
| 2026-09-16 | Implemented R1–R4 and beta metadata; independent review added failed-cancellation cleanup, localized pending-checkout messages and a typed-exception concurrency fixture correction. | Focused billing/auth/pipeline/extension checks passed. |
| 2026-09-16 | Release checker rejects malformed paths and root-dot localhost aliases, validates exact ZIP permissions/version and rejects env/key files. Deploy requires explicit HTTPS health URL before mutation. | PowerShell fixture checks and real fixture-origin WXT ZIP build passed; fixture ZIP deleted. |
| 2026-09-16 | Full local harness with fresh disposable PostgreSQL/Redis passed: 725 backend tests / 39,068 assertions; 366 extension tests; contracts, compile, Chrome build and release guards. Both containers removed. | Ignored `app/backend/storage/logs/launch-fixes-check.log`. No integration skips. |
| 2026-09-16 | Pint and runtime Composer, full extension and shared-contract dependency audits passed. Workflow YAML parsed; pinned action commits verified against upstream tags. | Local commands and independent release review. |

## Completion Notes

- R1 safely reconciles unknown checkout sessions and never blindly recreates a possibly payable session. Before the original expiry, an absent lookup can require waiting up to 31 minutes; the translated UI gives the UTC deadline. Completed payments still depend on signed webhook reconciliation.
- R2 holds the user row lock through password validation and token issuance; tests prove both concurrent reset/login orderings using real PostgreSQL locks.
- R3 clears only owned inactive monitor claims, preserving interrupted recovery, pending cancellation and newer operations.
- R4 finalizes locally in the existing continuation after commit; genuine stalled-job recovery and legacy finalizer compatibility remain. No new migration or queue.
- Version 0.1.0 and repeatable release/CI checks are ready. No public artifact was distributed; normal local build was restored after fixture-origin packaging.
- Hosted release gates remain in `docs/product-specs/release-readiness.md`. User explicitly deferred hosting/domain/support decisions; remind them to supply those values at handoff. Native-speaker review, real provider spend, hosted billing/browser/backup/rollback/alert evidence are not claimed complete.
- Remote CI has no secrets and does not publish the fixture artifact. Its first run is inspected after this branch is pushed; local results above are the validated implementation baseline.
