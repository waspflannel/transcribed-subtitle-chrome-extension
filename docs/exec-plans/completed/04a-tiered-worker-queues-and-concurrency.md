# Phase 04a: Tiered Worker Queues And Account Concurrency

Status: completed
Owner: agent
Created: 2026-05-22
Last updated: 2026-05-22

## Goal

After authenticated accounts and billing entitlements exist, replace the current single tier-aware subtitle queue plus per-install limiter with an account-owned worker model that separates generation orchestration from parallel AI batch work.

The implementation should keep workers shared by tier and work type, not reserved per user, while giving each plan clear generation and batch-concurrency guarantees.

## Scope

- In scope:
  - Tier/work-type Redis queues for generation orchestration and AI batch work.
  - Account-level active generation admission limits.
  - Account-level AI batch concurrency limits.
  - Shared priority worker groups plus small base-tier guarantee pools.
  - Runtime diagnostics and sanitized trace context for queue family, tier, limits, active counts, and delay/rejection reasons.
- Out of scope:
  - Per-user worker processes or reserved per-user pods.
  - Kubernetes manifests or autoscaling rules as part of the first implementation.
  - A new public queued status for over-limit generation requests.
  - Team, school, organization, or enterprise concurrency policy.

## Acceptance Criteria

- [x] Subtitle jobs use authenticated `user_id` as the concurrency owner; `install_id` remains only for device and abuse diagnostics.
- [x] Generate requests are rejected with `429 concurrency_exceeded` when the account is already at its active generation limit.
- [x] Reusing an already-running compatible job does not consume another generation slot.
- [x] Completed and failed jobs no longer count against active generation concurrency.
- [x] Generation/orchestration jobs route to `subtitle-generation-{tier}` queues.
- [x] AI batch jobs route to `subtitle-batch-{tier}` queues.
- [x] AI batch middleware limits active batch work per account and tier, then releases delayed jobs back to Redis.
- [x] Runtime diagnostics show queue depths and worker group config for all generation and batch queues.
- [x] Concurrency logs and trace rows are sanitized and never expose raw user IDs, install IDs, transcript text, cue text, prompts, provider payloads, or secrets.

## Key Implementation Areas

- Queue families:
  - Add generation queues: `subtitle-generation-ultimate`, `subtitle-generation-pro`, `subtitle-generation-plus`, `subtitle-generation-base`.
  - Add batch queues: `subtitle-batch-ultimate`, `subtitle-batch-pro`, `subtitle-batch-plus`, `subtitle-batch-base`.
  - Route `ProcessSubtitleJob`, analysis continuation, romanization merge, and finalization to generation queues.
  - Route tokenization, translation, romanization, and enrichment batch jobs to batch queues.
- Tier limits:
  - `base`: 1 active generation, 3 active AI batches.
  - `plus`: 2 active generations, 8 active AI batches.
  - `pro`: 3 active generations, 14 active AI batches.
  - `ultimate`: 5 active generations, 20 active AI batches.
  - Keep these values in Laravel config with environment overrides.
- Limiters:
  - Replace the single `per_install_concurrency` limiter with separate generation admission and AI batch limiters.
  - Scope Redis limiter keys by hashed `user_id`, generation tier, and limiter type.
  - Keep sync queue behavior unblocked in tests.
- Worker groups:
  - `generation-priority`: listens to all generation tier queues in premium-to-base order; default 4 workers.
  - `batch-priority`: listens to all batch tier queues in premium-to-base order; default 20 workers.
  - `base-generation-guarantee`: listens only to `subtitle-generation-base`; default 1 worker.
  - `base-batch-guarantee`: listens only to `subtitle-batch-base`; default 2 workers.
  - Update local auto-start to start worker groups; production can run equivalent Supervisor, Docker, or Kubernetes workers later.

## Interface And Contract Notes

- No Generate request payload changes.
- Standardize public `concurrency_exceeded` API errors for generation admission rejection.
- Existing job polling remains unchanged; do not add a new `queued` job status in this phase.
- Add config keys for per-tier generation queues, batch queues, generation concurrency, batch concurrency, and worker groups.
- Update runtime commands to report generation and batch queue family depths separately.

## Required Product Decisions

- Whether the first beta plan names map exactly to `base`, `plus`, `pro`, and `ultimate`.
- Whether base guarantee workers are enough for beta or whether plus/pro also need guaranteed pools after load evidence exists.
- Whether full word-card enrichment should use the same AI batch limit as tokenization/translation or a stricter future sub-limit.

## Validation/Evidence Required

- Feature tests for generation admission rejection, compatible running-job reuse, and completed/failed jobs no longer counting against active generation limits.
- Queue routing tests for generation jobs, continuation/finalization jobs, and AI batch jobs.
- Middleware tests for account/tier batch limits, release behavior, and sync driver bypass.
- Runtime command tests for all queue-family depths and worker group summaries.
- Sanitization assertions for concurrency trace context.
- `.\scripts\agent\check.ps1`
- `.\scripts\agent\verify-pr.ps1`

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-22 | Implement this phase after authenticated account ownership exists. | The concurrency owner must be `user_id`; implementing against anonymous installs would force a second migration. |
| 2026-05-22 | Use shared worker pools, not per-user workers. | Per-user workers waste capacity and do not scale with idle accounts. |
| 2026-05-22 | Use separate generation and AI batch limits. | Whole-video admission and provider-heavy parallel batch work have different cost and fairness constraints. |
| 2026-05-22 | Reject over-limit Generate requests instead of adding queued status. | This avoids adding new public lifecycle states before beta evidence proves queued overflow is needed. |
| 2026-05-22 | Keep Kubernetes out of the first implementation. | Laravel queues, Redis locks, and worker config should own business fairness; Kubernetes can later run and scale worker pods without changing policy. |
| 2026-05-22 | Keep the public beta billing catalog at `base`, `plus`, and `pro`; keep `ultimate` as an internal queue tier until a product/pricing decision adds it publicly. | Current billing configuration exposes only three paid plans, while queue policy needs the ultimate tier ready for future entitlement mapping. |
| 2026-05-22 | Use only the new `SUBTITLE_GENERATION_QUEUE_*` and `SUBTITLE_BATCH_QUEUE_*` names for tier routing. | Legacy `SUBTITLE_QUEUE_*` values in local environments would otherwise keep jobs on the old `subtitle-ai` family and fail the phase goal. |
| 2026-05-22 | Let full word-card enrichment share the account/tier AI batch limiter with tokenization, translation, and romanization. | The first beta needs one inspectable AI batch fairness policy; stricter enrichment sub-limits require load and cost evidence. |
| 2026-05-22 | Base generation and base batch guarantee pools are enough for the first implementation. | Higher-tier guarantee pools can be added later from runtime queue-depth and wait evidence without changing admission policy. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-22 | Plan created from Plan C worker allocation discussion. | Roadmap document added for post-auth implementation. |
| 2026-05-22 | Refined implementation against the current Laravel backend, local Boost skills, and Laravel 13 queue/cache docs. | Loaded `laravel-best-practices`, `laravel-specialist`, `laravel-security`, `subtitle-pipeline`; fetched Laravel 13 queue/cache docs for queue names, middleware, releases, and cache locks; baseline `.\scripts\agent\check.ps1` passed. |
| 2026-05-22 | Implemented explicit generation and AI batch queue families, tier concurrency config, account-owned generation admission, account-owned batch limiter, worker groups, runtime diagnostics, and docs updates. | Focused backend tests passed: `php artisan test --compact tests/Feature/SubtitleRuntimeTracingTest.php tests/Feature/SubtitleJobApiTest.php tests/Feature/BillingAndUsageTest.php tests/Unit/SubtitleRuntimeTracerTest.php tests/Unit/SubtitleWorkflowLoggerTest.php` (85 passed, 553 assertions). |
| 2026-05-22 | Completed validation and documentation lifecycle checks. | `vendor/bin/pint --dirty --format agent` passed; `php artisan test --compact tests/Feature/SubtitleJobApiTest.php tests/Feature/SubtitleRuntimeTracingTest.php tests/Feature/BillingAndUsageTest.php` passed (77 passed, 535 assertions); `.\scripts\agent\doc-gardening.ps1` reported no findings; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed. |
| 2026-05-22 | Completed the final code-simplifier pass and removed stale single-queue compatibility helpers from the tiered queue services. | `vendor\bin\pint --dirty --format agent` passed; focused backend queue/billing/tracing tests passed (88 passed, 585 assertions); `.\scripts\agent\check.ps1` passed; `git diff --check` passed. |

## Completion Notes

- Implemented tiered generation queues, AI batch queues, per-tier generation and batch concurrency config, account-owned generation admission, Redis-backed account/tier AI batch limiting, shared priority/base-guarantee worker groups, and runtime queue-family diagnostics.
- Removed obsolete single-queue helper aliases after the split to explicit generation and batch queue APIs.
- Updated architecture, reliability, security, observability, quality, runtime README, local runtime env script, production hosting plan, and technical-debt tracker to reflect the new queue model.
- Residual risk: provider throughput is still bounded by global worker counts and provider rate limits; existing TD-009/TD-010 keep real provider-backed timing and load evidence open.

## Risks and Follow-up Debt

- Provider cost can still spike if global worker counts exceed provider throughput, even when per-account limits are correct.
- Base guarantee pools protect against starvation but do not promise immediate starts under heavy total load.
- The first implementation should preserve current queue trace legibility while introducing more queue names and limiter types.
- Load-test evidence may later justify different tier limits or dedicated plus/pro guarantee pools.
