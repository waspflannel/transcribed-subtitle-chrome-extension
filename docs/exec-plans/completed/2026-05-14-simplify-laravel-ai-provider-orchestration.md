# Plan: Simplify Laravel AI provider orchestration

Status: completed
Owner: agent
Created: 2026-05-14
Last updated: 2026-05-14

## Goal

Simplify `LaravelAiTranslationAnalysisProvider` so it orchestrates Laravel AI agents directly instead of maintaining provider preflight checks, response-shape adapters, and retry-model fallback paths.

Keep real boundary validation for AI output content: cue identity, cue count, token identity, token count, and learner-token safety still fail loudly with stable public errors.

## Scope

- In scope: provider orchestration, tokenization retry removal, retry model config/log/doc cleanup, focused test updates.
- Out of scope: changing public API contracts, storage shape, extension behavior, learner-card behavior, or Scribe transcription handling.

## Acceptance Criteria

- [x] Provider calls agents directly and receives structured arrays from the Laravel AI response.
- [x] Tokenization uses one agent call per batch and fails immediately when validation rejects output.
- [x] `ensureProviderConfigured`, `structuredResponse`, retry helpers, retry model config, and retry-model log fields are removed.
- [x] Agent prompt inputs stay dynamic-data-only and do not contain `instructions` or `qualityFailures`.
- [x] Living reliability/observability docs no longer describe a tokenization retry model.
- [x] Focused backend tests pass.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-14-refactor-ai-prompt-ownership-to-agents.md`
- Known risks: removing retry means a single bad tokenization output now fails the generation request instead of attempting a second model call.

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
vendor\bin\pint --dirty --format agent
php artisan test --compact tests\Unit\CueEnrichmentServiceTest.php
php artisan test --compact tests\Unit\AiAgentInstructionTest.php
php artisan test --compact tests\Feature\SubtitleJobApiTest.php --filter=learning_token
```

Evidence to capture:

- Tests:
  - `vendor\bin\pint --dirty --format agent` passed.
  - `php artisan test --compact tests\Unit\CueEnrichmentServiceTest.php` passed, 19 tests / 81 assertions.
  - `php artisan test --compact tests\Unit\AiAgentInstructionTest.php` passed, 4 tests / 12 assertions.
  - `php artisan test --compact tests\Feature\SubtitleJobApiTest.php --filter=learning_token` passed, 3 tests / 19 assertions.
  - `php artisan test --compact tests\Feature\SubtitleJobApiTest.php` passed, 28 tests / 257 assertions.
  - `.\scripts\agent\check.ps1` passed, including docs lint, contracts validation/build, backend tests, extension tests, TypeScript compile, and WXT build.
- Screenshots or video: not applicable for backend-only flow change.
- Logs: not applicable beyond command output.
- Metrics or traces: not applicable.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-14 | Remove tokenization retry instead of preserving it as orchestration policy. | The current product path should trust the tokenizer agent plus validation and fail loudly on invalid output. |
| 2026-05-14 | Remove provider preflight and generic response-shape coercion. | Laravel AI agents are the framework boundary; app code should call the structured agent and validate domain content. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-14 | Plan created. |  |
| 2026-05-14 | Implemented provider simplification and removed retry model references from config/logging/living docs. | Pending test run. |
| 2026-05-14 | Validation completed. | Focused tests, full `SubtitleJobApiTest`, and `.\scripts\agent\check.ps1` passed. |

## Completion Notes

- What changed: `LaravelAiTranslationAnalysisProvider` now calls structured Laravel AI agents directly, JSON-encodes dynamic input in one helper, and fails tokenization validation immediately without retry-model fallback. Retry model config/log fields and living docs were removed.
- Validation results: focused backend tests, full subtitle feature test, Pint, and the repository check script passed.
- Simplicity/readability review: provider tokenization no longer flows through `tokenizeBatch`, retry attempts, response coercion, or provider preflight.
- Residual risk: a malformed tokenizer response now fails the generation request on the first invalid output.
- Follow-up debt: none added.
