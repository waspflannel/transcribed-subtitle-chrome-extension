# Plan: Implement Ponytail application review

Status: code committed and validated; user computer testing pending
Owner: agent
Work mode: standard; Astra implementers and reviewers
Created: 2026-09-12
Last updated: 2026-09-12

## Goal and scope

Implement the confirmed fixes and cleanup from `docs/ponytail-application-review-2026-09-12.md` on `codex/ponytail-review-fixes`. Evaluate the report's profiling candidates and implement supported narrow improvements. Preserve functionality, language fidelity, billing, ownership/run fencing, accessibility and temporary-audio cleanup. No paid provider calls, production migrations or deployment. User requested a branch; prepare local reviewable commits without assuming a remote push or PR.

## Acceptance criteria

- [x] Every C1–C6, F1–F11 and E1–E5 has an explicit implementation or evidence-based disposition.
- [x] Confirmed failures have meaningful focused regressions.
- [x] Full repository harness passes after integration and Astra review.
- [x] Required docs, measured evidence and rollout limits are updated.
- [x] Changes are grouped into reviewable commits on the requested branch.

## Work ownership

| Worker | Findings / files |
| --- | --- |
| Astra generation | C3, F3, F4, F5, E1; pipeline, chunk jobs, providers, evaluation and focused tests |
| Astra extension | C2, C4, F1, F2, F8, E2, E3, E5; extension runtime, assets and focused tests |
| Astra support | C1, C5, C6, F6, F9, F11, E4; contracts, dashboard/billing models/ledger, website and tests |
| Root | F7 retention, F10 preview reuse; integration, independent reviews, docs and grouped commits |

## Validation

Run focused tests while editing; root runs `.\scripts\agent\check.ps1` after integration. On resumption the user explicitly limited validation to code tests and reserved computer/browser testing for themselves. Do not run browser automation, live generation or runtime migrations for this handoff. Do not claim provider speed or linguistic-quality gains without matched provider-backed evidence.

## Commit plan

1. `0fb3506` — Simplify contracts and account website reads.
2. `1ec966d` — Preserve subtitle work across retries and reuse progress previews. Retention and generation changes share API regressions, so they are committed together.
3. `2fc51cb` — Fix saved subtitle recovery and reduce extension refresh work.
4. Record the audit, implementation handoff, current behavior, validation and deferred measurements in a documentation commit.

Stage exact files per group and inspect staged diffs. Do not stage unrelated files or environment data. Final grouping may combine coupled changes where that makes each commit independently valid.

## Decision log

- 2026-09-12: User explicitly requires Astra for code changes and reviewers. Three implementers were spawned with `model: gpt-6-astra`; root is also implementing.
- 2026-09-12: Apply Ponytail and Git Group Commits, plus repository Laravel best-practices/subtitle/AI skills. Context7 Laravel 13 and local installed framework source replace unavailable Boost documentation tools. Generic interface/Action/Horizon advice does not justify new architecture.
- 2026-09-12: F10 uses a run-scoped lightweight preview artifact, invalidated atomically by draft/analysis writes, with lazy materialization. Existing artifact cleanup also removes it; no new cache service or infrastructure.
- 2026-09-12: Resumed in OpenCode/Astra from Codex session `01a09458-0f81-7760-8503-5debb5d763eb`. Recovered worker reports from local session records and checked them against the working tree. The original root and unfinished reviewer stopped with usage-limit errors; the work does not depend on those sessions resuming.
- 2026-09-12: Honored the user's code-tests-only instruction. The first handoff left the patch uncommitted. The user subsequently explicitly authorized committing the branch changes and pushing the branch to the remote; no PR or deployment was requested.
- 2026-09-12: Use the existing video-filtered history as the readable completed-track source; global History can include obsolete processing versions. Use animation frames for search coalescing because microtasks do not batch separate input events across tasks.

## Progress

- Branch created from main at dcf6dc29fafbfcf4345025388167539bfea8aca4, carrying the earlier audit documentation only.
- Parallel implementation started. Baseline from audit: 531 backend tests, 242 extension tests, contracts/typecheck/build passed; extension 879,754 bytes.
- Recovered generation worker Plato (`01a09463-9fee-7ae0-9f95-0273ea563384`): C3/F3/F4/F5 implemented; E1 measured and deferred. Initial focused validation reported 160 tests / 1,117 assertions. Its watchdog follow-up wrote the run-fenced heartbeat and passed 22 tests / 147 assertions before the usage limit prevented a final response.
- Recovered extension worker Kierkegaard (`01a09463-c502-7ae3-b74a-95105a5bdbd2`): initial extension patch complete; reported 250 tests, compile and build passed. Its report measured 605,136 raw build bytes / 29 files. Subsequent integration changes are covered by the fresh results below.
- Recovered support worker Carson (`01a09463-e441-71e2-aed2-043ef3bd9dbb`): contracts, billing, website and delegated retention migration/tests complete. Reported 53 billing/site tests / 380 assertions, three retention tests / 32 assertions and two cancellation tests / 32 assertions.
- Recovered backend reviewer Poincare (`01a09468-659a-7a03-8dda-555f83646e78`): identified the retry/watchdog conflict, now covered by the heartbeat regression. No further actionable findings were reported in retention, previews, enrichment idempotency, billing or scoring.
- Extension reviewer Nash (`01a09469-16b7-73d3-ba02-5d813c3f0e2f`) stopped without a final report. The root's last public update identified obsolete history hiding readable saved tracks. The resumed Astra pass traced recovery, polling, lyrics publication, content timing and search rendering and completed the repairs below.
- Initial fresh harness found 545 backend tests passing and one outdated `maxExceptions=1` assertion. Updated the test to group transcription with other retryable provider jobs; production retry limits were retained.
- Finished F2 recovery for obsolete completed-history entries in both content and panel entrypoints. Added a local-only refresh regression for F8. Fixed F1 completion ownership to compare job/attempt identity rather than response-object identity, with repeated/superseding response checks. Changed E3 search scheduling from microtasks to animation frames and updated its regression.

## Finding disposition

| Findings | Disposition and evidence |
| --- | --- |
| C1 | Implemented referenced-type generation settings; worker verified 36 declarations became 21 without changing unique declaration contents. Contract build and extension compilation pass. |
| C2 | Implemented WOFF2-only panel faces and removed unused Geist 500; used subsets/weights remain. Production build passes with 29 files and about 605.32 kB, versus the audit's 43 files / 879.75 kB. Visual acceptance is user-owned. |
| C3, F4 | Inlined enrichment flow; per-run/batch overlap protection, artifact reuse and atomic result/cost/completion writes. API tests exercise redelivery, overlap contention and rollback. |
| C4 | Removed obsolete overlay input-selection state, unused token-click type and History `publicJobId` field/formatter; ordinary focus restoration remains. Extension tests pass. |
| C5 | Removed the unused dashboard track eager load; website tests pass. |
| C6 | Removed unused billing model fields and added forward migration `2026_09_12_065327_drop_unused_billing_dates_from_users_table.php`. Test migrations pass; runtime migration is pending. |
| F1 | Publish newly accepted lyrics completions once; cached terminal refreshes preserve loaded cards. Completion ownership is job/attempt-scoped. Delayed-card and repeated/superseding completion regressions pass. |
| F2 | Video-filtered readable history recovers tracks outside both caches and bypasses obsolete completed global entries. Content/panel regressions pass with 25 global jobs and five remembered tracks. |
| F3 | Bounded transient chunk retries preserve completed work; permanent/exhausted failures clean up. Retry-start heartbeat protects valid slow attempts without extending stale or completed deliveries. Provider, pipeline and watchdog tests pass. |
| F5 | Word precision counts all lexical predictions and reports unlocatable predictions. Extra, wholly invented and out-of-order token regressions pass; production wording acceptance remains unchanged. |
| F6 | Deleted the obsolete history `required` assignment. The contract check now runs an isolated language-sync regression preserving unrelated schema data. |
| F7 | Failed/cancelled jobs expire after 30 days, with null-expiry backfill and daily queue-metadata pruning. Tests cover active-job preservation, trace cleanup and retained ledger records. |
| F8 | Active monitors own status polling; local-only refreshes do not reload completed tracks. One-minute request-count and monitor-free persisted-operation recovery regressions pass. |
| F9 | Replaced four ledger sums with one aggregate and removed unused `granted`. Billing arithmetic, period/account scoping and query-count regressions pass. |
| F10 | Persist/reuse a lightweight run-scoped preview, invalidated atomically by draft/analysis writes. Unchanged assembly performs one payload query; tests cover out-of-order updates, rollback, run replacement and cleanup. Full preview response bytes and production latency remain unmeasured. |
| F11 | Marketing now describes card lookup and retained generated-track history. Website assertions pass. |
| E1 | Deferred. The generation worker reported synthetic FFmpeg preparation measurements, but these do not establish end-to-end benefits or timing/text parity on representative M4A/WebM speech. Opening-first preparation changes queue completion and cleanup accounting; retain the current path until a bounded comparison supports that work. |
| E2 | Narrow change implemented: per-frame positioning, reused player/geometry and no content rebuild during positioning. Code tests cover scroll bursts and hidden overlays; real scrolling performance remains user-owned. |
| E3 | Narrow change implemented: cached search text, small render revisions and animation-frame input coalescing. Tests cover metadata and source-text invalidation. Long-transcript responsiveness remains user-owned; virtualization is deferred. |
| E4 | Ordered, individually versioned CSS links replace the import chain. Code tests check link order and child-version changes. Prior worker reported local browser evidence; no computer testing was repeated on resumption, and production headers remain unverified. |
| E5 | Annotation-only updates preserve native timing bindings and use current annotations in callbacks. Binding regressions pass; visible flicker and playback continuity require the user's real-browser test. |

## Fresh integration validation

- `.\scripts\agent\check.ps1` passed: documentation lint, contract maintenance/schema/type generation, **546 backend tests / 4,334 assertions**, **255 extension tests across 32 files**, TypeScript compile and production Chrome build.
- Focused `SubtitleRuntimeTracingTest.php`: 25 tests / 128 assertions passed. Focused background, lyrics, transcript and content tests passed before the full run.
- `php vendor/bin/pint --dirty --format agent` applied repository PHP formatting; the full harness above ran afterward.
- `git diff --check` passed. The build reports approximately **605.32 kB**; this is uncompressed package size, not a provider or playback speed measurement.
- Updated reliability, frontend, design, schema, quality and debt records. The dated audit remains the pre-fix evidence source.

## Rollout and user testing

- Two forward migrations are present: billing-field retirement and terminal-expiry backfill. Only test databases were migrated during this resumption. The backfill's `down()` intentionally leaves diagnostic deadlines in place because pruning cannot be undone; recreating retired columns cannot restore old values.
- Use the normal release procedure: finish/drain active work, apply migrations, refresh cached configuration, restart workers and reload the extension. The preview uses existing artifact storage and requires no new infrastructure.
- User owns computer/browser checks: older saved-video recovery, lyrics replacement followed by card lookup and Study changes, monitor restart recovery, long transcript search/Quick fix, visible/hidden overlay scrolling, fonts and partial-subtitle continuity.
- E1 and production/provider/visual measurements remain open. No paid provider call, browser run, runtime cleanup or deployment was performed by the resumed session.

## Completion

Code implementation, integration review and automated checks are complete. Application changes are committed in the three groups above, based on `dcf6dc2`; the audit and handoff documentation are included with this record. The authorized remote delivery target is `origin/codex/ponytail-review-fixes`. Keep this plan active for user computer-test results; E1 and runtime rollout remain pending.
