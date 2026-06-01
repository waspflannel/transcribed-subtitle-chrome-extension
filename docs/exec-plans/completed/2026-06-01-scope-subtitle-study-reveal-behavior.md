# Plan: Scope subtitle study reveal behavior

Status: completed
Owner: agent
Created: 2026-06-01
Last updated: 2026-06-01

## Goal

Correct the subtitle study blur interactions so the overlay no longer treats the whole rail as one reveal surface. Token cards should reveal token text plus that token's romanization on token hover/focus/pin, full cue romanization should reveal as its own layer, and translation should reveal as its own layer.

Keep the existing Study settings, replay, copy, and token-card enrichment behavior intact while tightening the hover pause/reveal boundaries to match the requested learning workflow.

## Scope

- In scope:
- Overlay markup/CSS selectors for token-card, full cue romanization, and translation blur reveal scope.
- Content/overlay hover pause wiring needed to avoid blank rail hover acting like word hover.
- Focused unit tests and durable docs for the corrected interaction model.
- Out of scope:
- Backend generation, shared API contracts, billing, or word-card data shape changes.
- New keyboard shortcut or vocabulary-review systems.

## Acceptance Criteria

- [x] Blank overlay rail hover does not reveal blurred source, romanization, or translation text.
- [x] Token-card blur reveals only the hovered/focused/pinned token text and token romanization.
- [x] Full cue romanization blur reveals only when the full romanization line is hovered/focused.
- [x] Translation blur reveals the translation layer when translation text is hovered/focused.
- [x] Existing replay/copy controls, token preview, pinned/pending/failed token states, and duplicate translation suppression remain covered.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `docs/FRONTEND.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-06-01-subtitle-study-controls.md`
- Known risks:
- Hover/focus CSS can accidentally broaden reveal scope through parent selectors.
- Pause/resume should remain tied to deliberate study targets, not blank overlay space.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: extension unit tests plus TypeScript compile/build; full harness check before handoff.
- Screenshots or video: not expected for this selector-only bugfix unless validation exposes layout changes.
- Logs: command output for validation.
- Metrics or traces: not applicable.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-01 | Treat token cards, full cue romanization, and translation as three separate reveal surfaces. | Matches the user's clarified study workflow and avoids coupling token romanization to the full romanization line. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-01 | Plan created and scope refined from user feedback. | Existing overlay CSS used broad `.rail:hover` and paused selectors; docs still described overlay-wide reveal. |
| 2026-06-01 | Implemented scoped reveal and removed obsolete manual-pause reveal control. | Overlay markup now carries source/romanization/translation blur modifiers; popup Study no longer exposes reveal-on-pause. |
| 2026-06-01 | Split token romanization from the full cue romanization reveal surface. | Token romanization now uses the token-card blur group instead of the full cue romanization group. |
| 2026-06-01 | Validation passed and self-review completed. | `npm test`, `npm run compile`, `npm run build` in `app\extension`; `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`; `.\scripts\agent\doc-gardening.ps1`. |

## Completion Notes

- What changed: Scoped blur reveal to the requested surfaces: token text plus token romanization reveals per token, full cue romanization reveals only on its own line, translation reveals as a translation layer, and blank rail/manual pause no longer reveal text. Hover pause no longer starts on blank rail hover, and the obsolete reveal-on-pause setting was removed from local settings and the popup.
- Validation results: `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location` passed; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings.
- Simplicity/readability review: Kept the change local to existing overlay CSS/markup, content hover callbacks, settings normalization, popup bindings, and focused tests; no new dependencies or backend contracts were added.
- Residual risk: No browser screenshot was captured for this selector-only bugfix; the existing release-readiness checklist still tracks overlay visual QA.
- Follow-up debt: No new debt added.

