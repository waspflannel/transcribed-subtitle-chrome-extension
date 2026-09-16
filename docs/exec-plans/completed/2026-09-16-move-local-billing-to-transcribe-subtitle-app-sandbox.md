# Plan: Move local billing to Transcribe subtitle app sandbox

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Move local billing to the user-selected Transcribe-subtitle-app Stripe sandbox and make subscription management work through the hosted portal with signed webhook updates.

## Scope

- Configure Base, Plus and Pro monthly USD plans, portal and local webhook forwarding.
- Store credentials and object IDs only in the ignored backend environment file.
- Replace stale billing references to the old sandbox; preserve accounts, jobs, tokens and usage history.
- No live payments, changes to the previous Stripe account, dependency upgrades or billing API version migration.

## Acceptance Criteria

- [x] Backend authenticates account `acct_1UGAY62fCqu33Jv3` (Transcribe-subtitle-app).
- [x] Prices match Base $9/90 minutes, Plus $19/240 minutes and Pro $39/600 minutes.
- [x] Portal allows cancellation at period end and switching the three prices, without quantity changes.
- [x] Signed sandbox webhooks update local subscription state.
- [x] Local account has no old-sandbox billing references.
- [x] Hosted controls and repository checks are verified.

## Relevant Context

- Architecture: `ARCHITECTURE.md`.
- Operations: `docs/operations/production-hosting-and-ops.md`.
- Quality rules: `docs/quality/golden-principles.md`.
- Previous local Pro record was active while its remote subscription was canceled; no webhook receipts existed.
- Stripe MCP now exposes Transcribe-subtitle-app as well as the previous sandbox. Always select `acct_1UGAY62fCqu33Jv3` with `livemode=false` for this app's local billing work.
- Laravel best-practices/security and Stripe billing/security skills apply. Preserve the repository's pinned `2025-03-31.basil` API contract.

## Implementation Steps

- [x] Verify credentials and back up the ignored environment file.
- [x] Provision catalog and portal.
- [x] Start a local listener with no secrets in process arguments or logs.
- [x] Reconcile only billing references belonging to the previous sandbox.
- [x] Validate hosted billing and webhook delivery.
- [x] Run checks and record final state.

## Validation Plan

```powershell
.\scripts\agent\check.ps1
```

Also verify account identity, exact prices, hosted portal controls, signed webhook receipts and matching local state. Never record keys, portal session tokens or full provider payloads here.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Use only the selected sandbox for new operations. | User corrected account selection and authorized local credential replacement. |
| 2026-09-16 | Use supplied key while MCP reconnection is pending. | Key verifies the correct account independently. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Verified new sandbox; catalog and customers are empty. | Stripe API reads. |
| 2026-09-16 | Created three products/prices and explicit portal configuration; updated ignored environment. | New account only; $9/$19/$39 USD monthly. |
| 2026-09-16 | Added a hidden local Stripe listener and startup integration. | Initial launch, restart and idempotent launch verified; signing secret redacted from logs. |
| 2026-09-16 | Backed up old billing identity and completed a new Pro hosted test checkout. | Both checkout and subscription-created webhooks returned HTTP 200. |
| 2026-09-16 | Tested Pro to Plus to Pro, period-end cancellation and undo through the hosted portal. | Four subscription-updated events returned HTTP 200 and updated the local account. |
| 2026-09-16 | Repository check passed. | 673 backend tests passed, 9 skipped; 333 extension tests passed; contracts, TypeScript and build passed. |
| 2026-09-16 | User authorized the new sandbox for MCP; account discovery confirms access. | Transcribe-subtitle-app is listed with the expected account ID and test mode. |

## Completion Notes

- Local account is active Pro in the new sandbox, renews October 16, 2026, with no cancellation scheduled. Hosted Update subscription and Cancel subscription controls are visible.
- Six signed webhook receipts succeeded, none failed. The new period has exactly one monthly grant totaling 600 minutes after all plan-change tests.
- Old environment and billing identity backups are ignored local files. Account, jobs, tokens and historical usage were preserved. No old-sandbox or live Stripe objects were changed.
- No application billing logic or dependencies changed. The PowerShell launcher passes parsing, live start/restart/idempotency and secret-log checks; `git diff --check` passed.
- Stripe CLI uses this sandbox's default `2023-10-16` event version. The existing handler's legacy path was verified; REST remains pinned to Basil. Pin the public webhook endpoint to Basil when deploying.
- MCP access to the selected sandbox is authorized. Initial setup and hosted-flow verification used the authorized new sandbox key.
- After key rotation, replace the backend environment key and run `scripts/runtime/start-local-stripe.ps1 -Restart`. Local startup restarts forwarding after a reboot.
