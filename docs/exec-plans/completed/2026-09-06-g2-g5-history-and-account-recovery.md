# Plan: G2 G5 history and account recovery

Status: completed
Owner: agent
Created: 2026-09-06
Last updated: 2026-09-07

## Goal

Historical pre-commit work log. Current authorization is governed by `docs/exec-plans/completed/2026-09-07-resolve-smoothening-ux-audit.md`: all password work is excluded, including remaining hints/minlength/tests, which have now been withdrawn. Earlier validation totals below are retained as historical evidence only. G2's navigation-only solution resolves the report's permitted minimal acceptance; G5/U4 retain non-password subsets only.

Mitigate misleading History Retry (G2) with navigation-only links and explicit review instructions. Partially mitigate G5 with account/billing navigation and honest preferences, plus password and connection-expiry clarity (U4). Recovery/verification UX additions were withdrawn at the user's request.

## Scope

- Retained scope: native History/account-billing links, truthful feature preference copy, active connection listing, regression suites, per-finding testing notes. Password-rule hints/minlength/tests are now withdrawn too.
- Withdrawn at user request: new password/verification panel links and error directions, verification-page identity/account switching, and their new tests. Preserve all pre-existing auth routes, enforcement, security, and recovery behavior.
- Out of scope: selected-job submission, R1/R2/R8/R9/R12/R24, U1 queue-state propagation, provider calls, new dependencies, browser work, production, commits, original checkout. Preserve uncommitted G3/G4.

## Acceptance Criteria

- [x] History never submits a generation; links retain each video's URL and explain that options require review.
- [x] Retained Account and billing link uses configured origin and existing dashboard route without token/email URL parameters.
- [x] Signed-in feature copy describes preferences, not guaranteed entitlement; disabled Upgrade removed.
- [x] Withdraw added verification identity/account-switch UI and restore the baseline view, without changing shipped auth enforcement.
- [x] Expired tokens do not displace active connections before the listing limit; password hints/minlength and their test withdrawn under the broader exclusion.
- [x] Focused tests, Pint, full harness, and per-finding deferred manual testing notes complete.

## Relevant Context

- Product docs: audit G2/G5/U4 and Completed Tasks And Testing.
- Architecture docs: FRONTEND, DESIGN, RELIABILITY, SECURITY; root/backend AGENTS and Boost routing.
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: completed G3/G4 dashboard clarity plan.
- Known risks: G2 and G5 are partial; browser testing deferred. Account session/request races and password-reset session invalidation unchanged. Withdrawn recovery/verification UX is not a pending implementation task.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: existing extension history/account/API suites, backend WebAuthTest and SaasWebsiteAndSeoTest; full root harness.
- Screenshots or video: deferred by user until batches finish.
- Environment: existing worktree-only SQLite in-memory test env/fakes; no installs or shared services.
- Formatting: `php vendor/bin/pint --dirty --format agent`; `git diff --check`.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-06 | Native links replace History click handler entirely. | Current action submits global defaults, not job identity/options; backend compatible-job reset is a separate existing POST behavior, unchanged. |
| 2026-09-06 | Loaded ponytail, Laravel best-practices, specialist, security; Boost skill listing and SearchDocs succeeded. | Reuse Fortify GET routes and POST logout, escaped Blade, PHPUnit factories and native anchors. No new auth infrastructure. Workers unavailable. |
| 2026-09-06 | Include U4, defer U1. | U4 uses account forms/listing already reviewed; U1 needs queued state through multiple background producers rather than a label-only change. |
| 2026-09-07 | Withdraw password-recovery/verification UX additions at user request. | User intentionally omitted those additions to keep account creation easy. Keep billing/preferences, G2/G3/G4, expiry filtering, and hints matching pre-existing Password::min(10)->letters()->numbers(). No new password restrictions. |
| 2026-09-07 | Broader exclusion supersedes hint retention above. | Restore register/reset views and WebAuthTest to baseline. Exclude R24 and password/verification portions of G5/U4; pre-existing auth enforcement remains intact. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-06 | Script-created plan resumed after interruption. | Status confirmed only G3/G4 changes plus blank plan; no batch edits lost. |
| 2026-09-07 | Implemented G2 navigation, G5 recovery, U4 password/expiry clarity. | No new routes, dependencies, migrations, retry/session framework, or backend generation changes. |
| 2026-09-07 | Fixed failing test fixture, not application behavior. | Initial backend run: 21 passed/1 failed; token `created_at` update ignored by mass-assignment. Explicit fixture forceFill corrected it. |
| 2026-09-07 | Focused tests and full harness pass. | Focused: extension 29 tests/3 files, backend 22 tests/238 assertions. Full: backend 329/2655 assertions, extension 159/26 files; docs/contracts/typecheck/Chrome build pass. Pint passed. |
| 2026-09-07 | User-requested recovery/verification UX withdrawal complete. | Removed only added panel links/directions, verification identity/switch form, and related new tests. Baseline verification view/routes/Fortify actions unchanged; G5 marked partial. |
| 2026-09-07 | Post-withdrawal checks all pass. | Focused: extension 27 tests/3 files; backend 21 tests/220 assertions. Full harness: backend 328 tests/2637 assertions; extension 157 tests/26 files; docs/contracts/typecheck/Chrome build pass. Pint and diff check pass (line-ending warnings only). |

## Completion Notes

- What changed: native links replace the History action handler; the retained account/billing link reuses API origin validation; hints and expiry filters use existing rules. Recovery/verification links, directions, view additions, and corresponding new tests were removed at user request. G3/G4 retained.
- Validation results: pre-withdrawal results remain in the historical progress log. After withdrawal, focused extension: 27 passed; backend: 21 passed/220 assertions; Pint passed. Full-harness results are recorded in the correction entry below. No browser or live account/provider operations.
- Simplicity/readability review: no selected-job retry protocol or inferred entitlement. One billing link replaces the former three-link array; no credentials in URLs. Baseline verification view and auth routes/actions have no diff. Token filtering and pre-existing password requirements are unchanged.
- Residual risk: G2 and G5 are partial. No selected-history option restoration; recovery/verification UX intentionally withdrawn. R1 startup, R2/R9 operation identity, R8/R12 account races, and R24 remain. U1 deferred.
- Follow-up debt: manual/browser testing after batches, with separate G2/G5/U4 setup/steps/expected outcomes in Completed Tasks And Testing. No new quality-score claims without runtime evidence.
