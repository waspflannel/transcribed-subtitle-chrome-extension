# Plan: Subtitle Study Controls

Status: completed
Owner: agent
Created: 2026-06-01
Last updated: 2026-06-01

## Goal

Add a study-control layer to the existing YouTube subtitle overlay without changing backend generation, contracts, billing, or provider flows.

The extension should let learners blur token cards, full cue romanization, and translation independently, reveal token text plus token romanization per token and full cue romanization/translation by layer on direct hover/focus, pause playback when a word is hovered or focused by default, and expose compact replay/copy controls in the overlay. The popup should gain a dedicated Study tab for these controls while keeping generation-time controls separate.

## Scope

- In scope:
- Local extension settings for blur and hover-pause controls.
- Content/background/popup messaging needed for Study settings to persist and broadcast to the active tab.
- Overlay ready-state markup, CSS, and interactions for blur reveal, word-hover pause, replay cue, and copy cue.
- Popup Study tab for blur toggles and hover pause.
- Focused extension tests, TypeScript/build validation, harness validation, and docs updates.
- Out of scope:
- Backend API, Laravel services, billing, shared contracts, storage, and subtitle generation pipeline.
- Vocabulary review, saved words, subtitle browser, keyboard shortcut system, drag/resize, or non-YouTube platform support.
- Manual-pause reveal behavior.
- Browser smoke harness debt beyond any practical local visual QA evidence captured during this slice.

## Acceptance Criteria

- [x] Local settings persist `blurSourceWords`, `blurRomanization`, `blurTranslation`, and `pauseOnWordHover` with safe defaults.
- [x] Overlay quick controls expose replay/copy only; blur settings live in the popup Study tab.
- [x] Blurred token cards, full cue romanization, and translation keep layout stable; token text plus token romanization reveal per token on hover/focus/pin, while full cue romanization and translation reveal by layer on their own hover/focus.
- [x] Hovering, focusing, or clicking a source token pauses the active YouTube video when hover pause is enabled and resumes only when the extension caused the pause.
- [x] Replay seeks to the active cue start using the local timing offset and starts playback.
- [x] Copy writes source text plus available romanization and non-duplicate visible translation, with visible success/failure status.
- [x] Existing token click enrichment, pending/failed states, duplicate translation suppression, overlay positions, and mobile/compact behavior remain intact.
- [x] Popup has a stable six-tab layout with Study controls separate from Generate and Settings.
- [x] Extension tests, compile, build, repo harness check, PR verification, and docs checks pass or have recorded blockers.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/product-specs/release-readiness.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/FRONTEND.md`, `docs/DESIGN.md`, `docs/references/project-guardrails.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-11-transcription-overlay-ui-revamp.md`, `docs/exec-plans/completed/2026-05-21-saas-roadmap-phase-02-extension-frontend-upgrade.md`, `docs/exec-plans/completed/2026-05-25-stitch-led-cinematic-frontend-revamp.md`
- Known risks:
  - Overlay controls could crowd the rail or conflict with token popovers at compact/mobile widths.
  - Pausing on hover/focus can be annoying if too broad; scope it to token interactions only.
  - Clipboard APIs can fail in content scripts or non-secure contexts; failure must be visible without breaking playback.
  - Browser screenshot automation is still tracked separately as `TD-003` and `TD-007`.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: extend settings/message boundaries and content-script video state/actions.
- [x] Slice 2: add overlay blur/reveal rendering, quick controls, replay/copy, and token hover pause wiring.
- [x] Slice 3: add popup Study tab and keep Generate/Settings semantics clean.
- [x] Slice 4: add/update focused extension tests and durable frontend docs.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Run validation and record evidence.
- [x] Complete review notes and archive this plan.

## Validation Plan

Commands:

```powershell
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: focused extension unit tests, full extension test suite, TypeScript compile, WXT build, full harness, PR verification.
- Screenshots or video: popup Study tab and overlay blur states if a practical browser fixture is available; otherwise record the existing screenshot-harness debt.
- Logs: not expected unless runtime validation exposes extension console warnings.
- Metrics or traces: not applicable; local frontend behavior only.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-01 | Keep all blur settings off by default, turn hover pause on by default, and remove manual-pause reveal from the Study controls. | Preserves current visible subtitle behavior while preventing manual pause from unexpectedly revealing hidden study text. |
| 2026-06-01 | Hover pause resumes only when the pointer leaves after the extension paused playback. | Keeps the quick hover study loop reversible without resuming videos the user paused manually. |
| 2026-06-01 | Add a popup Study tab and compact overlay controls, but defer a full shortcut/subtitle-browser console. | Delivers the requested controls without expanding into vocabulary review or navigation systems. |
| 2026-06-01 | Keep all changes local to extension settings/runtime UI. | Blur/reveal/playback controls do not require backend contract or generation changes. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-01 | Plan created and refined from the user-approved implementation plan. | `.\scripts\agent\doctor.ps1` passed; relevant frontend/design/review/guardrail docs inspected. |
| 2026-06-01 | Implemented settings/message boundary, content-script video pause/replay/copy actions, overlay study controls, and popup Study tab. | `Push-Location .\app\extension; npm run compile; Pop-Location` passed. |
| 2026-06-01 | Added focused tests and durable docs for Study controls. | `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location` passed; popup Study screenshot captured at `%TEMP%\tse-study-controls-shots\popup-study.png`. |
| 2026-06-01 | Completed full validation and self-review. | `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, and `.\scripts\agent\doc-gardening.ps1` passed. |
| 2026-06-01 | Corrected hover behavior and overlay controls after review. | Overlay blur toggles removed; hover pause now resumes on pointer leave only when the extension caused the pause; manual pause no longer reveals blurred text by default. |
| 2026-06-01 | Scoped reveal behavior after review. | Token text plus token romanization is token-scoped, full cue romanization/translation reveal only on their own layer hover/focus, and blank rail hover does not reveal or pause. |

## Completion Notes

- What changed: Added extension-local study settings, temporary video hover-pause/replay/copy actions, overlay blur/reveal controls, a popup Study tab, focused tests, and durable docs. Overlay blur toggles were removed after review because those controls already live in popup settings, and reveal behavior was later scoped so blank rail hover and manual pause do not reveal blurred text.
- Validation results: Baseline and final `.\scripts\agent\check.ps1` passed; focused extension `npm test`, `npm run compile`, and `npm run build` passed; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings.
- Simplicity/readability review: Kept the feature local to existing WXT settings/message/content/overlay/popup boundaries, avoided backend or contract changes, and used direct DOM/platform APIs without new dependencies.
- Residual risk: Real loaded-extension overlay screenshot coverage still depends on the existing `TD-003`/`TD-007` browser smoke debt. A static popup Study screenshot was captured at `%TEMP%\tse-study-controls-shots\popup-study.png`.
- Follow-up debt: No new debt added.

