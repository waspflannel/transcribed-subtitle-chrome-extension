# Plan: Phase 06 - Translation And Arabic Learning Data

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-28

## Goal

Enrich generated subtitle cues with English translation and Arabic learning metadata, then render the learning interactions in the overlay.

This phase turns the synchronized subtitle layer into the actual learning product: Arabic source line, English translation, romanization/gloss settings, hover preview, and click/tap pinned token detail.

## Scope

- In scope:
  - Laravel AI SDK OpenAI-backed enrichment provider.
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

- [ ] Laravel enrichment service uses Laravel AI SDK with OpenAI as the first target provider.
- [ ] Enrichment output is structured and validated before storage.
- [ ] Each cue has an English translation when enrichment succeeds.
- [ ] Arabic cues include token metadata where available.
- [ ] Missing token fields are omitted or represented safely; UI does not show `null` placeholders.
- [ ] Dialect is stored as `unknown` or a detected value but hidden from normal UI.
- [ ] Overlay renders source text and translation.
- [ ] Romanization and gloss settings default on and can be toggled.
- [ ] Hover preview works for tokens.
- [ ] Click/tap pinned detail works for tokens.
- [ ] Provider failures map to stable public errors.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Related plans: `phase-05-generated-track-and-overlay-sync.md`, `phase-07-hardening-and-release-readiness.md`
- Known risks:
  - Model output may be incomplete or inconsistent without strict structured validation.
  - Arabic tokenization quality varies by dialect and orthography.
  - Hover UI can become cluttered if every available field is always displayed.

## Implementation Steps

- [ ] Inspect track/cue schema from Phase 05.
- [ ] Define enrichment structured output schema.
- [ ] Implement Laravel AI SDK enrichment provider.
- [ ] Add provider fakes for tests.
- [ ] Add output validation and retry/failure policy.
- [ ] Store enriched tracks.
- [ ] Update track response to include translations and tokens.
- [ ] Render translation in overlay.
- [ ] Implement token boundaries and interaction targets.
- [ ] Implement hover preview.
- [ ] Implement click/tap pinned detail.
- [ ] Connect romanization/gloss settings to display.
- [ ] Add tests and browser validation evidence.

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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |

## Completion Notes

- What changed:
- Validation results:
- Residual risk:
- Follow-up debt:
