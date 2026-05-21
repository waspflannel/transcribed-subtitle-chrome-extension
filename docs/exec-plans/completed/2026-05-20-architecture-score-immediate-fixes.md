# Plan: Architecture Score Immediate Fixes

Status: completed
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Goal

Implement the small, non-roadmap architectural fixes identified by the 2026-05-20 architecture review. The goal is to raise the architecture score through boundary hardening, safer logs, contract proof, and one current data-write correctness fix without pulling accounts, billing, production hosting, or major generation refactors forward.

This plan intentionally compares every architecture finding against `docs/exec-plans/active/saas-roadmap/` and defers work that is already owned by a future SaaS phase.

## Scope

- In scope: failure log sanitization, representative backend response contract validation, clicked-token enrichment write safety, and generated database schema documentation freshness.
- In scope: focused tests and documentation updates required by those changes.
- Out of scope: production hosting, CI, supervised workers, accounts, billing, website/dashboard work, marketing, Chrome Web Store release operations, provider-backed paid runs, and broad pipeline rewrites.
- Out of scope: new runtime dependencies unless a current repository tool cannot validate contract responses.
- Out of scope: pre-existing untracked files not required for this plan, including `docs/TEMP_HANDOFF.md`.

## Acceptance Criteria

- [x] Failure logs emitted through `SubtitleWorkflowLogger::processingFailed()` use one allowlisted or sanitized context path and do not include process commands, stdout or stderr excerpts, raw provider payloads, prompts, transcript text, raw audio paths, install IDs, or token payload dumps.
- [x] Tests prove unsafe failure context is removed while safe operational fields remain available for debugging.
- [x] Representative Laravel API responses are validated against canonical contract schemas from `packages/contracts/schemas` in the test suite or an invoked contract validation helper.
- [x] Clicked-token enrichment updates merge against a fresh locked track row before writing `SubtitleTrack.cues`, so concurrent token enrichments do not overwrite each other.
- [x] `docs/generated/db-schema.md` reflects the generation optimization migration fields `generation_tier` and `estimated_provider_cost_microusd`.
- [x] Standard repository validation passes, or any blocker is recorded with exact failing command and output.

## Roadmap Overlap Decisions

| Architecture Finding | Roadmap Coverage | Decision For This Plan | Reason |
| --- | --- | --- | --- |
| Failure logging can leak sensitive or noisy context | Roadmap phases mention public-safe telemetry, but no phase owns the immediate backend logger gap | Fix now | Small security and reliability improvement with high score impact and no product dependency |
| Production deployment architecture is incomplete | `saas-roadmap/06-production-hosting-and-ops.md` | Defer | Phase 06 owns CI, deployment, supervised workers, scheduler, backups, logs, alerts, and rollback |
| Async generation orchestration is concentrated in `SubtitleGenerationPipeline` | `saas-roadmap/01-generation-optimization.md` plus the simplification pass | Defer | Current generation work is active; splitting stages now risks colliding with measured optimization and should be done only when the next pipeline change requires it |
| AI provider orchestration class has too many stage responsibilities | `saas-roadmap/01-generation-optimization.md` | Defer | Model and prompt routing belong to the generation optimization phase; extraction should follow measured routing needs |
| Cue/token domain data is array-shaped JSON | Future account, caching, and vocabulary-related product pressure | Defer | Valuable but larger than an immediate score lift; revisit when account-scoped reuse or vocabulary features need stronger data ownership |
| Extension API client trusts backend JSON | `saas-roadmap/02-extension-frontend-upgrade.md` | Defer | Runtime guards should be designed with the product-grade popup and account/usage states, not added piecemeal before contract shape changes |
| Clicked-token enrichment can lose concurrent token patches | Not directly owned by a future roadmap phase | Fix now | Current correctness issue in an existing workflow; can be fixed without account or billing assumptions |
| Queue job wrappers duplicate config and failure behavior | Current generation optimization and simplification context | Defer | This is structural cleanup, but queue policy was recently changed; avoid churn until the active generation optimization branch settles |
| Browser extension smoke coverage is absent | `saas-roadmap/02-extension-frontend-upgrade.md`, `saas-roadmap/07-beta-launch-and-support.md`, `TD-003`, `TD-007` | Defer | Roadmap already owns visual/browser smoke evidence for extension upgrade and release readiness |
| Backend feature responses are not schema-validated | Not directly owned by a future roadmap phase | Fix now | Reinforces an existing contract-first architecture before auth/billing expands response shapes |
| Generated database docs are stale | Not directly owned by a future roadmap phase | Fix now | Low effort and improves agent legibility for generation tier and cost fields |
| Extension entrypoints are large coordinators | `saas-roadmap/02-extension-frontend-upgrade.md` | Defer | Popup and extension structure should be revisited when the frontend surface is redesigned |

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/architecture-review-report-2026-05-20.md`
- Quality rules: `docs/quality/golden-principles.md`
- Security and observability docs: `docs/SECURITY.md`, `docs/OBSERVABILITY.md`
- Roadmap: `docs/exec-plans/active/saas-roadmap/00-roadmap-index.md`
- Related active plans: `docs/exec-plans/active/saas-roadmap/01-generation-optimization.md`, `docs/exec-plans/active/2026-05-20-whole-codebase-simplification-pass.md`
- Related debt: `docs/exec-plans/tech-debt-tracker.md`
- Known risk: Laravel Boost MCP tools are not exposed in this Codex session; avoid Laravel API or dependency changes that require current framework documentation unless Context7 or local docs can supply enough evidence.

## Implementation Steps

- [x] Inspect the architecture report and SaaS roadmap.
- [x] Compare each architecture issue against future roadmap ownership.
- [x] Implement failure log context sanitization.
- [x] Add tests for sanitized failure logging.
- [x] Add representative backend response contract validation.
- [x] Add tests for the contract validation path.
- [x] Make clicked-token enrichment write updates concurrency-safe.
- [x] Add tests for same-track concurrent enrichment preservation.
- [x] Update generated database schema documentation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update `docs/QUALITY_SCORE.md` if the implemented fixes materially change the architecture, tests, observability, or security score.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Implementation Detail

### Slice 1: Sanitize Failure Logging

Goal: prevent exception context from bypassing the existing trace-sanitization posture.

Steps:

- Add one backend log-context sanitizer or allowlist used by `SubtitleWorkflowLogger::processingFailed()`.
- Preserve safe operational keys such as job ID, run ID, stage, provider, adapter, queue, public error code, exception class, and short non-sensitive reason.
- Drop keys and values that can include commands, stdout, stderr, local paths, raw audio paths, install IDs, provider payloads, prompts, transcript text, token payloads, full cue arrays, URLs with sensitive query strings, or other unbounded strings.
- Keep the sanitizer near the logger unless another existing logging helper already owns this boundary.
- Add or update tests that build a `SubtitleProcessingException` with unsafe context and assert the emitted log context does not contain those unsafe fields.

Target files:

- `app/backend/app/Services/SubtitleWorkflowLogger.php`
- `app/backend/app/Exceptions/SubtitleProcessingException.php` if a helper method is a cleaner fit
- `app/backend/tests/Unit` or `app/backend/tests/Feature` logger tests
- `app/backend/tests/Unit/Services/YouTubeAudioSourceTest.php` if audio failure context is easiest to exercise there

### Slice 2: Validate Backend Responses Against Contracts

Goal: make the backend prove that representative response bodies still match the canonical contract schemas.

Steps:

- Add a small test helper that validates captured Laravel JSON responses against `packages/contracts/schemas`.
- Prefer invoking an existing Node/AJV contract validator from tests or adding a narrow script under `packages/contracts/scripts` that accepts a schema path and payload path.
- Avoid adding a PHP JSON Schema dependency unless invoking the existing contract tooling proves impractical.
- Cover representative responses for subtitle job creation, running job polling, completed job polling with track, failed job polling, job history, learning-token enrichment, and API error responses.
- Keep fixtures public-safe and generated inside tests where possible.

Target files:

- `app/backend/tests/Feature/ContractBoundaryTest.php` or a new focused feature test
- `packages/contracts/scripts` if a reusable payload validator is needed
- `packages/contracts/schemas/*.schema.json` only if the test exposes a real contract mismatch

### Slice 3: Preserve Concurrent Clicked-Token Enrichments

Goal: avoid lost updates when two clicked-token enrichment requests patch different tokens on the same stored track.

Steps:

- Keep provider/cache work outside the database lock so slow AI calls do not block unrelated reads and writes.
- After the enrichment result is known, open a transaction and lock the current `SubtitleTrack` row with `lockForUpdate`.
- Reload the latest `cues` JSON from the locked row.
- Re-find the cue and token by cue ID and token index.
- If metadata was added by another request while the provider call was running, return the current stored metadata instead of overwriting it.
- Otherwise merge only the target token's learning metadata into the fresh cue array and save the full `cues` JSON once.
- Add a regression test that simulates two updates against the same track and proves both enriched tokens remain present after both operations.

Target files:

- `app/backend/app/Services/LearningTokenEnrichmentService.php`
- `app/backend/tests/Feature/LearningTokenApiTest.php` or the existing learning-token service tests

### Slice 4: Refresh Generated Database Schema Docs

Goal: keep agent-facing schema documentation consistent with the active migrations.

Steps:

- Update `docs/generated/db-schema.md` to include `subtitle_jobs.generation_tier` and `subtitle_jobs.estimated_provider_cost_microusd`.
- Update the generated-doc timestamp to 2026-05-20.
- If the schema doc format suggests an existing generator command, use it; otherwise edit only the stale generated section and record the manual update in this plan.

Target files:

- `docs/generated/db-schema.md`
- `docs/QUALITY_SCORE.md` only if the documentation freshness materially changes the recorded quality notes

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
.\scripts\agent\lint-docs.ps1
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: targeted backend tests for logger, contract response validation, and learning-token concurrency; full harness check.
- Screenshots or video: not required for this backend/docs-focused plan.
- Logs: sanitized log test output or assertions proving unsafe keys are absent.
- Metrics or traces: not required unless implementation touches runtime trace output.

## Expected Architecture Score Impact

The 2026-05-20 architecture review scored the codebase 80/100. This plan is expected to move the score into the low 80s by improving security/reliability, data-flow correctness, testability, and developer experience without pretending to solve production readiness or future SaaS ownership concerns.

Likely score movement:

- Security and Reliability Architecture: +1 from log sanitization.
- Testability: +1 from backend response schema validation.
- Data Flow and Workflow Clarity: +1 from safe clicked-token merge behavior.
- Code Organization and Developer Experience: small improvement from fresh generated schema docs.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-20 | Fix log sanitization, backend response contract validation, clicked-token write safety, and generated schema docs now. | These items improve current architecture quality without depending on accounts, billing, production hosting, or extension redesign. |
| 2026-05-20 | Defer production hosting, accounts, billing, browser smoke, extension entrypoint splitting, and large pipeline/provider refactors to the SaaS roadmap. | Those concerns already have explicit phase ownership and should be implemented with their surrounding product requirements. |
| 2026-05-20 | Do not add broad clean-architecture layers for this plan. | The codebase is already a healthy Laravel modular monolith; the current score lift comes from concrete boundary fixes. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-20 | Plan created. | `scripts/agent/new-plan.ps1 -Title "Architecture Score Immediate Fixes"` |
| 2026-05-20 | Architecture findings compared against all SaaS roadmap phases. | `docs/architecture-review-report-2026-05-20.md`; `docs/exec-plans/active/saas-roadmap/` |
| 2026-05-20 | Baseline harness passed before implementation. | `.\scripts\agent\check.ps1` passed: docs lint, contracts check, Laravel tests, WXT tests, TypeScript compile, and WXT build. |
| 2026-05-20 | Loaded backend implementation guidance. | `app/backend/AGENTS.md`, `laravel-best-practices`, `laravel-security`, `laravel-specialist`, `subtitle-pipeline`; Laravel and Symfony docs checked through Context7 for `DB::transaction`, `lockForUpdate`, JSON tests, and Symfony Process. |
| 2026-05-20 | Added failure-context sanitization to processing failure logs. | `php artisan test --compact tests/Unit/SubtitleWorkflowLoggerTest.php` passed: 6 tests, 11 assertions. |
| 2026-05-20 | Added AJV payload validation and backend response contract tests. | `npm run check` passed in `packages/contracts`; `php artisan test --compact tests/Feature/ContractResponseValidationTest.php` passed: 2 tests, 30 assertions. |
| 2026-05-20 | Made clicked-token enrichment merge against a fresh locked track row. | `php artisan test --compact tests/Feature/SubtitleJobApiTest.php --filter=learning_token` passed: 4 tests, 24 assertions. |
| 2026-05-20 | Updated generated DB schema docs and quality score notes. | `docs/generated/db-schema.md`; `docs/QUALITY_SCORE.md`. |
| 2026-05-20 | Targeted validation passed after implementation. | `vendor\bin\pint --dirty --format agent` passed; logger tests passed: 6 tests, 11 assertions; contract response plus subtitle API tests passed: 55 tests, 426 assertions; contracts `npm run check` passed. |
| 2026-05-20 | Full repository validation passed. | `.\scripts\agent\lint-docs.ps1` passed; `.\scripts\agent\doc-gardening.ps1` found no issues; `git diff --check` passed; `.\scripts\agent\check.ps1` passed: docs lint, contracts, 147 Laravel tests, 48 WXT tests, TypeScript compile, WXT build. |

## Completion Notes

- What changed: added allowlisted processing-failure log context, added a reusable contract payload validator plus backend response schema tests, made clicked-token enrichment merge against a fresh locked track row, refreshed generated DB schema docs, and updated quality score notes.
- Validation results: `vendor\bin\pint --dirty --format agent`, targeted backend tests, contracts `npm run check`, docs lint, doc gardening, `git diff --check`, and `.\scripts\agent\check.ps1` all passed. The final full harness reported 147 Laravel tests with 780 assertions and 48 WXT tests.
- Simplicity/readability review: changes stay at existing boundaries, add no runtime dependencies, keep AI/provider work backend-only, and avoid pulling forward SaaS roadmap scope.
- Residual risk: true parallel database lock behavior is still represented by Laravel's documented transaction and `lockForUpdate` semantics plus a simulated stale-write regression test rather than an OS-level concurrent request test.
- Follow-up debt: none added.
