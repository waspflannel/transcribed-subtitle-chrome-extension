# Plan: Simplification Refactor Cleanup

Status: completed
Owner: agent
Created: 2026-05-04
Last updated: 2026-05-04

## Goal

Apply the accepted simplification review findings without changing the first-release product behavior. The cleanup removes fake synchronous job status from the public contract, separates backend logging and WebVTT parsing from core workflow code, simplifies cue generation control flow, moves overlay positioning into CSS, and records phase-ahead popup controls as explicit debt.

## Scope

- In scope: subtitle job response contract cleanup, backend workflow logging extraction, WebVTT parser extraction, cue generation loop simplification, overlay positioning CSS cleanup, tests/fixtures/schema updates, and tech-debt documentation.
- Out of scope: implementing Romanization/Gloss behavior, changing request or track/cue response shapes beyond removing `JobResponse.status`, adding new logging packages/channels, async job delivery, or new popup UI behavior.

## Acceptance Criteria

- [x] `JobResponse.status` is removed from schemas, OpenAPI, fixtures, generated TypeScript types, Laravel resource output, and tests.
- [x] Subtitle workflow logging uses Laravel's built-in `Log` facade through a small domain logger, and `SubtitleJobService::generateTrack` reads as the main workflow rather than a log payload builder.
- [x] WebVTT parsing/validation lives outside `OpenAiWebVttTranscriptionService`, with direct parser unit coverage.
- [x] `TimestampedSubtitleTrackGenerator::cues()` uses a plain loop with no mutable state captured by a collection callback.
- [x] Overlay host positioning is expressed in Shadow DOM CSS using `data-position`, with no imperative `positionHost()` style rewrite.
- [x] Romanization/Gloss no-op controls are documented as open phase-ahead UI debt.
- [x] Repository validation passes via `.\scripts\agent\check.ps1`.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/OBSERVABILITY.md`, `docs/references/project-guardrails.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: review findings supplied in chat on 2026-05-04.
- Known risks: contract cleanup requires synchronized schema, fixtures, generated types, backend tests, and extension tests; parser extraction should not weaken WebVTT validation or error context.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: remove fake completed status across contracts, backend resource, fixtures, generated types, and tests.
- [x] Slice 2: document Romanization/Gloss as phase-ahead UI debt.
- [x] Slice 3: extract subtitle workflow logging behind a small Laravel `Log` facade wrapper.
- [x] Slice 4: split WebVTT parsing into a dedicated parser service and update tests.
- [x] Slice 5: simplify cue generation loop.
- [x] Slice 6: move overlay positioning into CSS.
- [x] Run validation and record evidence.
- [x] Complete review notes and archive the plan.

## Validation Plan

Commands:

```powershell
cd packages/contracts; npm run check
cd ..\..\app\backend; php artisan test --compact
cd ..\extension; npm test; npm run compile; npm run build
.\scripts\agent\check.ps1
```

Evidence:

- Tests: `npm run check` in `packages/contracts`; `php artisan test --compact` in `app/backend`; `npm test`, `npm run compile`, and `npm run build` in `app/extension`.
- Harness: `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`; `.\scripts\agent\doc-gardening.ps1`.
- Screenshots or video: not applicable; overlay behavior was a CSS relocation with compile/build validation.
- Logs: not applicable.
- Metrics or traces: not applicable.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-04 | Keep Romanization/Gloss controls visible, but document them as phase-ahead debt. | User explicitly chose to keep them for future use while acknowledging future phases should avoid unused UI. |
| 2026-05-04 | Use Laravel's built-in `Log` facade inside a small domain workflow logger. | Context7 Laravel 13 docs confirm `Log` supports standard levels and structured context; no package or custom channel is needed. |
| 2026-05-04 | Remove only `JobResponse.status`; keep request, track, cue, and runtime message shapes stable. | Matches the accepted plan and limits public contract churn. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-04 | Plan created. |  |
| 2026-05-04 | Refined plan against current code, guardrails, and Laravel logging docs. | Read AGENTS/docs; queried Context7 Laravel 13 logging docs. |
| 2026-05-04 | Removed fake completed status from contract/resource/tests and regenerated TypeScript contract types. | `npm run check` in `packages/contracts` passed. |
| 2026-05-04 | Extracted subtitle workflow logging and WebVTT parsing, simplified cue generation, and moved overlay positioning to CSS. | Backend focused tests passed; extension compile/build passed. |
| 2026-05-04 | Completed full validation and PR readiness checks. | `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, and `.\scripts\agent\doc-gardening.ps1` passed. |

## Completion Notes

- What changed: Removed `JobResponse.status`; documented popup phase-ahead controls; extracted `SubtitleWorkflowLogger`; extracted `WebVttTranscriptParser`; rewrote cue generation as a loop; moved overlay placement rules into CSS.
- Validation results: All planned contract, backend, extension, harness, PR verification, and doc-gardening checks passed.
- Simplicity/readability review: Core flows now read closer to their domain steps: provider request logic, parser logic, workflow logging, cue generation, and overlay placement are separated.
- Residual risk: The public contract removal is intentionally breaking for any client still reading `JobResponse.status`.
- Follow-up debt: Romanization/Gloss controls remain visible but inactive and are tracked in `docs/exec-plans/tech-debt-tracker.md`.
