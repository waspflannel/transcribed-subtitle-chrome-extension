# Plan: Architecture review cleanup 2026-06-09

Status: completed
Owner: agent
Created: 2026-06-09
Last updated: 2026-06-09

## Goal

Resolve the actionable issues from `docs/architecture-review-report-2026-06-09.md` across the extension, backend, contracts, public website assets, and documentation.

The target shape is one active Postgres/Redis subtitle runtime, side-panel vocabulary in extension code, typed message flow that catches missing handlers, honest signed-out account UI, reduced backend duplication, and no dead marketing/runtime compatibility artifacts on the active path.

## Scope

- In scope: all findings and deletion candidates in the 2026-06-09 architecture report, with implementation grouped by risk.
- In scope: update tests, contract types/schemas, generated docs, public website routes/assets, and harness docs when behavior or source paths change.
- Out of scope: changing product pricing, adding a full install/download funnel beyond replacing the duplicate `/desktop` route with a clearer extension route/redirect, and changing provider models or pipeline semantics.

## Acceptance Criteria

- [x] Keyboard shortcuts that send content-side setting patches persist settings and update the overlay.
- [x] Extension message types are split by destination and background request handling is exhaustively typed.
- [x] Signed-out panel/account UI no longer fabricates `Local beta` quota numbers.
- [x] Active side-panel code no longer uses popup naming except historical docs.
- [x] SQLite runtime compatibility branches and tests are removed; queue defaults align with Redis/Postgres runtime.
- [x] Dead public marketing assets and duplicate/misleading route/assets are removed or renamed.
- [x] Backend repeated job predicates, job history serialization, unique-violation detection, batch job wrappers, and processing version logic have single owners.
- [x] Worker auto-start is no longer a hidden core generation-service dependency.
- [x] Docs are updated for side panel, Redis runtime, route naming, and historical design docs.
- [x] `.\scripts\agent\check.ps1` passes or any failure is explained with evidence.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`, `docs/DESIGN.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/architecture-review-report-2026-06-09.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-18-retire-sqlite-runtime-for-postgres-redis.md`, `docs/exec-plans/completed/2026-06-08-frontend-architecture-refactor.md`, `docs/exec-plans/completed/2026-06-08-extension-transcript-panel-phase-2.md`
- Known risks: broad rename churn across extension tests; route/asset changes can invalidate website SEO tests; worker lifecycle changes need local runtime validation separate from unit tests.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Fix message handling, message typing, side-panel naming, and account empty state.
- [x] Remove SQLite runtime compatibility and realign queue defaults/checks.
- [x] Clean marketing route/assets/docs drift.
- [x] Collapse backend duplicated predicates/resources/jobs/version helpers.
- [x] Move worker auto-start out of core generation service.
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

- Tests: `.\scripts\agent\check.ps1` passed on 2026-06-09: contracts validation/build, 201 Laravel tests, 91 extension tests, TypeScript compile, and WXT build.
- Screenshots or video: not captured; this cleanup changed routing/copy and backend architecture, not visual layout behavior beyond the simple `/extension` page.
- Logs: no runtime logs required.
- Metrics or traces: no provider-backed generation run required for this cleanup.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-09 | Keep `queue_unavailable` as a generic public error code while removing SQLite-specific detection/copy. | Redis and worker infrastructure can still be temporarily unavailable; the stale part is the SQLite lock path and database-specific user copy. |
| 2026-06-09 | Replace duplicate `/desktop` with product-specific extension/install route behavior rather than keeping a second canonical home page. | The product is a browser extension, not the imported desktop reference design. |
| 2026-06-09 | Move historical root design docs only with reference updates. | They are not the active baseline, but active/completed plans still reference them for history. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-09 | Plan created and scoped from architecture review. | `docs/architecture-review-report-2026-06-09.md`; repo grep verification. |
| 2026-06-09 | Extension message, side-panel naming, and anonymous account cleanup implemented. | `Push-Location app/extension; npm run compile; npm test; Pop-Location` passed before backend/docs work continued. |
| 2026-06-09 | SQLite runtime branches removed, queue defaults changed to Redis, worker auto-start moved behind `subtitles:dev-workers`, backend duplication reduced, `/extension` route added, dead assets deleted, and docs updated. | `php artisan test --filter="SubtitleJobServiceTest|dev_worker_command_starts_configured_subtitle_workers|new_subtitle_request_uses_configured_subtitle_queue_connection" --compact`; `php artisan test --filter=SaasWebsiteAndSeoTest --compact`; `Push-Location app/extension; npm test -- keyboard-shortcuts; Pop-Location`; targeted `php -l` checks. |
| 2026-06-09 | Full validation passed and live stale-name grep was clean. | `.\scripts\agent\check.ps1`; `rg "popup|feature-connect|feature-memory|feature-tasks|feature-automation|feature-browse|feature-sandbox|sqlite-local|database is locked|subtitle queue database|Local beta" app/backend/app app/backend/config app/backend/routes app/backend/tests app/backend/resources app/extension`. |
| 2026-06-09 | Removed the overlay renderer/type re-export from the overlay shell module. | `Push-Location app/extension; npm test -- overlay; npm run compile; Pop-Location`; final `.\scripts\agent\check.ps1` passed. |

## Completion Notes

- What changed: Fixed extension settings-message handling, split runtime message destinations, renamed active popup vocabulary to panel vocabulary, removed fabricated anonymous quota UI, removed SQLite runtime compatibility, moved worker startup behind an explicit dev command, collapsed repeated backend predicates/resources/job wrappers/version logic, removed the overlay renderer re-export, replaced `/desktop` duplicate content with `/extension`, removed/renamed dead public assets, moved historical root design docs to `docs/history`, and updated live docs.
- Validation results: `.\scripts\agent\check.ps1` passed.
- Simplicity/readability review: Active runtime now has one Redis/Postgres queue path, generation requests no longer spawn processes, and repeated backend concepts have single owners.
- Residual risk: Browser extension end-to-end visual smoke remains the existing release-smoke debt; no provider-backed generation run was required for this cleanup.
- Follow-up debt: None introduced by this cleanup.
