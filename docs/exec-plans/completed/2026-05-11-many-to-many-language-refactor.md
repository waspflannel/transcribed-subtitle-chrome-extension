# Plan: Many-To-Many Language Refactor

Status: completed
Owner: agent
Created: 2026-05-11
Last updated: 2026-05-11

## Goal

Refactor subtitle generation from a fixed source-language-to-English workflow into selectable learning/source and translation/target languages. The popup should default to Auto detect -> English, keep the existing YouTube-only synchronous generation path, and clearly separate the eight Supported languages from the broader Experimental catalog where transcription and translation quality may vary.

## Scope

- In scope:
  - Canonical shared language catalog owned by `packages/contracts`.
  - Contract updates for many-to-many source/target languages and optional detected source language.
  - Backend validation, persistence, transcription language-code handling, detected-language normalization, cache keys, resources, factories, fixtures, and tests.
  - OpenAI enrichment prompt wording generalized away from English-only assumptions.
  - Popup settings and UI for searchable source/target language pickers with Supported/Experimental badges and caveat copy.
  - Durable docs updates for the new product and architecture behavior.
- Out of scope:
  - New AI providers, direct provider calls from the extension, queues, user accounts, non-YouTube platforms, subtitle editing, vocabulary review, or provider failover.

## Acceptance Criteria

- [x] Source language accepts Auto detect or any catalog language; target language accepts any catalog language except Auto detect.
- [x] Supported languages are English, Spanish, French, German, Chinese (`zh`), Japanese, Arabic, and Portuguese.
- [x] Experimental languages remain selectable with visible quality caveats.
- [x] Auto-detected tracks preserve requested `sourceLanguage` and expose optional `detectedSourceLanguage` when available.
- [x] Same-language source/target requests return transcript subtitles without unnecessary translation enrichment.
- [x] Backend, extension, contracts, fixtures, tests, and docs agree on the same language behavior.
- [x] Final validation, simplification review, grouped commits, and PR-readiness checks are completed.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/FRONTEND.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-11-clean-elevenlabs-scribe-rewrite.md`
- Known risks:
  - Large language catalogs can bloat UI code if duplicated instead of shared.
  - Provider-detected language codes may be ISO-639-3 or aliases and need normalization.
  - Full-card and clicked-token prompts must not keep hidden English-only behavior after the target becomes selectable.
  - Popup searchable controls must stay keyboard and screen-reader usable inside a compact extension popup.

## Implementation Steps

- [x] Inspect current state and baseline validation.
- [x] Confirm or refine acceptance criteria.
- [x] Implement shared catalog and API contract changes.
- [x] Implement backend validation, persistence, provider handling, and tests.
- [x] Implement extension settings, request payload, picker UI, and tests.
- [x] Update durable docs and generated contract docs.
- [x] Run validation and record evidence.
- [x] Complete simplification/readability review.
- [x] Complete grouped Git handoff.

## Validation Plan

Commands:

```powershell
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; vendor\bin\pint --dirty --format agent; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\doc-gardening.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: contract validation/type generation, Laravel feature/unit tests, WXT Vitest, TypeScript compile, WXT build, harness check.
- Screenshots or video: not required unless automated extension UI smoke support becomes available during this work.
- Logs: verify no new logs include prompts, full transcripts, translations, token payloads, raw audio paths, or install IDs.
- Metrics or traces: not required for this synchronous workflow.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-11 | Use a curated static ISO catalog under `packages/contracts` as the shared source of truth. | Keeps source/target validation and extension UI aligned without adding runtime backend endpoints or provider-specific catalog coupling. |
| 2026-05-11 | Keep `sourceLanguage` as requested and add optional `detectedSourceLanguage`. | Preserves cache semantics for Auto detect requests while exposing useful provider output to the UI/history. |
| 2026-05-11 | Represent Chinese as one broad `zh` option. | Matches the user-approved v1 scope and avoids premature dialect/script branching. |
| 2026-05-11 | Default source/target to Auto detect -> English. | Makes the expanded app useful immediately without forcing setup before first generation. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-11 | Plan created after loading `phased-implementation-v2`, `git-group-commits`, review policy, project guardrails, backend Boost routing, local Laravel skills, ElevenLabs Scribe docs, and OpenAI structured output docs. | `.\scripts\agent\doctor.ps1` passed; baseline `.\scripts\agent\check.ps1` passed with contracts, 52 backend tests, 33 extension tests, TypeScript compile, and WXT build. |
| 2026-05-11 | Added shared language catalog, schema sync script, updated contract enums/fixtures/types, and backend catalog reader. | `packages/contracts npm run check` passed during implementation. |
| 2026-05-11 | Updated backend validation, migration, models/resources/history, Scribe request handling, detected language normalization, cache keys, same-language skip behavior, and OpenAI prompts/tests. | `php artisan test --compact` passed: 57 tests, 338 assertions. |
| 2026-05-11 | Replaced popup source dropdown with searchable Learning/Translation language pickers, persisted target language, sent source/target to backend, and added catalog tests. | Extension `npm test` passed: 9 files, 37 tests; `npm run compile` passed. |
| 2026-05-11 | Updated durable docs for many-to-many languages, detected source language, quality caveats, and generated contract/database references. | Edited product, architecture, frontend, reliability, observability, security, quality, generated docs, release readiness, and guardrail references. |
| 2026-05-11 | Completed simplification/readability review and fixed findings. | `git diff --check` passed; removed unused popup language helper; moved fake target-language tracking onto the translation test provider; expanded contract enum sync validation for history and detected language schemas. |
| 2026-05-11 | Ran final stack validation after simplification fixes. | `npm run check` in `packages/contracts` passed; `php artisan test --compact` passed with 57 tests and 338 assertions; extension `npm test` passed with 9 files and 37 tests; extension `npm run compile` passed; extension `npm run build` passed; `.\scripts\agent\check.ps1` passed. |

## Completion Notes

- What changed: Added a shared 185-entry language catalog with 8 Supported real languages and 176 Experimental languages, expanded contracts/source-target validation, persisted optional detected source language, generalized backend transcription/enrichment to many-to-many pairs, added same-language skip behavior, replaced the popup source dropdown with searchable Learning/Translation language pickers, and updated durable docs.
- Validation results: Contracts, backend tests, extension tests, TypeScript compile, WXT build, and `.\scripts\agent\check.ps1` passed.
- Simplicity/readability review: Fixed misplaced test tracking state, removed an unused UI helper, and broadened contract enum sync assertions so the generated schemas cannot drift quietly.
- Residual risk: Live provider quality for Experimental languages still needs manual public-video testing with real ElevenLabs/OpenAI credentials.
- Follow-up debt: Add browser screenshot smoke coverage when the extension UI smoke harness exists.
