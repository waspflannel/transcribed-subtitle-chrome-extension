# Plan: Learning Upgrade 03 - Listening Shadowing Speaking Practice

Status: planned
Owner: agent
Created: 2026-06-03
Last updated: 2026-06-03

## Goal

Turn generated subtitle cues into active listening and speaking practice. Learners should be able to loop short sections, auto-pause after lines, hide/reveal text, shadow the current cue, record a short attempt, and receive backend-scored feedback.

This phase adds deliberate practice modes while preserving the existing passive watch-and-study workflow.

## Scope

- In scope:
  - Overlay/transcript controls for auto-pause after each cue, repeat current cue, repeat N times, AB loop from cue range, slow current cue, and listen-then-reveal mode.
  - Shadowing prompts tied to the active cue, using source text, romanization, translation, and timing data already present in the generated track.
  - Opt-in microphone recording for short attempts with explicit privacy copy.
  - Backend speech scoring endpoint for authenticated users with strict duration and size limits, temporary audio deletion, sanitized logs, rate limits, and safe JSON results.
  - Practice attempt state in the extension and account-scoped backend history if needed for user-visible progress.
  - Tests and browser visual QA for practice controls and failure states.
- Out of scope:
  - Browser-only speech recognition as the primary scorer.
  - Long-form recording, continuous dictation, conversation chat, or live captioning.
  - Storing raw microphone audio after scoring.
  - Comparing against native-speaker waveform/audio clips unless separately accepted.
  - Content discovery and non-YouTube platforms.

## Acceptance Criteria

- [ ] Learners can enable auto-pause after each cue and resume/replay with keyboard and visible controls.
- [ ] Learners can repeat the active cue and AB loop a cue range without breaking native WebVTT sync or timing offset behavior.
- [ ] Listen-then-reveal hides selected layers until the cue is replayed or the learner requests reveal.
- [ ] Shadowing mode shows a clear prompt and does not expose microphone controls until the user explicitly starts a recording action.
- [ ] Microphone recording requests permission only when needed, records only a short bounded clip, and can be cancelled.
- [ ] Backend scoring rejects oversized, too-long, unauthenticated, unsupported-language, and malformed attempts with stable public errors.
- [ ] Temporary audio is deleted after scoring success or failure; logs do not include raw audio paths, transcripts, prompts, or generated practice content.
- [ ] Scoring response returns user-safe feedback such as transcript match, timing, pronunciation notes, and retry suggestion without exposing provider internals.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`, `docs/SECURITY.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
  - `docs/exec-plans/active/00-learning-upgrade/01-keyboard-transcript-accessibility-foundation.md`
  - `docs/exec-plans/active/00-learning-upgrade/02-vocabulary-sentence-mining-anki-export.md`
  - `docs/exec-plans/active/00-learning-upgrade/00-roadmap-index.md`
- External references:
  - `https://developer.chrome.com/docs/extensions/develop/concepts/declare-permissions`
  - `https://developer.mozilla.org/en-US/docs/Web/API/MediaStream_Recording_API`
  - `https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API`
- Known risks:
  - Microphone permission changes Chrome Web Store privacy posture.
  - Speech scoring can add provider cost and support load.
  - Browser recording formats vary; backend validation must accept only supported safe formats.

## Planned Interfaces

- Backend API:
  - `POST /v1/speaking-attempts`
- Request inputs:
  - `trackId`, `cueId`, optional token or cue range, source/target language, client-recorded duration, and short audio upload.
- Response output:
  - Attempt ID, score bands, recognized text, cue-match summary, pronunciation/timing feedback, retry suggestion, and user-safe error details.
- Limits:
  - Short clips only, strict upload size cap, authenticated account ownership, rate limits, and temporary storage cleanup.

## Implementation Steps

- [ ] Inspect current content-script video controls, overlay interactions, settings, API boundaries, backend audio handling, and logging rules.
- [ ] Add local practice settings and runtime messages for auto-pause, repeat, AB loop, slow current cue, listen-then-reveal, and shadowing mode.
- [ ] Implement practice playback controls in content script using the active video and existing track cue timings.
- [ ] Add overlay/transcript UI for practice controls with keyboard parity.
- [ ] Add microphone recording flow using browser media APIs with explicit privacy copy and cancellation.
- [ ] Add backend contracts, request validation, temporary audio handling, provider/service scoring adapter, sanitized trace/log events, and public errors.
- [ ] Add tests for playback mode state, recording state, backend validation, scoring response shape, cleanup, and rate-limit/failure behavior.
- [ ] Capture browser screenshots or video for practice modes and mic permission/failure copy.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Update product/frontend/security/reliability/observability docs and quality score if needed.
- [ ] Run validation and record evidence.
- [ ] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; php artisan test --compact --filter=SpeakingAttempt; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: contracts, backend attempt validation/scoring/cleanup tests, extension playback/recording tests, full harness.
- Screenshots or video: auto-pause, AB loop, listen-then-reveal, shadowing prompt, recording start/cancel, scoring result, scoring failure.
- Logs: sanitized scoring events without raw audio, prompt, transcript, or content payloads.
- Metrics or traces: attempt counts, scoring latency, failure reasons, and provider cost estimates if scoring uses a paid provider.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-03 | Include microphone scoring in v1, but keep scoring backend-owned. | The user selected mic scoring now; backend ownership keeps privacy, limits, and provider behavior enforceable. |
| 2026-06-03 | Keep browser speech recognition out of the primary v1 path. | Browser support and vendor behavior are uneven; it can be revisited as a fallback after backend scoring works. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-03 | Plan created. | `docs/exec-plans/active/00-learning-upgrade/03-listening-shadowing-speaking-practice.md` |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:

