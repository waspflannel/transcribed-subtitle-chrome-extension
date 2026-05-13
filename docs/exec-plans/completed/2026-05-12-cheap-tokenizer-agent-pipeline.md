# Plan: Cheap Tokenizer Agent Pipeline

Status: completed
Owner: agent
Created: 2026-05-12
Last updated: 2026-05-12

## Goal

Replace the current regex/Japanese-specific tokenization path with a mandatory cheap tokenizer agent that runs after transcription for every generated track. Keep romanization as a separate optional step controlled by the request, and keep enrichment as a later metadata step that preserves tokenizer boundaries.

The public cue/token response shape stays unchanged. The create-job request gains optional `includeRomanization`, defaulting to current behavior.

## Scope

- In scope:
- Backend tokenizer agent, provider orchestration, generic token validation, deterministic fallback, retry-on-token-quality failure, processing/cache versioning.
- Create-job contract and extension request update for `includeRomanization`.
- Tests and durable docs for the new pipeline.
- Out of scope:
- Database migrations.
- Extension overlay UI redesign.
- New morphology dependencies or PHP `ext-intl`.
- Direct provider calls from the extension.

## Acceptance Criteria

- [x] Tokenization runs for every generated track through a dedicated structured-output agent.
- [x] Tokenization agent output contains only cue identity and source-token boundaries.
- [x] Romanization runs only when `includeRomanization` is true and useful for the detected source language.
- [x] Romanization and full-card enrichment preserve tokenizer boundaries exactly.
- [x] Generic validation rejects character-split no-space scripts and broad no-space chunks without hardcoded Japanese morphology rules.
- [x] Tokenization retries once on retryable token-quality validation failure, then default generation falls back to generic deterministic tokens.
- [x] Contracts, extension request payload, docs, and processing/cache versions are updated.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-11-ai-first-tokenization-and-romanization-revamp.md`
- Known risks:
- Laravel AI calls must not log prompts, transcript payloads, token payloads, or generated learning content.
- Current worktree includes the previous hasty tokenizer changes; refactor them rather than layering additional hardcoded rules.
- `docs/TEMP_HANDOFF.md` is unrelated untracked work and must remain untouched.
- Cheap tokenizer model quality may vary; validation and fallback must keep tracks usable.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: update contracts and extension payload for `includeRomanization`.
- [x] Slice 2: add tokenizer agent/model config and split provider methods into tokenize, romanize, enrich.
- [x] Slice 3: refactor subtitle job orchestration to tokenize first, optionally romanize, then optionally enrich.
- [x] Slice 4: replace Japanese-specific tokenizer heuristics with generic validation/fallback.
- [x] Slice 5: add/update backend, contract, and extension tests.
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

- Tests: focused backend unit/feature tests, full backend suite, contracts check, extension tests/compile, harness check.
- Screenshots or video: not required unless extension UI changes beyond payload wiring.
- Logs: confirm workflow logs remain payload-free.
- Metrics or traces: not required for this synchronous path.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-12 | Use a dedicated tokenizer agent before romanization/enrichment. | Tokenization is the primary data shape needed for the overlay and should not be coupled to romanization or word-card metadata. |
| 2026-05-12 | Default tokenizer model is `gpt-5.4-nano`. | The task is narrow structured extraction/segmentation and should use a cheap model by default. |
| 2026-05-12 | Add `includeRomanization` request flag with default true. | Preserves current behavior while allowing the extension to skip romanization when the user hides it. |
| 2026-05-12 | Keep deterministic fallback generic and coarse. | Avoids accumulating language-specific morphology code while keeping default subtitles usable on provider failure. |
| 2026-05-12 | Loaded Boost skills: `ai-sdk-development`, `laravel-best-practices`, `laravel-patterns`, `laravel-security`, `laravel-specialist`, `subtitle-pipeline`. | Backend AI/provider work must preserve Laravel conventions, provider boundaries, payload-free logging, and subtitle pipeline constraints. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-12 | Plan created and refined from user-approved cheap tokenizer-agent pipeline. | `AGENTS.md`, `app/backend/AGENTS.md`, Boost skill list, Context7 `/laravel/ai` structured-output docs, current tokenizer/provider/tests inspection. |
| 2026-05-12 | Implemented tokenizer-first backend pipeline, request-level romanization control, generic no-space validation/fallback, extension request payload, contracts, and docs. | `CueTokenizationAgent`, `LaravelAiTranslationAnalysisProvider`, `SubtitleJobService`, `LearningTokenTokenizer`, contract schemas, extension background payload, architecture/reliability/observability docs. |
| 2026-05-12 | Validation completed. | `php artisan test --compact` passed 81 tests / 436 assertions; `npm test` passed 9 files / 38 tests; `npm run compile` passed; `packages/contracts npm run check` passed; `scripts/agent/check.ps1` passed; `scripts/agent/verify-pr.ps1` passed. |

## Completion Notes

- What changed: Added a dedicated cheap structured-output cue tokenizer, made tokenization mandatory before romanization/enrichment, added `includeRomanization`, preserved token boundaries through later agents, replaced Japanese-specific backend fallback with generic no-space validation/fallback, and bumped processing/clicked-token cache versions.
- Validation results: Full backend, extension, contracts, agent harness, and PR verification checks passed on 2026-05-12.
- Simplicity/readability review: The deterministic fallback is intentionally coarse and generic; language-specific segmentation lives in the tokenizer agent instead of accumulating backend morphology rules.
- Residual risk: Cheap model segmentation quality still depends on provider behavior, but validation/retry/fallback prevents the worst character-card and broad-token failures from blocking generation.
- Follow-up debt: No new debt added; release-smoke/browser screenshot debt remains tracked separately.
