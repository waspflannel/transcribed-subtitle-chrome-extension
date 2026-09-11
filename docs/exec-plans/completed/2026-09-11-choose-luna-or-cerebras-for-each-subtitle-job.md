# Plan: Choose Luna or Cerebras for each subtitle job

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-11
Last updated: 2026-09-11

## Goal

Choose Luna or Cerebras from the extension for each generation without restarting the backend between choices. A job keeps its provider and exact model for all text processing, including later edits and word cards.

## Scope

- Persist a native model selector in existing extension settings; default to Luna.
- Validate `aiProvider`, resolve the backend model, and pin both on each job.
- Include pins in job reuse, database uniqueness, word-card caches, logs and cost estimates.
- Pass immutable selection explicitly through analysis, retries, full/clicked cards, Quick Fix and lyrics replacement.
- Keep ElevenLabs and its transcript cache independent of the text model.
- Preserve the relaxed validator and spoken-wording prompt.
- No new dependencies, hybrid routing, paid generation, or automatic backend restarts.

## Acceptance Criteria

- [x] Consecutive or concurrent jobs can choose different providers without config mutation.
- [x] Same-video results are isolated by provider and exact model; audio transcripts remain shared.
- [x] Queued jobs, cards and corrections retain saved selection after backend defaults change.
- [x] Invalid/unconfigured choices cannot create jobs, reserve usage or start audio work.
- [x] Selector uses existing accessible native controls and is remembered in extension storage.
- [x] Full harness passes and deployment migration is applied locally.

## Relevant Context

- Architecture: `ARCHITECTURE.md`
- Reliability: `docs/RELIABILITY.md`
- UI expectations: `docs/FRONTEND.md`
- Quality rules: `docs/quality/golden-principles.md`
- Prior recovery: `docs/exec-plans/completed/2026-09-11-review-and-recover-provider-and-pipeline-optimizations.md`

## Implementation Steps

- [x] Trace all model calls and reuse boundaries.
- [x] Add schema migration, canonical API fields, selection propagation and UI.
- [x] Add focused behavior checks and review ownership and reuse.
- [x] Update architecture, UI, reliability and deployment notes.
- [x] Run full validation and finish review.

## Validation Plan

- Repository harness: `.\scripts\agent\check.ps1`
- PHP formatting: `php vendor/bin/pint --dirty --format agent`
- API tests cover two providers, two model versions, shared transcript cache, queued work, costs, corrections and invalid input.
- SDK fakes assert all four analysis/card/edit entry points use explicit provider/model despite opposing global configuration; alignment is covered through the correction API.
- Real extension entrypoint tests switch providers between active tabs and exercise the native select.
- No paid external generation is started.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-11 | Extend existing SubtitleModel with immutable selection values and explicit SDK prompt overrides. | Avoid mutable global state in long-lived workers; reuse installed SDK support. Context7 Laravel AI docs confirmed per-invocation provider/model arguments. |
| 2026-09-11 | Keep processing version independent of model; extend the compatibility unique key. | Model variants must coexist without hiding existing jobs or invalidating Scribe transcripts. |
| 2026-09-11 | Migration pins historical jobs to the configured deployment default once. | Historical jobs did not store provider identity; a dynamic fallback would keep changing them. This does not establish historical provenance. |
| 2026-09-11 | Use native select and existing settings/message flow. | No component library or new preference infrastructure is needed. |
| 2026-09-11 | Apply ponytail, subtitle-pipeline, ai-sdk-development and laravel-best-practices. | Explicit pins follow provider boundaries; delegated Laravel rule review identified uniqueness, corrections, cache and rollback requirements. Generic infrastructure recommendations were out of scope. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-11 | Core implementation and focused tests pass. | 113 API tests / 851 assertions; 57 SDK and tracing tests / 234 assertions; 17 background/panel/history tests. |

## Completion Notes

- Full harness passed: 505 backend tests / 3,997 assertions; 231 extension tests; contracts, TypeScript compile and production extension build. Evidence: ignored `app/backend/storage/logs/per-job-provider-full-check.log`.
- Pint and `git diff --check` passed. Final panel label simplification passed the real panel entrypoint tests and production build again.
- Local Postgres migration applied. Verified both columns and the expanded unique index. One existing completed job remains intact, pinned to Luna. Both provider configurations are present; no keys were exposed.
- Reviewed every production AI call, retry context, job/cache identity, and model-specific cost/log field. No new dependencies or mutable provider context were introduced.
- Backend/worker restart and extension reload are left to the user, as requested earlier. No paid provider generation or browser quality comparison was run.
- Delivery branch: `codex/reviewed-pipeline-optimizations`. Changes are grouped into backend model selection and contracts, the extension selector, and documentation commits.
- Existing rows lack historical provider identity; deployment default pins that legacy work. Rolling back the unique index may be blocked by valid model variants, without deleting them.
