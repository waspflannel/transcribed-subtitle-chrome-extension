# Phase 01: Generation Optimization

Status: in_progress
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Goal

Make subtitle generation faster, cheaper, and more predictable before the SaaS surface drives paid usage.

This phase should start from the existing Laravel queue pipeline, Postgres/Redis runtime, sanitized trace rows, and current OpenAI/ElevenLabs workflow. It should improve the active path with evidence rather than adding speculative provider abstractions.

## Scope

- In scope:
  - Baseline timing and cost measurement from real provider-backed runs.
  - Queue worker, batch size, concurrency, and tier-priority tuning.
  - Account-aware completed-track and in-flight job reuse.
  - Safer caching for token/card work where privacy and ownership boundaries allow it.
  - Model and prompt routing for speed, cost, and quality.
  - Performance budgets by tier and video length.
- Out of scope:
  - New user account implementation.
  - Billing implementation.
  - Public launch scaling work.
  - Provider failover unless measured failures prove it is required.
  - New platforms beyond public YouTube watch pages.

## Acceptance Criteria

- [ ] A provider-backed baseline exists for short, medium, and near-limit videos using Postgres + Redis workers. Short local traces exist in `subtitles:metrics --json`; medium and near-limit credentialed runs remain tracked as `TD-010`.
- [x] Trace evidence separates queue wait, audio acquisition, transcription, tokenization, translation, romanization, enrichment, and finalization.
- [x] Default tier and higher-tier performance budgets are documented and measurable.
- [x] Duplicate compatible requests reuse completed or running work without duplicate provider calls.
- [x] Queue priority and concurrency rules support faster higher-tier jobs without starving base-tier users.
- [x] Full word-card mode has clear cost and speed behavior before it is exposed as a paid feature.
- [x] Prompt/model changes preserve contract-valid output and existing quality gates.
- [x] Provider spend per generated minute is recorded internally for later pricing validation.

## Key Implementation Areas

- Measurement:
  - Use `subtitle_job_events`, structured logs, and runtime CLI commands to establish current p50/p95 timing.
  - Add internal provider cost fields only if they are needed for billing-margin decisions.
- Caching and reuse:
  - Reuse completed tracks per authenticated account and compatible request shape.
  - Reuse in-flight compatible jobs.
  - Evaluate cross-account public-video caching only after privacy, retention, and product ownership rules are explicit.
- Queue priority:
  - Add tier-aware queue routing or priority scoring with a simple current path.
  - Keep worker counts and provider rate limits configurable.
  - Prevent one user from occupying all workers through per-user concurrency caps.
- Model routing:
  - Keep tokenizer, translation, romanization, and enrichment outputs schema validated.
  - Use cheaper or faster models only where quality evidence is acceptable.
  - Avoid hidden fallback chains unless real provider failures justify them.
- User-facing progress:
  - Convert trace data into public-safe stage timing and queue state for later UI phases.

## Required Product Decisions

- Target budgets for beta are internal telemetry gates, not user-visible promises: base aims for short/medium/near-limit p95 completion within 4/10/30 minutes, plus within 3/7/22 minutes, and pro within 2/5/15 minutes.
- Higher tiers get separate queue names and higher per-install concurrency. Worker counts stay operational config; local generate requests auto-start workers for the ordered queue list, while production can still use supervised priority workers without Horizon in this phase.
- Full word-card mode remains available but is treated as a higher-cost path through separate processing versions, separate cost estimates, and separate timing metrics before billing decides tier access or minute weighting.
- Completed-track and in-flight reuse remains scoped to the current anonymous install/account boundary. Cross-account public-video caching is explicitly deferred until accounts, retention, and ownership rules are written.
- Cheaper/faster model routing may use configured model names only when existing structured-output validators still pass. This phase does not add hidden provider failover or bypass quality gates.

## Implementation Slices

1. Add internal generation tier metadata, tier queue configuration, per-install concurrency caps, performance budgets, and provider cost fields without changing extension-facing request contracts.
2. Route initial, batch, continuation, and finalization jobs through tier-aware queue names while keeping duplicate request reuse scoped to compatible content and install/account ownership.
3. Persist sanitized cost and budget telemetry from transcription and AI cue-batch stages, then expose local metrics/runtime commands for p50/p95 timing, queue wait, budget status, and cost per generated minute.
4. Update reliability, observability, security, and architecture docs with the new operational path and add focused tests for queue routing, concurrency middleware, reuse, budget telemetry, and metrics output.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-20 | Do not expose tier selection through the anonymous extension API. | Public clients could spoof tier headers before Phase 03 authentication; the backend stores a default internal tier that future account auth can set server-side. |
| 2026-05-20 | Use named Redis/database queues for priority instead of Horizon-specific configuration. | The current runtime already uses Laravel queues and explicit workers; named queues are inspectable, testable, and do not add production infrastructure. |
| 2026-05-20 | Use install-scoped concurrency until accounts exist. | The current ownership boundary is the extension install ID; Phase 03 can replace the key with authenticated account identity without changing queue middleware behavior. |
| 2026-05-20 | Record estimated provider cost from configurable unit prices and safe units only. | Provider usage payloads and transcripts must not be stored in traces; configurable estimates provide margin evidence without leaking sensitive content. |
| 2026-05-20 | Skip concurrency caps for the Laravel sync queue driver. | Sync is a test/local inline path where nested jobs run before the parent job releases its slot; database/Redis workers still enforce caps. |
| 2026-05-20 | Restore local worker auto-start on generate. | Local product usage expects clicking Generate to progress without a separate queue-worker command; production can disable auto-start and use supervised workers. |
| 2026-05-20 | Concurrency-limited subtitle work uses unlimited release attempts with capped exceptions. | Install concurrency middleware releases jobs as a normal delay signal; worker or job-level `tries=1` turns those delays into `MaxAttemptsExceededException` failures before the delayed work can run, while `maxExceptions=1` preserves hard-failure behavior for real exceptions. |
| 2026-05-20 | Add local `ultimate` tier for maximum browser-run parallelism testing. | The tier uses queue `subtitle-ai-ultimate`, per-install concurrency 20, and 20 local auto-workers so one install can exercise the parallel batch workflow without changing extension contracts. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-20 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed: docs lint, contracts, Laravel tests, WXT tests, TypeScript compile, and WXT build. |
| 2026-05-20 | Added internal generation tiers, tier queue routing, per-install queue concurrency caps, performance budget events, provider cost estimates, and generation metrics. | `php artisan test --compact tests/Feature/SubtitleJobApiTest.php tests/Feature/SubtitleRuntimeTracingTest.php` passed: 57 tests, 416 assertions. |
| 2026-05-20 | Confirmed local runtime profile and existing short-job metrics. | `php artisan subtitles:runtime-check --json --strict` passed; `php artisan subtitles:metrics --json` reported 7 completed short/base jobs, p50 37248 ms, p95 46417 ms, p95 queue wait 10490 ms, 0 budget misses. |
| 2026-05-20 | Applied new local Postgres migrations. | `php artisan migrate --force` ran `2026_05_18_230000_require_subtitle_job_run_id` and `2026_05_20_034043_add_generation_optimization_fields_to_subtitle_jobs_table`. |
| 2026-05-20 | Full harness and PR-readiness validation passed after implementation. | `.\scripts\agent\check.ps1` passed; `.\scripts\agent\doc-gardening.ps1` found no issues; `.\scripts\agent\verify-pr.ps1` passed. |
| 2026-05-20 | Restored local worker auto-start after runtime diagnosis found pending Redis work with no worker process. | `SubtitleQueueWorkerBootstrapper` now starts configured queue workers from generate requests and logs worker lifecycle metadata. |
| 2026-05-20 | Fixed retry exhaustion after a live generated job failed in queued translation batches. | Job `91b0948e-16e2-4d98-9b23-21ec6a239503` showed successful worker pickup, transcription, and batch dispatch before concurrency-delayed cue jobs hit `MaxAttemptsExceededException` under one-attempt worker/job settings; auto-start now launches `--tries=0` workers, concurrency-limited jobs use `tries=0`/`maxExceptions=1`, and workers with mismatched queue arguments are treated as stale. |
| 2026-05-20 | Added and enabled local `ultimate` tier. | Local `.env` now defaults browser-generated jobs to `ultimate` and auto-starts 20 workers; runtime queue priority is `subtitle-ai-ultimate,subtitle-ai-pro,subtitle-ai-plus,subtitle-ai`. |

## Completion Notes

- Implemented code path is complete for local validation: tier metadata persists internally, tier queues route initial/batch/continuation/finalization jobs, database/Redis queue workers enforce install-scoped concurrency, completed jobs emit budget checks, cost estimates accumulate on jobs, and `subtitles:metrics` reports p50/p95 timing, queue wait, budget misses, and cost per generated minute.
- Generate requests now call `SubtitleQueueWorkerBootstrapper` so local Redis/database workers are auto-started when queued work exists; worker lifecycle is logged with sanitized queue, retry, and PID metadata.
- Extension-facing contracts did not change; generation tier and cost remain internal until accounts and billing phases define authenticated entitlements.
- Provider-backed medium and near-limit evidence was not run during implementation to avoid unapproved provider spend. Follow-up is tracked in `docs/exec-plans/tech-debt-tracker.md` as `TD-010`.

## Validation/Evidence Required

- `.\scripts\agent\check.ps1`
- `.\scripts\agent\verify-pr.ps1`
- Provider-backed timing runs with sanitized trace evidence.
- Before/after comparison for generation duration, queue wait, provider failures, and cost per generated minute.
- Tests for duplicate request reuse, queue priority behavior, entitlement-neutral caching, and public-safe telemetry.

## Risks and Follow-up Debt

- More workers may trigger provider rate limits and make higher tiers slower instead of faster.
- Cross-account caching can create privacy and ownership issues if not designed carefully.
- Model routing can reduce quality in ways tests may not catch without real-language samples.
- Cost telemetry must not leak raw prompt, transcript, translation, token, or provider payload data.
