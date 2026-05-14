# Plan: Refactor AI Prompt Ownership To Agents

Status: completed
Owner: agent
Created: 2026-05-14
Last updated: 2026-05-14

## Goal

Move stable AI prompt instructions into the Laravel AI agent classes and keep `LaravelAiTranslationAnalysisProvider` focused on orchestration: input assembly, batching, retries, SDK invocation, structured response adaptation, and validation.

The runtime behavior, public API contracts, database shape, extension flow, and learner-card output should remain unchanged.

## Scope

- In scope: backend AI agents, `LaravelAiTranslationAnalysisProvider`, and focused backend tests for prompt/input ownership.
- Out of scope: extension UI changes, contract/schema changes, persistence changes, model changes, provider replacement, and broad decomposition of the provider class.

## Acceptance Criteria

- [ ] Agent `instructions()` methods are the single source of stable AI behavior rules.
- [ ] Provider input builders include only dynamic request data and no `instructions` key.
- [ ] Normal agent calls rely on agent-owned default model selection.
- [ ] Tokenization retry calls still use the configured retry model override.
- [ ] Existing validation, retry flow, schemas, and failure behavior stay intact.
- [ ] Focused tests prove prompt payloads contain expected dynamic input and no provider-side instructions.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`, `docs/references/boost-skill-routing.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-13-tokenization-pipeline-cleanup-refactor.md`
- Known risks: prompt behavior could drift if stable rules are removed from provider payloads but not fully represented in agent instructions; retry model handling could regress if default model overrides are removed too broadly.

## Implementation Steps

- [x] Inspect current state and dirty worktree.
- [x] Confirm/refine acceptance criteria from the user-provided plan.
- [x] Update agent instructions so stable prompt rules live in agent classes.
- [x] Refactor provider prompt builders into dynamic input builders.
- [x] Update tests for payload shape, model override behavior, and agent instructions.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Run validation and record evidence.
- [x] Complete review notes and archive this plan if all work is complete.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend
vendor\bin\pint --dirty --format agent
php artisan test --compact tests\Unit\CueEnrichmentServiceTest.php
php artisan test --compact tests\Feature\SubtitleJobApiTest.php --filter=learning_token
Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: focused backend unit/feature tests plus repository harness check.
- Screenshots or video: not applicable; backend-only refactor.
- Logs: not applicable unless failures require diagnosis.
- Metrics or traces: not applicable.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-14 | Use Laravel AI agent classes as the stable prompt owner. | Local Boost skills and Context7 Laravel AI docs define `instructions()`/`schema()` as agent responsibilities; provider-side duplicate instructions make behavior drift likely. |
| 2026-05-14 | Keep retry failure reasons as dynamic input. | `qualityFailures` depends on validation results for a specific batch and belongs in the provider-assembled prompt payload. |
| 2026-05-14 | Do not update public docs/contracts. | The refactor should not change public behavior, API shape, architecture boundary, or extension flow. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-14 | Plan created and refined from user-provided implementation plan. | Loaded `phased-implementation-v2`, root/backend AGENTS guidance, project guardrails, review policy, local `ai-sdk-development`, `laravel-best-practices`, `laravel-patterns`, Boost skill list, and Context7 `/laravel/ai` docs. |
| 2026-05-14 | Implemented prompt ownership refactor. | Agent instructions now carry stable rules; provider prompt builders now return dynamic input arrays without `instructions`; normal prompts rely on agent model methods while retry prompts still pass the retry model override. |
| 2026-05-14 | Focused validation passed. | `vendor\bin\pint --dirty --format agent`; `php artisan test --compact tests\Unit\CueEnrichmentServiceTest.php`; `php artisan test --compact tests\Unit\AiAgentInstructionTest.php`; `php artisan test --compact tests\Feature\SubtitleJobApiTest.php --filter=learning_token`. |
| 2026-05-14 | Full validation and self-review completed. | `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed; static search found no provider-side `instructions` payloads or old prompt-builder names in `LaravelAiTranslationAnalysisProvider`. |
| 2026-05-14 | Final archived-plan validation passed. | Re-ran `.\scripts\agent\check.ps1` after moving the plan to completed. |

## Completion Notes

- What changed: Stable prompt instructions moved into the four Laravel AI agent classes. `LaravelAiTranslationAnalysisProvider` now assembles dynamic input arrays, JSON-encodes them in one helper, and passes model overrides only for tokenization retry calls.
- Validation results: Pint passed; focused backend tests passed; full repository harness check passed before and after archiving the plan; PR verification passed.
- Simplicity/readability review: The provider remains the workflow coordinator but no longer duplicates agent prompt rules. Agent model config keys were added only to preserve existing missing-model failure context while letting agents own default model selection.
- Residual risk: Prompt wording changed location but not intended behavior; future prompt changes should be made in agent instructions only.
- Follow-up debt: None for this refactor.
