# Plan: Simple AI Tokenization Pipeline Refactor

Status: active
Owner: agent
Created: 2026-05-12
Last updated: 2026-05-12

## Goal

Simplify subtitle generation into the product pipeline the user expects: audio acquisition, ElevenLabs transcription, one mandatory AI tokenizer pass, optional romanization, display, and on-click enrichment. The tokenizer agent owns learner-friendly token boundaries; backend code validates only structure and obvious malformed output.

Remove the earlier morphology-like retry and no-space under-segmentation heuristics that made Japanese behavior hard to reason about. Keep coarse deterministic fallback only as an emergency provider-failure path so default subtitle generation still completes.

## Scope

- In scope: backend tokenization/romanization flow, tokenizer prompt, provider validation/fallback, model configuration defaults, processing/cache versions, contracts/extension romanization flag behavior, focused tests, and durable docs.
- Out of scope: database migrations, UI redesign, language-specific deterministic morphology rules, new providers, queues, and provider failover.

## Acceptance Criteria

- [x] Every transcript generation calls `CueTokenizationAgent` after transcription, including Latin and non-Latin cues.
- [x] `OPENAI_TOKENIZATION_MODEL` defaults to the existing enrichment model when unset; `OPENAI_ROMANIZATION_MODEL` defaults to the tokenizer model.
- [x] Backend validation preserves cue identity, sequential token indexes, source-order token text, non-empty token text, and rejects obvious no-space character-card spam.
- [x] Backend removes tokenizer repair retry prompts and under-segmentation / broad-token quality heuristics.
- [x] Tokenization failure completes default generation with coarse fallback tokens.
- [x] Romanization receives tokenized cues and rejects changed token count, indexes, or text.
- [x] Enrichment preserves tokenizer output and rejects changed token boundaries.
- [x] Contracts and extension keep `includeRomanization` optional/defaulted and send it from `settings.showRomanization`.
- [x] Processing/cache versions change so old tracks are not reused.
- [x] Architecture, reliability, observability, and quality docs reflect the simplified agent pipeline.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Backend guidance: `app/backend/AGENTS.md`, `app/backend/.agents/skills/ai-sdk-development/SKILL.md`, `app/backend/.agents/skills/laravel-best-practices/SKILL.md`, `app/backend/.ai/skills/subtitle-pipeline/SKILL.md`, `app/backend/.ai/skills/laravel-security/SKILL.md`
- Related plans: `docs/exec-plans/completed/2026-05-11-ai-first-tokenization-and-romanization-revamp.md`, `docs/exec-plans/completed/2026-05-12-cheap-tokenizer-agent-pipeline.md`
- Known risks: real Japanese token quality depends on the selected model and prompt quality; local validation intentionally cannot prove linguistic segmentation correctness.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Simplify tokenizer validation and fallback.
- [x] Simplify provider tokenizer prompt and remove repair retry behavior.
- [x] Update model defaults and processing/cache versions.
- [x] Verify romanization/enrichment boundary preservation and extension request behavior.
- [x] Add or update focused backend, contract, and extension tests.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: backend unit/feature tests, extension tests, contracts check, harness check, PR verification.
- Screenshots or video: not required; no UI layout changes planned.
- Logs: not required unless a validation failure needs diagnosis.
- Metrics or traces: not required for this synchronous refactor.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-12 | Tokenizer quality is owned by the AI agent and selected model, not local no-space language heuristics. | The previous heuristic layer still produced poor Japanese boundaries and made behavior harder to tune generically. |
| 2026-05-12 | `OPENAI_TOKENIZATION_MODEL` falls back to the enrichment model, and romanization falls back to tokenizer model. | The user chose quality-first behavior over cheapest-default behavior. |
| 2026-05-12 | Romanization receives tokenized cues and must preserve card boundaries exactly. | Romanization text must align with the displayed tokens and cannot safely infer its own segmentation. |
| 2026-05-12 | Default generation falls back to coarse deterministic tokens on tokenizer failure. | Subtitle generation should remain useful even when the tokenizer provider fails or returns malformed structure. |
| 2026-05-12 | Laravel Boost docs MCP is unavailable in this session; local project/backend skill files are the active guidance. | The required Boost skill routing was inspected via repo files and `php artisan boost:list-skills`. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-12 | Plan created and refined from approved user plan. | `docs/exec-plans/active/2026-05-12-simple-ai-tokenization-pipeline-refactor.md` |
| 2026-05-12 | Baseline harness check passed before simplification edits. | `.\scripts\agent\check.ps1` |
| 2026-05-12 | Removed tokenizer repair retry, broad/under-segmentation heuristics, and cheap-model default; updated docs to describe structural validation. | Backend tokenizer/provider/config/docs edits |
| 2026-05-12 | Focused tokenizer/provider unit tests pass after behavior changes. | `Push-Location .\app\backend; php artisan test --compact tests\Unit\LearningTokenTokenizerTest.php tests\Unit\CueEnrichmentServiceTest.php; Pop-Location` |
| 2026-05-12 | Subtitle job feature tests, contracts, and extension tests/compile pass. | `php artisan test --compact tests\Feature\SubtitleJobApiTest.php`; `npm run check`; `npm test`; `npm run compile` |
| 2026-05-12 | Final harness, PR verification, doc gardening, and diff whitespace checks pass. | `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`; `.\scripts\agent\doc-gardening.ps1`; `git diff --check` |

## Completion Notes

- What changed: Simplified tokenization into one mandatory AI tokenizer pass after transcription, removed tokenization repair retry and local broad/under-segmentation heuristics, kept only structural token validation plus obvious no-space character-card rejection, made tokenizer model fallback quality-first, preserved token boundaries through romanization/enrichment, bumped processing/cache versions, and updated durable docs.
- Validation results: `php artisan test --compact` passed with 84 tests and 447 assertions; contracts `npm run check` passed; extension `npm test` passed with 9 files and 38 tests; extension `npm run compile` passed; `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, `.\scripts\agent\doc-gardening.ps1`, and `git diff --check` passed.
- Simplicity/readability review: Tokenizer quality now lives in the prompt/model instead of backend morphology guesses. Fallback remains coarse and generic only for provider failure. Logs continue to avoid prompts, transcripts, translations, romanizations, and token payloads.
- Residual risk: Real token quality still depends on the configured tokenizer model. Explicit deployments with `OPENAI_TOKENIZATION_MODEL` set to a cheap model will keep using that override until changed.
- Follow-up debt: None added.
