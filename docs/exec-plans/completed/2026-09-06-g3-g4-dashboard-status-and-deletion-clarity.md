# Plan: G3 G4 dashboard status and deletion clarity

Status: completed
Owner: agent
Created: 2026-09-06
Last updated: 2026-09-06

## Goal

Historical pre-commit work log. Individual commits are now authorized under `docs/exec-plans/completed/2026-09-07-resolve-smoothening-ux-audit.md`. G3's manual GET refresh resolves its explicitly allowed minimum acceptance; no automatic polling is required. Earlier partial wording describes the absence of live updates, not an outstanding feature obligation.

Mitigate G3 with visible manual GET refresh and snapshot labels. Fix G4's missing video identity and deletion scope using existing routes and confirmation handling.

## Scope

- In scope: dashboard/detail Blade, dashboard row data and owned count, focused existing feature suites, audit evidence.
- Out of scope: automatic polling, new dependencies/routes, cancellation, R16 races, U2 minute labels, browser work, production, commits, and the original worktree.

## Acceptance Criteria

- [x] Refresh links use existing GET routes; refreshed fixtures show current job, usage, and billing values.
- [x] Source video IDs/canonical links appear in rows and details without title fetching or transcript exposure.
- [x] Clear all shows all owned jobs, including hidden/old-version jobs, and excludes other accounts.
- [x] Confirmations identify targets and explain track removal, lost reuse, completed-usage non-refund, and in-progress provider limitations.
- [x] Keep authorization, CSRF, deletion, and billing logic unchanged; record test/format/harness results and missing browser evidence.

## Relevant Context

- Product docs: `docs/ux-audit-smoothening-2026-09-06.md` (G3/G4).
- Architecture docs: `docs/FRONTEND.md`, `docs/DESIGN.md`, `docs/RELIABILITY.md`, `docs/SECURITY.md`.
- Quality rules: `docs/quality/golden-principles.md`
- Instructions: root/backend AGENTS, harness, project guardrails, Boost routing.
- Known risks: R16 unchanged; no browser validation. G3 remains a manual mitigation, not live synchronization.

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

Run `php artisan test --compact` for SaasWebsiteAndSeoTest and WebSubtitleJobDeletionTest, `php vendor/bin/pint --dirty --format agent`, and the root harness. Use SQLite in memory, array cache/session/mail, local storage, and fakes; never runtime migrations. No browser evidence authorized.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-06 | Native GET links and existing `data-confirm`; one owner-scoped count without display filters. | No polling, mutation changes, title service, or new abstraction needed. |
| 2026-09-06 | Loaded ponytail, local Laravel best-practices (Blade/tests) and specialist skills; Boost list and SearchDocs succeeded with local environment. | Follow current controllers, escaped Blade, factories, PHPUnit; skip generic infrastructure/coverage expansion. Boost namespace was unavailable under testing, then succeeded under local with SQLite/array settings. |
| 2026-09-06 | Composer lockfile install with local cache/home, no scripts/plugins. | 135 installs, zero updates; no existing environment or cached config copied. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-06 | Plan created with repository script; confirmed smoothening and only pre-existing untracked audit. | All work in isolated audit worktree. |
| 2026-09-06 | Implemented dashboard count/video data and two Blade views; reused wrapping action styles and scrolling table. | No route, service, JS, CSS, billing, or deletion mutation changes. |
| 2026-09-06 | Focused PHPUnit suites pass. | Initially 28 tests/236 assertions; after adding completed-usage deletion regression, 29 tests/242 assertions. |
| 2026-09-06 | Pint and full harness pass. | Backend 326 tests/2613 assertions; extension 148 tests/26 files; contracts, TypeScript, Chrome build, docs lint all pass. |
| 2026-09-06 | Updated audit and frontend docs. | G3 marked partial/manual mitigation; G4 fixed in code, browser evidence deferred. |

## Completion Notes

- What changed: three application files, two existing feature suites, frontend/audit docs, and this plan. No dependencies upgraded; installed existing Composer and contracts lockfiles locally. Ignored `.env` is newly authored test-only configuration with a dummy key, SQLite in memory, array stores/mail, sync queue, local storage, disabled auto-workers, and empty provider credentials.
- Validation results: focused tests, Pint, docs lint, contracts validation/type generation, full backend and extension tests, TypeScript, and Chrome build passed. Boost skill listing initially failed under testing (namespace disabled); reran successfully under local with SQLite/array settings and used SearchDocs before edits.
- Simplicity/readability review: one scalar count matches the existing owner-scoped bulk action. Canonical links use encoded IDs and escaped Blade output; shared confirmation copy is reused within its existing view. No new helpers, endpoints, polling, or migrations. Existing CSRF forms and owner checks untouched.
- Residual risk: no browser/visual measurements, live webhook journey, or concurrent settlement validation. Count is explicitly a snapshot, not an immutable deletion selection. G3 does not auto-update; R16 is unchanged.
- Follow-up debt: browser validation only when authorized; no expansion into other audit findings in this batch.
