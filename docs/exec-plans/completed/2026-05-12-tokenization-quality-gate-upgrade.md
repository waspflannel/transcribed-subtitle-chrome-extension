# Plan: Tokenization Quality Gate Upgrade

Status: completed
Owner: agent
Created: 2026-05-12
Last updated: 2026-05-12

## Goal

Upgrade tokenization from accepting any structurally valid AI output to accepting only token boundaries that pass a backend quality gate. Bad learner-card chunks such as `か聞いてみた` and `いと` should be rejected and retried instead of rendered as clickable tokens.

Keep the implementation AI-first and dependency-free for this slice: improve prompt context, add validation-quality checks, retry failed cues with a stronger configured model, and fall back to plain subtitle display for cues whose tokenization remains poor.

## Scope

- In scope: backend tokenization prompt/input, structured token schema, validation quality gate, retry-model cascade, processing/cache version bumps, tests, and durable docs.
- Out of scope: public API/contract shape changes, new Japanese tokenizer dependencies, extension UI redesign, queues, provider failover across vendors, and transcription provider changes.

## Acceptance Criteria

- [x] Tokenization prompt input includes previous/current/next cue context and normalized `tokenizationText`.
- [x] Tokenization agent returns validation-only source character spans that are stripped before storage/API exposure.
- [x] Quality gate rejects observed Japanese bad chunks (`か聞いてみた`, `いと`) and overbroad/punctuation-joined tokens across languages.
- [x] Failed cue tokenization retries once with `OPENAI_TOKENIZATION_RETRY_MODEL`, defaulting to `gpt-5.5`.
- [x] Valid first-pass cues are not retried.
- [x] Cues that still fail quality are stored as `tokens: []` and render as plain subtitle text.
- [x] Provider outage or malformed tokenization batch still completes default subtitle generation.
- [x] Romanization and full enrichment preserve valid tokenizer boundaries and skip/handle tokenless cues without retokenizing.
- [x] Existing cached processing versions are not reused.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Backend guidance: `app/backend/AGENTS.md`, `docs/references/boost-skill-routing.md`, `app/backend/.agents/skills/ai-sdk-development/SKILL.md`, `app/backend/.agents/skills/laravel-best-practices/SKILL.md`, `app/backend/.ai/skills/subtitle-pipeline/SKILL.md`
- Related plans: `docs/exec-plans/completed/2026-05-12-simple-ai-tokenization-pipeline-refactor.md`, `docs/exec-plans/completed/2026-05-12-cheap-tokenizer-agent-pipeline.md`, `docs/exec-plans/completed/2026-05-11-ai-first-tokenization-and-romanization-revamp.md`
- Known risks: live token quality still depends on OpenAI model behavior; stricter global checks may suppress some valid multi-word fixed expressions; logs must not include prompts or transcript text.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: add richer prompt context, validation-only token spans, and stronger tokenizer instructions.
- [x] Slice 2: add token quality gate and tokenless cue fallback.
- [x] Slice 3: add retry-model cascade and model configuration.
- [x] Slice 4: update tests, docs, processing versions, and generated contract artifacts if needed.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; vendor\bin\pint --dirty --format agent; php artisan test --compact tests\Unit\LearningTokenTokenizerTest.php tests\Unit\CueEnrichmentServiceTest.php tests\Feature\SubtitleJobApiTest.php; php artisan test --compact; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: focused backend tokenizer/provider/job tests, full backend tests, contracts check, extension tests/compile, harness check, PR verification.
- Screenshots or video: not required; overlay rendering contract remains unchanged.
- Logs: confirm docs describe model/retry/fallback signals without payload logging.
- Metrics or traces: not required for this synchronous path.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-12 | Use AI-first prompt/context plus backend quality gate, not a new Japanese tokenizer dependency. | Matches the requested plan and keeps deployment simple while directly blocking bad clickable tokens. |
| 2026-05-12 | Apply stricter quality checks globally, with Japanese-specific bad-pattern coverage. | User chose all-language scope, but the observed regression needs concrete Japanese fixtures. |
| 2026-05-12 | Store `tokens: []` when retry still fails quality. | Plain subtitles are less harmful than incorrect clickable word cards, and the overlay already supports tokenless cues. |
| 2026-05-12 | Use Laravel AI per-prompt model override for retry cascade. | Context7 and installed Laravel AI code confirm structured agents support prompt-level provider/model overrides. |
| 2026-05-12 | Loaded Boost skills: `ai-sdk-development`, `laravel-best-practices`, and `subtitle-pipeline`. | Backend AI/provider work must preserve Laravel conventions, structured output usage, backend-only provider calls, and payload-free logs. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-12 | Plan created and refined from the user-approved tokenization quality upgrade. | `AGENTS.md`, `docs/references/project-guardrails.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `app/backend/AGENTS.md`, Laravel AI Context7 docs, installed Laravel AI `Promptable`/OpenAI gateway inspection. |
| 2026-05-12 | Implemented span-backed token quality validation, retry-model cascade, tokenless fallback, tokenless cue skipping for romanization/enrichment, processing-version bumps, and durable docs. | `CueTokenizationAgent`, `LearningTokenTokenizer`, `LaravelAiTranslationAnalysisProvider`, `SubtitleJobService`, `TimestampedSubtitleTrackGenerator`, `SubtitleWorkflowLogger`, backend unit/feature fixtures. |
| 2026-05-12 | Focused and full validation passed. | `vendor\bin\pint --dirty --format agent`; `php artisan test --compact tests/Unit/CueEnrichmentServiceTest.php tests/Unit/LearningTokenTokenizerTest.php tests/Unit/TimestampedSubtitleTrackGeneratorTest.php tests/Feature/SubtitleJobApiTest.php` passed with 75 tests/470 assertions; final `.\scripts\agent\verify-pr.ps1` passed with backend 98 tests/555 assertions, contracts check, extension 38 tests, compile, and WXT build. |

## Completion Notes

- What changed: Tokenizer prompts now include neighboring cue context and tokenization text, tokenizer structured output includes validation-only source spans, backend validation rejects broad/punctuation/japanese particle-attached chunks, failed cues retry with `OPENAI_TOKENIZATION_RETRY_MODEL`, and unrecoverable cues persist as transcript-only `tokens: []`.
- Validation results: Focused tokenizer/provider/job tests, full backend tests, contracts check, extension tests/compile/build, scaffold check, and PR verification all passed.
- Simplicity/readability review: Kept the public API unchanged, avoided a new tokenizer dependency, kept language-specific backend logic limited to observed bad Japanese patterns, and preserved later AI stages as boundary-preserving enrichments.
- Residual risk: Some valid rare fixed expressions may be suppressed by the stricter quality gate; live Japanese videos should still be checked against real model behavior and adjusted with fixtures if needed.
- Follow-up debt: None added.
