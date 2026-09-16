# Plan: Remediate comprehensive application review

Status: complete
Owner: root agent with bounded billing/auth, provider-controls, and extension specialists
Work mode: standard
Created: 2026-09-15
Last updated: 2026-09-16

## Goal

Revalidate and repair R01–R18 and PT01–PT03 from the unchanged comprehensive review dated 2026-09-15. Preserve the existing uncommitted lyrics changes and record executable evidence, product choices, and remaining environment limitations.

## Scope

- In scope: isolated tests, billing/auth/abuse correctness, pipeline replay/provider privacy, text/UI correctness, diagnostics, targeted advisory updates, obsolete code removal, current-system documentation.
- Out of scope: deployment, commits/pushes, customer data, real email/billing/provider requests, changing refund/deletion/queued-entitlement/lyrics-quality policy without a decision.

## Acceptance Criteria

- [x] All independently actionable confirmed defects repaired with meaningful regressions.
- [x] Test isolation proven before any test/migration; baseline working tree preserved.
- [x] UI fixtures checked in browser; actual extension and production service limitations distinguished.
- [x] Harness, audits, focused checks and combined diff review recorded; no suppressed failures.
- [x] Every finding has a disposition and dated completion evidence.
- [x] R07: confirm generation, debit full reserved minutes for user cancellation/deletion after paid work starts, and refund before paid work; verify race and repeat-cancellation behavior.

## Relevant Context

- Baseline: docs/comprehensive-application-review-2026-09-15.md, main at 3801532, existing dirty lyrics changes and review additions retained.
- Architecture, product index/lyrics, SECURITY, RELIABILITY, FRONTEND, DESIGN, operations, golden principles and earlier review packages govern decisions.
- Skills: Ponytail; Laravel best-practices (specialist read rule files), security, subtitle-pipeline, AI SDK; agent-browser for UI. Boost search-docs absent; isolated boost:list-skills returns no boost namespace. Use local skill files, Context7 and installed source instead.

## Implementation Steps and Finding Ledger

| ID | Dependency | Status / owner | Validation evidence |
| --- | --- | --- | --- |
| R01 | First | Fixed / root | Bootstrap before artisan/PHPUnit; fail-closed config check before providers/migrations. 2 tests / 9 assertions, inherited sentinel unchanged and hostile cached config bypassed. |
| R02 | R01 | Fixed / billing | Basil item periods/invoice parent plus legacy fixtures; invalid periods reject; REST API version pinned. Hosted Stripe validation remains. |
| R03 | R02 | Fixed / billing | Canceled T+300 → checkout T+100 → active T+200 regression; equal timestamps and new subscription identity tested. |
| R04 | R02 | Fixed / billing | Durable checkout intent and fail-closed expiration; PostgreSQL subprocess tests prove real row locking for replacement, completion/webhook and deletion races: 3 / 29. |
| R05 | R01 | Fixed / billing | Real reset request and stale cookie/session replay, including hashless legacy sessions; WebAuthTest: 12 / 105. Forward migration required. |
| R06 | R01 | Fixed / provider | Actual-call permits, deduplicated misses, edit serialization and current-tier checks. Provider/SDK/correction/API tests: 236 / 1,658; Redis subprocess integration: 2 / 9. |
| R07 | R06, product decision | Fixed / provider + extension + root | September 16 policy implemented: full reservation debit after paid dispatch admission, quiet early refund, Generate confirmation and stale-context guards. Accounting: 22 tests / 169 assertions; four PostgreSQL race cases pass; browser checks at 320/360px pass. |
| R08 | R01 | Fixed / billing | Hourly IP/global registration/reset limits and generic reset responses; varied-address/fake-notification regressions. |
| R09 | R01 | Fixed / root | Optimizer overlap lock, stage fences and persisted continuation; advanced replay, missing publication and interleaved cleanup tests. Actual worker-kill matrix remains untested. |
| R10 | R01 | Fixed / provider | Actual installed SDK HTTP 503/500/429/400/401/connection/quota classification; generation/correction 503→success wrappers. |
| R11 | R01 | Fixed / root | Korean/mixed/decomposed spacing, matched-span and fallback Quick Fix paths; Korean/progressive tests: 23 / 203; cache versions v17/v7/v11. |
| R12 | R01 | Fixed / extension | Actual background entrypoint correction refresh and stale account/tab/operation guards tested. |
| R13 | R01 | Fixed / extension | Browser Arabic/Hebrew at 320/360px normal/editable, mixed punctuation, overflow and logical Tab order pass. |
| R14 | R01 | Fixed / extension | Browser preserves 82-character draft, backward selection 3–12, input scroll 120px and panel scroll 110px; search retains focus. |
| R15 | R10 | Fixed / provider | Raw previous causes removed; safe diagnostic allowlist retained; private sentinels absent from ordinary logs and failed_jobs. |
| R16 | R01 | Fixed / root | 30 running jobs vs 25 display rows; database and native Redis ready/delayed/reserved counts tested. |
| R17 | R10 regression | Fixed / root | Targeted Composer/contracts/WXT/Vitest/transitive updates; full/runtime Composer and full/production npm audits clean. |
| R18 | All changes | Fixed / root | Current provider/card workflow, truthful verification, admitted-job policy and rollout docs reconciled; original review retained. |
| PT01 | R09 | Fixed / root | Caller search, four dead logger methods and unused failIncompleteState removed; active logs preserved. |
| PT02 | Provider coordination | Fixed / root | Unused dialect prompt/schema/DTO/artifact output removed; spoken-word policy and cue contracts retained. |
| PT03 | R12–14 | Fixed / extension | Unused stageTimeline builder/type/constant removed; live activity/progress and lyrics checklist retained. |

## Validation Plan

Use php artisan test (now bootstrap-isolated), focused fake provider/HTTP/mail tests, browser fixtures, scripts/agent/check.ps1, Composer runtime/full audits and npm audits/builds. No existing DB destructive reproduction. Test PostgreSQL/Redis boundaries only in disposable local services if available; otherwise name the missing proof explicitly.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-15 | Force test services before Laravel starts; isolated storage/config cache; validate loaded config before migration traits. | PHPUnit env force alone cannot protect parent Artisan/config cache. |
| 2026-09-15 | Preserve existing policy for already-admitted jobs, unverified accounts, full refund and loose lyrics checks. | Remediation fixes defects; documentation describes implemented behavior. R07 allowance question is pending. |
| 2026-09-15 | Use disposable PostgreSQL 17 and Redis 7 containers with loopback ports for concurrency evidence. | SQLite and array cache cannot prove production row-lock or shared-permit behavior. |
| 2026-09-15 | Keep this plan active for R07; finish every independent fix. | No answer authorizes a daily work allowance or partial-delivery debit; either choice changes observable cancellation policy. |
| 2026-09-16 | User approved full reserved-minute debit for voluntary cancellation after paid provider work begins, with a confirmation before Generate. Refund cancellation before paid work without advertising the exception. | Closes repeated costly cancellations while returning minutes when no paid transcription/analysis has begun. Failures retain their existing refund policy. |
| 2026-09-16 | Use a durable per-run marker at paid provider admission, serialized with cancellation; include voluntary running-job deletion. | A displayed stage cannot prove whether a provider request began, and deletion must not bypass cancellation settlement. No new daily allowance or account-deletion retention. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-15 | Read report, guidance and current dirty tree; created plan via new-plan.ps1. Assigned bounded owners. | No baseline reset or commit. |
| 2026-09-15 | R01 isolation ready; released specialists to run focused tests. | TestEnvironmentIsolationTest: 2 pass / 9 assertions. |
| 2026-09-15 | Combined review found and repaired Windows cache-path coverage, optimizer cleanup, checkout deletion/new-subscription ordering and stale-tier edge cases. | Focused regressions and real PostgreSQL/Redis interleavings pass. |
| 2026-09-15 | Verified UI with the actual source and fake browser transport. | [Browser evidence](../../review-evidence/2026-09-15-remediation/extension-evidence.md); 105 focused extension tests pass. |
| 2026-09-15 | Updated only affected dependencies and current-system docs; preserved all earlier dirty work. | Composer/npm audits clean; no audit exceptions, commit, deployment or live service use. |
| 2026-09-15 | Fixed strict indexed-access/override errors exposed by regenerated WXT config without weakening checks; reran full harness. | Contracts, 656 backend tests / 5,201 assertions (all five service integration tests enabled), 310 extension tests, TypeScript and 606.09 kB Chrome build pass. Compile also passes after build. |
| 2026-09-15 | Final formatting, diff and dependency checks pass. | Pint; git diff --check; Composer full/runtime and npm extension full/production/contracts audits all clean. |
| 2026-09-16 | Resumed R07 implementation after the product decision. Backend and extension specialists own separate files; billing specialist reviews accounting/locking; root updates product/website copy and integration evidence. | Laravel best-practices/security/subtitle skills, Context7 Laravel 13 transaction/locking guidance; existing dirty work retained. |
| 2026-09-16 | R07 accounting and original repeated-cancellation sequence are covered. | 22 tests / 169 assertions; original billing period, paid Scribe/upload/analysis, cached transcript, saturation, retry reset, failure refunds, idempotency and balance exhaustion after deletion. |
| 2026-09-16 | Confirmed both race outcomes with separate PostgreSQL workers and observed lock waits. | Cancel/delete first: refund and no HTTP. Paid marker first: full debit during in-flight HTTP, with no transaction held across the call. Four PG cases plus Redis checks: 6 / 63. Independent review found no confirmed defects. |
| 2026-09-16 | Native confirmation and context guards pass browser checks at 320/360px. | [Browser evidence](../../review-evidence/2026-09-16-generation-confirmation/README.md); focus, Escape, decline, busy/retry, Generate again and stale account/video/settings/duration checks. |
| 2026-09-16 | Final full harness and formatting pass; stale dashboard refund-copy assertion was updated to the approved policy. | 682 backend tests / 5,426 assertions, including all nine service integration tests; 333 extension tests / 32 files; contracts, TypeScript and 609.85 kB Chrome build. Pint and diff check pass. |

## Completion Notes

All R01–R18 and PT01–PT03 findings are implemented with the evidence and remaining environment limits in the [completion report](../../comprehensive-application-remediation-2026-09-15.md). R07 now follows the September 16 user decision and the full harness passes. Apply its marker migration and coordinated confirmation/website rollout after draining old queued/running generation work. No live provider calls, customer data access or deployment occurred. The original review remains the baseline. After implementation, the user requested commits and a remote push; remediation is grouped on `codex/comprehensive-review-fixes`, with the earlier lyrics-validation changes kept local and uncommitted.
