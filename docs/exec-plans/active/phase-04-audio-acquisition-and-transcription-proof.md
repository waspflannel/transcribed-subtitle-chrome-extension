# Plan: Phase 04 - Audio Acquisition And Transcription Proof

Status: in_progress
Owner: agent
Created: 2026-04-28
Last updated: 2026-05-01

## Goal

Validate the highest-risk backend path: acquiring audio for public YouTube videos, enforcing duration limits, deleting raw audio, and producing timestamped transcription output suitable for subtitle synchronization.

This phase intentionally reaches real transcription early. It uses Laravel AI SDK transcription with OpenAI and normalizes the SDK's timestamped segments into the product subtitle contract.

## Scope

- In scope:
  - Backend YouTube audio acquisition for public videos.
  - Public-video and duration validation.
  - 60-minute maximum duration enforcement.
  - Temporary raw audio storage during processing only.
  - Raw audio deletion on success and failure.
  - Laravel AI SDK transcription proof through OpenAI.
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

- [x] Backend rejects invalid, unsupported, non-public, or too-long videos.
- [ ] Backend acquires audio for at least one public YouTube test video.
- [x] Raw audio is deleted after transcription succeeds.
- [x] Raw audio is deleted after transcription fails.
- [ ] Backend can call a real transcription provider with backend-held secrets.
- [x] Backend produces `TimestampedTranscript` with sorted segments.
- [x] Each segment has valid `startSeconds`, `endSeconds`, and text.
- [x] Transcription provider failures map to stable public errors.
- [x] Logs explain acquisition/transcription failures without dumping full transcripts by default.
- [x] The implementation records whether Laravel AI SDK is sufficient for timestamped transcription.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `phase-03-laravel-job-api-and-persistence.md`, `phase-05-generated-track-and-overlay-sync.md`
- Known risks:
  - YouTube audio acquisition can be brittle.
  - Long videos can exceed provider file size or duration limits.
  - Laravel AI SDK segment behavior must be verified with a real provider call, because subtitle sync requires stable start/end timestamps.
  - The SDK's OpenAI gateway currently requests diarized JSON when `diarize()` is enabled and maps returned segments into `TranscriptionSegment` objects.

## Implementation Steps

- [x] Inspect the synchronous generation service from Phase 03.
- [x] Implement an audio source service for backend YouTube acquisition.
- [x] Add video metadata/duration validation.
- [x] Enforce 60-minute max duration before provider calls.
- [x] Store raw audio in a controlled temporary location.
- [x] Ensure cleanup runs on success, failure, and thrown exceptions.
- [x] Implement Laravel AI SDK transcription service.
- [x] Request timestamped segments through Laravel AI SDK diarized transcription.
- [x] Normalize provider output into `TimestampedTranscript`.
- [x] Move provider identity and model defaults to Laravel AI SDK-native configuration.
- [x] Record deferred Laravel AI SDK features for later SDK-first adoption.
- [x] Add failure mapping and diagnostics.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
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
| 2026-05-01 | Use the Phase 04 Laravel Boost lenses: `laravel-best-practices`, `laravel-specialist`, `laravel-security`, and `ai-sdk-development`. | The phase touches Laravel services, API validation, raw audio storage, provider secrets, external process execution, logs, and transcription behavior. Generic auth, Horizon, Redis, and user-account recommendations remain out of scope. |
| 2026-05-01 | Keep the synchronous completed-job API from Phase 03 for this proof. | The current product contract returns a completed track or stable error. Real processing may force async delivery later, but Phase 04 can still prove audio acquisition, cleanup, and timestamped transcription without changing extension-facing contracts. |
| 2026-05-01 | Use Laravel AI SDK for transcription and request diarized OpenAI output. | The Laravel 13 AI SDK docs expose `Transcription::fromPath(...)->diarize()->generate(...)`; the installed SDK OpenAI gateway maps returned `segments` into timestamped `TranscriptionSegment` objects, which preserves the `TimestampedTranscript` contract without a custom HTTP adapter. |
| 2026-05-01 | Default `OPENAI_TRANSCRIPTION_MODEL` to `gpt-4o-transcribe-diarize`. | This matches the installed Laravel AI SDK OpenAI provider default for transcription and the `diarize()` request path used to obtain timestamped segments. |
| 2026-05-01 | Use `yt-dlp` as the first backend YouTube acquisition mechanism and keep it configurable. | Laravel has no built-in YouTube audio acquisition. A single external binary is the smallest inspectable proof path and avoids adding PHP package dependencies during this phase. |
| 2026-05-01 | Prefer Laravel AI SDK primitives before custom AI abstractions. | The SDK already provides provider enums, provider/model config, custom base URLs, transcription, events, fakes, agents, structured output, queues, files, vector stores, embeddings, reranking, tools, and failover. Phase 04 adopts the primitives needed for transcription and records the rest as deferred SDK-first integration points. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-05-01 | Started Phase 04 implementation. Loaded project docs, Phase 03 completion notes, Laravel Boost skills, Context7 docs for Laravel AI SDK and Laravel 13 process/HTTP testing, and current OpenAI audio transcription docs. Baseline harness check passed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` |
| 2026-05-01 | Confirmed local proof blockers before implementation: `yt-dlp`/`ffmpeg` are not installed and `.env` has no `OPENAI_API_KEY`. Code and automated fakes can validate behavior locally; real-provider proof remains blocked until those environment pieces exist. | `Get-Command yt-dlp`; `Get-Command ffmpeg`; `.env` inspection |
| 2026-05-01 | Implemented configurable YouTube audio acquisition with metadata/duration/public-video checks, controlled temporary audio storage, cleanup on success/failure, stable public errors, and structured stage logs. | `YouTubeAudioSourceTest`; `SubtitleJobApiTest` |
| 2026-05-01 | Implemented Laravel AI SDK transcription, timestamped transcript normalization, and source-timed proof track generation through the existing synchronous job API. Removed the obsolete mock generator path. | `LaravelAiTranscriptionServiceTest`; `TimestampedTranscriptNormalizerTest`; `php artisan test --compact` passed: 18 tests, 145 assertions |
| 2026-05-01 | Rechecked Laravel 13 AI SDK docs and installed package code, then replaced the direct OpenAI HTTP transcription adapter with `Laravel\Ai\Transcription::fromPath(...)->diarize()->generate(provider: 'openai', model: ...)`. | Context7 `/laravel/ai`; Laravel 13 AI SDK docs; `vendor/laravel/ai/src/Gateway/OpenAi/OpenAiGateway.php`; `LaravelAiTranscriptionServiceTest` |
| 2026-05-01 | Ran repository validation and docs checks after implementation. Real acquisition/transcription proof remains the only open Phase 04 slice because the local machine lacks `yt-dlp` and backend OpenAI credentials. | `.\scripts\agent\check.ps1`; `.\scripts\agent\doc-gardening.ps1`; `.\scripts\agent\verify-pr.ps1` |
| 2026-05-01 | Started Laravel AI SDK-native cleanup slice. Baseline harness check passed before edits. | `.\scripts\agent\check.ps1` |
| 2026-05-01 | Moved transcription model defaults into `config/ai.php`, switched provider identity to `Lab::OpenAI`, added project Boost subtitle-pipeline guidance, and recorded deferred SDK features as future SDK-first integration points. | `LaravelAiTranscriptionServiceTest` |
| 2026-05-01 | Removed Laravel AI SDK transcription event listeners to keep the proof path simple. Job-level stage logs remain the active observability surface; SDK events are documented as a future option only if needed. | User simplification review |
| 2026-05-01 | Validated the SDK-native cleanup slice. Real acquisition/transcription proof remains blocked by missing local `yt-dlp`/`ffmpeg` and backend OpenAI credentials. | `vendor\bin\pint --dirty --format agent`; `php artisan test --compact` passed: 20 tests, 148 assertions; `.\scripts\agent\check.ps1`; `.\scripts\agent\doc-gardening.ps1`; `.\scripts\agent\verify-pr.ps1` |
| 2026-05-01 | Simplified YouTube audio acquisition by requiring `yt-dlp` to report the downloaded file path and removing the fallback directory scan/path helper. Kept duration/public/live validation, temp containment, cleanup, MIME detection, and stable errors. | `YouTubeAudioSourceTest`; `SubtitleJobApiTest`; `php artisan test --compact` passed: 18 tests, 146 assertions; `.\scripts\agent\check.ps1` |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:
