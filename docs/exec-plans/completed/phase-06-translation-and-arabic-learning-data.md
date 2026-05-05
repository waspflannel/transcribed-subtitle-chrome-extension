# Plan: Phase 06 - Translation And Arabic Learning Data

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-05-05

## Goal

Enrich generated subtitle cues with English translation and Arabic learning metadata, then render the learning interactions in the overlay.

This phase turns the synchronized subtitle layer into the actual learning product: Arabic source line, English translation, romanization/gloss settings, hover preview, and click/tap pinned token detail.

## Scope

- In scope:
  - Laravel AI SDK OpenAI-backed enrichment provider using structured output where it fits the cue contract.
  - Dedicated cue-enrichment agent or prompt with structured output.
  - Translation from source cue text to target language.
  - Arabic token metadata for text, lemma, root, part of speech, romanization, gloss, and usage note when available.
  - Dialect detection stored as metadata and hidden from normal UI.
  - Validation of enriched cue output before storage.
  - Extension rendering of translation below source text.
  - Romanization and gloss settings.
  - Hover preview.
  - Click/tap pinned detail.
- Out of scope:
  - Vocabulary review system.
  - Subtitle editing.
  - User accounts or cloud sync.
  - Displaying dialect in normal UI.
  - Manual correction workflow.

## Acceptance Criteria

- [x] Laravel enrichment service uses Laravel AI SDK with OpenAI as the first target provider.
- [x] Enrichment output is structured and validated before storage.
- [x] Each cue has an English translation when enrichment succeeds.
- [x] Arabic cues include token metadata where available.
- [x] Missing token fields are omitted or represented safely; UI does not show `null` placeholders.
- [x] Dialect is stored as `unknown` or a detected value but hidden from normal UI.
- [x] Overlay renders source text and translation.
- [x] Romanization and gloss settings default on and can be toggled.
- [x] Hover preview works for tokens.
- [x] Click/tap pinned detail works for tokens.
- [x] Provider failures map to stable public errors.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `phase-05-generated-track-and-overlay-sync.md`, `phase-07-hardening-and-release-readiness.md`
- Known risks:
  - Model output may be incomplete or inconsistent without strict structured validation.
  - Arabic tokenization quality varies by dialect and orthography.
  - Hover UI can become cluttered if every available field is always displayed.

## Implementation Steps

- [x] Inspect track/cue schema from Phase 05.
- [x] Define enrichment structured output schema.
- [x] Implement Laravel AI SDK enrichment provider.
- [x] Add provider fakes for tests.
- [x] Add output validation and retry/failure policy.
- [x] Store enriched tracks.
- [x] Update track response to include translations and tokens.
- [x] Render translation in overlay.
- [x] Implement token boundaries and interaction targets.
- [x] Implement hover preview.
- [x] Implement click/tap pinned detail.
- [x] Connect romanization/gloss settings to display.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Add automated tests and record the browser validation gap.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
php artisan test
npm run build
```

Evidence to capture:

- Tests: structured output validation, enrichment failure mapping, token rendering, settings behavior.
- Screenshots or video: overlay with Arabic, English translation, hover preview, and pinned token detail.
- Logs: enrichment started/completed/failed events.
- Metrics or traces: enrichment latency and cue count.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Keep enrichment agent narrow. | Job state, storage, and UI decisions belong to application services, not the AI agent. |
| 2026-05-04 | Implement one synchronous OpenAI/Laravel AI enrichment pass before track storage. | The current API is synchronous and returns a completed track; queues, provider failover, and progress state remain out of scope until product evidence requires them. |
| 2026-05-04 | Store detected dialect on the track but keep it out of the extension-facing contract and overlay. | The phase requires stored dialect metadata while normal UI must not show it. |
| 2026-05-04 | Use the existing cue contract names (`translatedText`, `tokens`) and extend only missing token fields. | This preserves the canonical API boundary and avoids introducing a second response shape. |
| 2026-05-05 | Split and retry failed multi-cue enrichment batches. | Live provider proof showed partial structured output and connection failures on larger batches; recursively retrying smaller batches preserves strict validation while avoiding whole-video failure for one bad provider response. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-05-04 | Loaded `phased-implementation-v2`, Laravel Boost routing, local Laravel skills (`laravel-patterns`, `laravel-security`, `laravel-specialist`, `subtitle-pipeline`), Laravel AI SDK guidance, and project guardrails. Refined slices: backend enrichment and validation, contract token metadata, overlay learning interactions, validation and plan lifecycle. | `php artisan boost:list-skills`; Context7 `/laravel/ai` docs; `docs/references/project-guardrails.md`; `docs/quality/golden-principles.md` |
| 2026-05-04 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed: contracts check/build, 27 backend tests, 16 extension tests, TypeScript compile, WXT build. |
| 2026-05-04 | Implemented backend structured enrichment, hidden dialect persistence, stable enrichment failures, contracts for `root` and `usageNote`, overlay translation/token rendering, CSS hover previews, pinned token detail, and settings-backed romanization/gloss display. | Focused tests passed: `CueEnrichmentServiceTest`, `TimestampedSubtitleTrackGeneratorTest`, `SubtitleJobApiTest`, extension `webvtt-track.test.ts`, and `overlay.test.ts`. |
| 2026-05-04 | Package-level validation passed after implementation. | `npm run check` in `packages/contracts`; `php artisan test --compact` passed 32 tests; `npm test` passed 19 extension tests; `npm run compile`; `npm run build`. |
| 2026-05-04 | Final harness and PR-readiness checks passed after archiving the plan. | `.\scripts\agent\doc-gardening.ps1`; `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`. |
| 2026-05-05 | Post-implementation live proof hardened the synchronous flow. | `Kax_tVLW7TU` completed with 58 cues after split-retrying one malformed 8-cue batch; `y1wyPIAHhGQ` completed from a clean DB with 95 cues. Full `.\scripts\agent\check.ps1` passed afterward with 37 backend tests and 20 extension tests. |

## Completion Notes

- What changed: Added OpenAI/Laravel AI structured cue enrichment, validation and failure mapping, hidden `source_dialect` persistence, enriched cue storage, token contract fields, split-retry fallback for failed multi-cue enrichment batches, and overlay learning interactions for translation, token hover preview, pinned detail, romanization, and gloss settings.
- Validation results: Baseline `.\scripts\agent\check.ps1` passed before edits. Focused backend and extension tests passed during implementation. Package-level validation passed after implementation: contracts check/build, 32 backend tests, 19 extension tests, TypeScript compile, and WXT build. Final `.\scripts\agent\doc-gardening.ps1`, `.\scripts\agent\check.ps1`, and `.\scripts\agent\verify-pr.ps1` passed after the completed plan was archived. Post-proof validation passed with 37 backend tests, 20 extension tests, TypeScript compile, and WXT build.
- Simplicity/readability review: Kept the flow synchronous, used one provider boundary, preserved existing cue contract names, kept dialect out of the UI response, used CSS for hover previews to avoid pointer rerender churn, and added only a targeted split-retry fallback for observed provider failure modes.
- Residual risk: Browser screenshot proof is still not automated because the extension smoke-test gap remains tracked by `TD-003`. Timing quality still needs follow-up; live proof showed subtitles generated successfully, but some cue boundaries can drift from spoken audio.
- Follow-up debt: No new debt added. `TD-006` was closed because romanization/gloss controls are now wired to overlay behavior.
