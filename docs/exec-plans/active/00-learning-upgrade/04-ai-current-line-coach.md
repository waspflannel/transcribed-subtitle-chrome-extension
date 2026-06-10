# Plan: Learning Upgrade 04 - AI Current Line Coach

Status: planned
Owner: agent
Created: 2026-06-03
Last updated: 2026-06-03

## Goal

Add a focused AI coach for the active subtitle cue. Learners should be able to ask fixed, high-value questions about the current line without leaving YouTube: explain grammar, show literal vs natural translation, simplify, quiz me, and provide a pronunciation/shadowing prompt.

This phase makes the product feel like a contextual tutor while avoiding the complexity and safety surface of freeform chat.

## Scope

- In scope:
  - Fixed AI coach modes for active cue: grammar explanation, literal vs natural translation, simplify, quiz, and pronunciation/shadowing prompt.
  - Backend AI endpoint keyed by authenticated account, `trackId`, `cueId`, source/target language, mode, and prompt/model version.
  - Structured output schemas and validation for each mode or a shared mode-aware response.
  - Overlay and side-panel Transcript UI for mode buttons, loading state, cached result display, retry on failure, and copy/save hooks where appropriate.
  - Caching to avoid repeated provider calls for the same cue/mode/version.
  - Sanitized logs and rate limits.
- Out of scope:
  - Freeform chat or arbitrary learner questions.
  - Multi-turn conversation memory.
  - AI provider calls from the extension.
  - Rewriting generated subtitle tracks based on coach output.
  - Content discovery and non-YouTube platforms.

## Acceptance Criteria

- [ ] Learners can request each fixed coach mode from the active cue in the overlay and side-panel Transcript view.
- [ ] Backend validates account ownership of the track/cue before provider calls.
- [ ] Coach requests use bounded cue context and do not log prompts, full transcripts, provider payloads, or generated learning content.
- [ ] Responses are structured, validated, user-safe, and mode-specific.
- [ ] Repeated requests for the same account/track/cue/mode/version reuse cached responses.
- [ ] Failure states are stable, retryable, and do not expose provider internals.
- [ ] Existing token enrichment, cue translation, romanization, saved items, and practice modes remain separate workflows.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`, `docs/SECURITY.md`, `docs/OBSERVABILITY.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
  - `docs/exec-plans/active/00-learning-upgrade/01-keyboard-transcript-accessibility-foundation.md`
  - `docs/exec-plans/active/00-learning-upgrade/02-vocabulary-sentence-mining-anki-export.md`
  - `docs/exec-plans/active/00-learning-upgrade/03-listening-shadowing-speaking-practice.md`
  - `docs/exec-plans/active/00-learning-upgrade/00-roadmap-index.md`
- Known risks:
  - AI coach can duplicate existing token-card behavior if modes are too broad.
  - Freeform chat pressure may creep into v1; fixed modes should stay strict.
  - Provider cost can grow with repeated line-level requests unless caching and rate limits are in place.

## Planned Interfaces

- Backend API:
  - `POST /v1/cue-coach`
- Request inputs:
  - `trackId`, `cueId`, and mode: `grammar`, `literal_natural`, `simplify`, `quiz`, or `pronunciation`.
- Response output:
  - Mode, cache status, safe title, short explanation, structured fields for the selected mode, and timestamps.
- Cache key:
  - Account ID, track ID, cue ID, mode, source language, target language, model, and prompt version.

## Implementation Steps

- [ ] Inspect current learning-token agent patterns, Laravel AI provider boundaries, response validators, cache strategy, contracts, and extension token-detail UI.
- [ ] Define fixed coach modes, structured output schemas, validation rules, and public failure messages.
- [ ] Add backend route, request, controller, service, AI agent(s), cache, rate limits, and sanitized tracing/logging.
- [ ] Add canonical contracts, fixtures, OpenAPI entries, generated TypeScript types, and backend contract validation tests.
- [ ] Add extension API method and runtime messages for cue coach requests.
- [ ] Add overlay and side-panel Transcript UI for mode buttons, loading, cached result, retry, copy, and save-to-study-library hooks where already available.
- [ ] Add focused backend and extension tests.
- [ ] Capture browser screenshots or video for coach modes and failure states.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Update product/frontend/security/observability docs and quality score if needed.
- [ ] Run validation and record evidence.
- [ ] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; php artisan test --compact --filter=CueCoach; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: contracts, backend coach validation/cache/provider tests, extension UI/request tests, full harness.
- Screenshots or video: each coach mode, cached response state, retry/failure state, compact/mobile display.
- Logs: sanitized coach request events without prompt, transcript, response payload, or provider payload content.
- Metrics or traces: request counts, cache hit rate, provider latency, and estimated provider cost if available.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-03 | Use fixed AI coach mode buttons for v1. | Fixed modes are easier to validate, cache, and keep useful in the compact overlay. |
| 2026-06-03 | Keep coach output separate from subtitle track generation. | Coach explanations should not mutate the generated track or destabilize playback sync. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-03 | Plan created. | `docs/exec-plans/active/00-learning-upgrade/04-ai-current-line-coach.md` |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:

