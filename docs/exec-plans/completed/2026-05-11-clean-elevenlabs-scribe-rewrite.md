# Plan: Clean ElevenLabs Scribe Rewrite

Status: completed
Owner: agent
Created: 2026-05-11
Last updated: 2026-05-11

## Goal

Rebuild the useful behavior from `codex/elevenlabs-scribe-transcription` on top of `main` without carrying over the branch's accidental complexity.

The finished workflow should keep a synchronous first-release path: the extension explicitly starts generation for a public YouTube video, the backend acquires temporary audio, ElevenLabs Scribe produces timed transcript words, the backend persists a generated WebVTT track with cue/token data, and the overlay renders browser-native synced subtitles. OpenAI is used only for romanization and learning cards: best-effort romanization in default mode, on-click card generation in default mode, and full-card generation only when the popup setting is enabled.

## Scope

- In scope:
- ElevenLabs Scribe transcription and word-timing normalization.
- Multilingual source selection for `auto`, `ar`, `en`, `es`, `pt`, `fr`, `de`, and `it`; target language remains English.
- Backend job status/progress persistence and `GET /v1/subtitle-jobs`.
- Default `on_demand` generation with transcript-first token stubs, best-effort OpenAI romanization, and clicked-token enrichment.
- Explicit `full` enrichment mode that blocks until all word cards are generated.
- Popup Generate/Jobs tabs, backend-synced progress, Full word cards toggle, source-language selector, and soft OpenAI-credit warning.
- Focused tests and docs for the new durable behavior.
- Out of scope:
- Backend cancellation and Cancel UI.
- Separate transcript panel, raw transcript export, or raw transcript persistence.
- Queues, WebSockets, provider failover, accounts, vocabulary review, or non-YouTube platforms.
- Reusing the messy branch as the code base.

## Acceptance Criteria

- [ ] Implementation branch is based on `main`; the old branch is used only as reference.
- [x] Default generation calls ElevenLabs for transcription and OpenAI only for best-effort romanization when non-Latin script is present.
- [x] Clicking an unenriched word calls the backend `/v1/learning-tokens`, patches the stored track, and avoids duplicate OpenAI calls for cached metadata.
- [x] Full word-card mode calls OpenAI for all cue cards before returning the generated track.
- [x] Backend job history/progress is authoritative for the popup Jobs tab; Cancel is absent.
- [x] Contracts, backend, extension, and docs agree on the same API shape and source-language list.
- [x] `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1` pass.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/product-specs/release-readiness.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/FRONTEND.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/phase-05-generated-track-and-overlay-sync.md`, `docs/exec-plans/completed/phase-06-translation-and-arabic-learning-data.md`, messy reference branch `codex/elevenlabs-scribe-transcription`
- Known risks:
  - OpenAI costs can spike if full-card mode is accidentally defaulted or on-click cache keys are unstable.
  - Scribe word timestamps must be normalized into readable, non-overlapping WebVTT cues.
  - Backend progress remains synchronous stage reporting, not true asynchronous job execution.
  - Laravel Boost `search-docs` is unavailable in this session, so Context7 and local Boost skills are used for current library guidance.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement contract and backend transcription/progress slice.
- [x] Implement backend enrichment and learning-token slice.
- [x] Implement extension popup/jobs/on-click slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\doctor.ps1
.\scripts\agent\check.ps1
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: contract validation/type generation, backend feature/unit tests, extension Vitest, TypeScript compile, WXT build, full harness.
- Screenshots or video: not required unless UI rendering changes fail tests or manual inspection is needed.
- Logs: focused assertions for backend stage/provider logs without sensitive payloads.
- Metrics or traces: not required for this synchronous first-release path.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-11 | Start from `main`, not the messy Scribe branch. | The branch proves the desired behavior but carries duplicated state, oversized prompts, cancel semantics, and bloat that are cheaper to avoid than unwind. |
| 2026-05-11 | Use ElevenLabs only for transcription and OpenAI only for romanization/card generation. | Keeps provider responsibilities narrow and makes OpenAI credit usage easy to reason about. |
| 2026-05-11 | Keep backend Jobs/progress but remove Cancel. | Progress is user-visible and useful; cancel would be misleading without real backend cancellation infrastructure. |
| 2026-05-11 | Cache clicked-token cards by patching the stored backend track. | Prevents repeated OpenAI calls across reloads during the 30-day track lifetime without adding accounts or extra storage. |
| 2026-05-11 | Use separate purpose-specific AI agents/prompts for full cards, romanization, and clicked-token cards. | Smaller prompts and schemas reduce cost risk and make each output contract easier to validate. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-11 | Plan created and refined against project guardrails, local Boost skills, Context7 ElevenLabs/Laravel AI docs, and OpenAI cost guidance. | `docs/references/project-guardrails.md`, `app/backend/.ai/skills/subtitle-pipeline/SKILL.md`, Context7 `/websites/elevenlabs_io`, Context7 `/laravel/ai`, OpenAI latest-model guidance. |
| 2026-05-11 | Contracts updated for multilingual source selection, `enrichmentMode`, backend job history, and clicked-token enrichment. | `Push-Location .\packages\contracts; npm run check; Pop-Location` passed. |
| 2026-05-11 | Backend rewritten around ElevenLabs Scribe, Scribe word normalization, transcript-first default generation, best-effort romanization, full-card mode, job history/progress, and learning-token caching. | `Push-Location .\app\backend; php artisan test --compact; Pop-Location` passed with 50 tests. |
| 2026-05-11 | Extension popup/background/overlay updated for source selection, Full word cards, Jobs progress, and on-click token enrichment. | `Push-Location .\app\extension; npm test; npm run compile; Pop-Location` passed with 29 Vitest tests and TypeScript compile. |
| 2026-05-11 | Project docs updated to make Scribe/default-on-demand the current architecture. | `ARCHITECTURE.md`, `docs/FRONTEND.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/QUALITY_SCORE.md`, `app/backend/.ai/skills/subtitle-pipeline/SKILL.md`. |
| 2026-05-11 | Final validation and self-review completed. | `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, `.\scripts\agent\doc-gardening.ps1`, `git diff --check` passed. |

## Completion Notes

- What changed: contracts now expose source-language selection, enrichment mode, backend job history, and clicked-token enrichment; backend now uses ElevenLabs Scribe for transcription, transcript-first default generation, best-effort romanization, full-card opt-in, job progress, and cached learning-token patching; extension popup/background/overlay now use backend Jobs progress and on-click card generation.
- Validation results: contracts check passed; backend `php artisan test --compact` passed with 50 tests; extension `npm test` passed with 29 tests; TypeScript compile and WXT build passed; `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, `.\scripts\agent\doc-gardening.ps1`, and `git diff --check` passed.
- Simplicity/readability review: removed the Whisper transcription service, recursive enrichment splitting, and full-card default path; OpenAI calls are now isolated to romanization, full-card batches, and one-token cards.
- Residual risk: no browser screenshot/manual YouTube run was captured in this turn; live provider behavior still depends on valid ElevenLabs/OpenAI keys and public-video audio acquisition.
- Follow-up debt: add browser screenshot smoke coverage when the extension smoke harness exists; run the release public-video matrix before store submission.

