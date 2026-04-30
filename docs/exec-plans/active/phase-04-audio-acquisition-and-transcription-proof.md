# Plan: Phase 04 - Audio Acquisition And Transcription Proof

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-30

## Goal

Validate the highest-risk backend path: acquiring audio for public YouTube videos, enforcing duration limits, deleting raw audio, and producing timestamped transcription output suitable for subtitle synchronization.

This phase intentionally reaches real transcription early. It should answer whether Laravel AI SDK can provide the timestamped output we need, and if not, introduce a Laravel-side OpenAI transcription adapter without changing product contracts.

## Scope

- In scope:
  - Backend YouTube audio acquisition for public videos.
  - Public-video and duration validation.
  - 60-minute maximum duration enforcement.
  - Temporary raw audio storage during processing only.
  - Raw audio deletion on success and failure.
  - `TranscriptionProvider` interface.
  - Laravel AI SDK transcription proof.
  - Laravel-side OpenAI adapter if Laravel AI SDK cannot expose subtitle-ready timestamp output.
  - Timestamped transcript normalization.
  - Provider timeout and failure handling.
  - Real-provider proof tests on selected public videos.
- Out of scope:
  - Translation.
  - Arabic token analysis.
  - Final cue segmentation polish.
  - Tab audio capture.
  - Private, login-gated, age-restricted, or region-restricted videos.

## Acceptance Criteria

- [ ] Backend rejects invalid, unsupported, non-public, or too-long videos.
- [ ] Backend acquires audio for at least one public YouTube test video.
- [ ] Raw audio is deleted after transcription succeeds.
- [ ] Raw audio is deleted after transcription fails.
- [ ] Backend can call a real transcription provider with backend-held secrets.
- [ ] Backend produces `TimestampedTranscript` with sorted segments.
- [ ] Each segment has valid `startSeconds`, `endSeconds`, and text.
- [ ] Transcription provider failures map to stable public errors.
- [ ] Logs explain acquisition/transcription failures without dumping full transcripts by default.
- [ ] The implementation records whether Laravel AI SDK is sufficient for timestamped transcription.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `phase-03-laravel-job-api-and-persistence.md`, `phase-05-generated-track-and-overlay-sync.md`
- Known risks:
  - YouTube audio acquisition can be brittle.
  - Long videos can exceed provider file size or duration limits.
  - Laravel AI SDK transcription examples may not expose segment timestamps; the product still requires them.
  - OpenAI `whisper-1` supports verbose timestamped output, but adapter behavior must be verified during implementation.

## Implementation Steps

- [ ] Inspect the job skeleton from Phase 03.
- [ ] Implement an audio source service for backend YouTube acquisition.
- [ ] Add video metadata/duration validation.
- [ ] Enforce 60-minute max duration before provider calls.
- [ ] Store raw audio in a controlled temporary location.
- [ ] Ensure cleanup runs on success, failure, and thrown exceptions.
- [ ] Implement `TranscriptionProvider`.
- [ ] Attempt timestamped transcription through Laravel AI SDK.
- [ ] If needed, implement a Laravel-side OpenAI adapter for verbose timestamped output.
- [ ] Normalize provider output into `TimestampedTranscript`.
- [ ] Add failure mapping and diagnostics.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Run real-provider proof cases and record results.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
php artisan test
```

Evidence to capture:

- Tests: audio validation, cleanup, provider failure mapping, transcript normalization.
- Screenshots or video: not required.
- Logs: one successful acquisition/transcription proof and one controlled failure proof.
- Metrics or traces: transcription latency, audio duration, provider usage if available.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Keep timestamped transcript as the contract even if the SDK wrapper is simpler. | Subtitle sync depends on timing, so the integration must adapt to the product contract rather than weakening it. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:
