# Plan: Optional Cue Translation Agent

Status: completed
Owner: agent
Created: 2026-05-14
Last updated: 2026-05-14

## Goal

Add optional cue-level subtitle translation as a separate backend AI step controlled by an extension checkbox. Translation uses the selected Translation language, writes existing cue `translatedText`, and leaves token boundaries, romanization, and word-card metadata ownership unchanged.

Keep default generation cheap and transcript-first: new and existing installs default translation off, and Full word cards only adds token metadata unless translation is explicitly enabled.

## Scope

- In scope: contracts, backend request validation, processing-version/cache behavior, translation agent/provider validation, job progress/logging, popup settings/UI, overlay rendering, and focused tests.
- Out of scope: database schema changes, new providers, queues, translation memory, glossary support, user accounts, or provider failover.

## Acceptance Criteria

- [x] `includeTranslation` is required by the shared contract and backend request; the extension setting defaults to `false` and always sends the normalized value.
- [x] The extension exposes `Translate subtitles`, persists it as `showTranslation`, and sends it to the backend.
- [x] Backend order is transcription -> tokenization -> optional romanization -> optional translation -> optional full word-card enrichment -> final track.
- [x] Same-language requests skip translation and keep `translatedText === sourceText`.
- [x] Full word cards preserve existing cue translation state and only add token metadata.
- [x] Translated and untranslated tracks cache separately.
- [x] Progress/history supports `translating`.
- [x] Tests and harness validation pass or any blockers are recorded with evidence.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-11-ai-first-tokenization-and-romanization-revamp.md`, `docs/exec-plans/completed/2026-05-12-simple-ai-tokenization-pipeline-refactor.md`
- Known risks: generated translations are sensitive video-derived content and must not be logged; old full-card tracks translated cue text implicitly, so processing versions must be bumped to avoid stale cache reuse.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Update contracts and generated TypeScript types.
- [x] Implement backend translation agent, provider validation, pipeline stage, logging, and processing versions.
- [x] Implement extension setting, checkbox, request payload, progress labels, and overlay gating.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location packages\contracts; npm run check; Pop-Location
Push-Location app\backend; php artisan test --compact tests/Feature/SubtitleJobApiTest.php; php artisan test --compact tests/Unit/CueEnrichmentServiceTest.php; php artisan test --compact tests/Unit/AiAgentInstructionTest.php; php artisan test --compact; Pop-Location
Push-Location app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: contract validation/type generation, Laravel feature/unit tests, extension unit/compile/build, repository check.
- Screenshots or video: not expected unless popup layout needs visual adjustment beyond static tests.
- Logs: confirm translation logs include counts/model/languages only, not cue text or translated content.
- Metrics or traces: none for this synchronous proof slice.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-14 | `showTranslation` defaults off and `includeTranslation` is an explicit request field. | Keeps default generation cheap while making generation controls contract-valid at the API boundary. |
| 2026-05-14 | Full word cards do not force cue translation. | Separates subtitle translation from token-card metadata and follows the requested checkbox semantics. |
| 2026-05-14 | Use Laravel AI structured output agent and existing provider service. | Matches current tokenization/romanization/enrichment architecture and Laravel AI docs. |
| 2026-05-14 | No DB migration. | Translation state is represented by processing version and existing `translatedText`. |
| 2026-05-14 | Loaded Boost skills: `ai-sdk-development`, `laravel-best-practices`, `laravel-security`, `subtitle-pipeline`. | Backend work touches Laravel requests/services, AI agents, provider config, logs, and generated text handling. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-14 | Plan created and refined from user-approved implementation plan. | Harness docs, backend skills, Laravel AI Context7 docs, and current tokenization/romanization pipeline inspected. |
| 2026-05-14 | Contract, backend, extension, and docs implementation completed. | Added `includeTranslation`, `CueTranslationAgent`, translation provider validation, `translating` progress, popup checkbox, and overlay gating. |
| 2026-05-14 | Validation completed. | `npm run check` in contracts passed; targeted backend tests passed: `SubtitleJobApiTest`, `CueEnrichmentServiceTest`, `AiAgentInstructionTest`; extension `npm test`, `npm run compile`, and `npm run build` passed; `php artisan test --compact` passed; `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1` passed; `vendor\bin\pint --dirty --format agent` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings. |

## Completion Notes

- What changed: Added optional cue translation as an independent agent/pipeline step, extension toggle, contract flag, progress stage, cache-version dimension, and durable docs.
- Validation results: All targeted and full harness checks passed; see Progress Log evidence.
- Simplicity/readability review: Kept translation inside the existing provider/service pattern, avoided DB changes, and preserved full-card enrichment as token metadata only.
- Residual risk: Live translation quality still depends on the configured OpenAI model and target language pair.
- Follow-up debt: None.
