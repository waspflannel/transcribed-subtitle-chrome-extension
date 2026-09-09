# Plan: Fix smoothening smoke failures G1 G5 R7

Status: active — code delivery complete; G1 runtime retest and G5 Stripe verification pending
Owner: agent
Created: 2026-09-08
Last updated: 2026-09-08

## Goal

Resolve the user's G1 cancellation runtime error, add cancellation to the main generation screen, restore R7 transcript Jump, and investigate/fix G5 subscription management that currently opens a payment-details-only Stripe portal.

## Scope

- In scope: narrow runtime/UI/billing fixes and regression checks for the three reported failures.
- Out of scope: browser testing, live Stripe/provider calls, account or subscription mutations, unrelated audit changes, dependency upgrades.

## Acceptance Criteria

- [ ] Cancellation receives a runtime response and is available from the main generation screen.
- [x] Transcript Jump seeks the displayed tab's matching video without weakening account/tab ownership (automated entrypoint regression; user retest pending).
- [ ] Subscription-management behavior is truthful and exposes the supported plan/cancellation flow; external configuration limits are documented.
- [x] Focused regressions and integrated checks pass; root code review and Ponytail review are recorded.

## Relevant Context

- Product docs: `docs/ux-audit-smoothening-2026-09-06.md`, `docs/smoothening-smoke-tests.md`.
- Architecture docs: `ARCHITECTURE.md`, `docs/REVIEW.md`, `docs/references/boost-skill-routing.md`.
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-09-08-complete-remaining-smoothening-fixes.md`.
- Known risks: real browser message dispatch differs from test mocks; Stripe invoices alone do not establish an active manageable subscription.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the bounded code changes; leave unverified external acceptance open.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and retest notes.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1 -SkipAppChecks
# App checks executed separately: backend full suite; extension full suite, compile and build.
```

Evidence to capture:

- Tests: 440 backend tests / 3,111 assertions; 222 extension tests / 30 files. Final test typing correction validated with 13 focused tests, compile and build.
- Screenshots or video: only the user's supplied Stripe screenshot; no browser testing performed.
- Logs: automated command output in this task; no private account/provider logs inspected.
- Metrics or traces: not applicable to these bounded fixes.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-08 | Three Luna xhigh agents own cancellation, transcript Jump, and backend billing respectively. | User requested coding delegation without progress checkups. Root owns docs, final integration checks and reviews. |
| 2026-09-08 | Work on the consolidated `smoothening-fixes` checkout with separate file ownership. | User explicitly consolidated branches; avoid new worker branch clutter. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-08 | Plan created; coding delegated with no browser testing or live billing changes. | Baseline `fcb6e89`; initial working tree clean. |
| 2026-09-08 | All three coding slices reviewed; two test-review improvements and final type errors corrected. | `docs/smoothening-smoke-followup-review.md`. |

## Completion Notes

- What changed: bind the current player before transcript Jump; show Cancel throughout main generation preparation/progress; explicit no-response sidepanel notification listener; optional Stripe portal configuration selection.
- Validation results: automated tests, compile, build and documentation checks passed as recorded above. No contract or dependency changes.
- Simplicity/readability review: existing functions and Stripe-native portal reused; no new abstractions. Root correctness and Ponytail reviews complete.
- Residual risk: current-source cancellation response works in automated tests but original browser error is not reproduced. Stripe account's active subscription and enabled portal features are unverified; the new configuration option alone does not establish G5 resolution.
- Follow-up debt: user retest G1/R7 with freshly reloaded extension and YouTube pages; inspect/configure Stripe subscription update/cancel features in the matching test environment and retest G5. Keep this plan active until those acceptance items are resolved.

