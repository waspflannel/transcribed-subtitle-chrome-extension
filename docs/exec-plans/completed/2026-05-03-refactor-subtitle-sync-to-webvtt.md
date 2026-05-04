# Plan: Refactor subtitle sync to WebVTT

Status: completed
Owner: agent
Created: 2026-05-03
Last updated: 2026-05-03

## Goal

Refactor subtitle synchronization to use browser-native WebVTT/TextTrack timing instead of manually checking
`video.currentTime` against cue ranges in the extension.

The backend should request WebVTT from Whisper-compatible OpenAI transcription output, persist that WebVTT with the
generated track, and expose it through the canonical track response. The extension should attach that WebVTT as a
hidden `<track>` on the YouTube video element and render the custom overlay from `TextTrack` `cuechange` events.

## Scope

- In scope:
- OpenAI Whisper VTT transcription request path.
- VTT validation/parsing into canonical cue records.
- Track persistence/response schema update for `webVtt`.
- Extension hidden WebVTT track binding.
- Removal of manual active-cue binary-search/animation-loop sync code.
- Focused tests and docs updates.
- Out of scope:
- Translation and Arabic learning metadata.
- User timing correction tools.
- Native YouTube caption UI replacement.
- Provider comparison or fallback model routing.

## Acceptance Criteria

- [x] Backend requests `response_format=vtt` from `whisper-1` for source transcription.
- [x] Backend persists and returns WebVTT on generated tracks.
- [x] Backend still validates VTT cue timing/text before storage and API response.
- [x] Extension attaches a hidden WebVTT track to the YouTube video.
- [x] Overlay updates from `TextTrack` active cue changes, not manual time polling.
- [x] Old manual sync utility/tests are removed.
- [x] Validation passes across backend, extension, contracts, and harness checks.

## Relevant Context

- Product docs:
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/phase-05-generated-track-and-overlay-sync.md`
- Known risks:
- `vtt` output is currently a `whisper-1` capability; the current default `gpt-4o-transcribe-diarize` does not support it.
- Browser `TextTrack` loading from Blob URLs must be cleaned up on route changes to avoid leaking object URLs.
- Phase 06 enrichment may need to map translation/token data back to VTT cue IDs.

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
vendor\bin\pint --dirty --format agent
npm run check
php artisan test --filter=OpenAiWebVttTranscriptionServiceTest --compact
php artisan test --filter=TimestampedSubtitleTrackGeneratorTest --compact
php artisan test --filter=SubtitleJobApiTest --compact
php artisan test --compact
npm test
npm run compile
npm run build
.\scripts\agent\check.ps1
.\scripts\agent\doc-gardening.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests:
- Screenshots or video:
- Logs:
- Metrics or traces:

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-03 | Switch transcription model default to `whisper-1` for the WebVTT path. | Current OpenAI docs show `vtt` output is supported by `whisper-1`; the existing diarized model cannot return VTT. |
| 2026-05-03 | Keep canonical `cues` while adding `webVtt`. | The extension needs WebVTT for native sync, while existing API/resource/tests and future enrichment still need structured cue records. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-03 | Plan created and refined after checking OpenAI, WXT, Laravel docs, local Laravel AI SDK source, and current Phase 05 implementation. | Context7 docs; `app/backend/vendor/laravel/ai/src/Gateway/OpenAi/OpenAiGateway.php`; current dirty diff showed a `$tPhis` typo in `SubtitleJobService.php`. |
| 2026-05-03 | Backend refactored to direct OpenAI WebVTT transcription, WebVTT persistence, schema response support, and simplified cue generation. | `OpenAiWebVttTranscriptionServiceTest`, `TimestampedSubtitleTrackGeneratorTest`, `SubtitleJobApiTest` passed. |
| 2026-05-03 | Extension refactored to attach hidden WebVTT tracks and update the overlay from `TextTrack` `cuechange`; manual sync utility and tests were removed. | `npm test`, `npm run compile`, `npm run build` passed. |
| 2026-05-03 | Removed unused `laravel/ai` dependency and updated architecture, reliability, observability, security, quality, guardrail, and future-phase docs for the direct WebVTT path. | `composer remove laravel/ai` updated `composer.json`/`composer.lock`; `.\scripts\agent\doc-gardening.ps1` passed. |
| 2026-05-03 | Full validation and PR readiness completed. | `vendor\bin\pint --dirty --format agent`; `npm run check`; `php artisan test --compact` passed 23 tests/170 assertions; extension tests passed 12 tests; `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`. |

## Completion Notes

- What changed: Backend now requests OpenAI `response_format=vtt` with the configured `whisper-1` default, parses WebVTT into timestamped segments, persists `web_vtt`, returns `track.webVtt`, and keeps structured source-only cues. Extension now attaches generated WebVTT as a hidden browser `TextTrack` and renders active source text from `cuechange` events.
- Validation results: `vendor\bin\pint --dirty --format agent`, `npm run check`, focused backend tests, `php artisan test --compact`, extension `npm test`, `npm run compile`, `npm run build`, `.\scripts\agent\check.ps1`, `.\scripts\agent\doc-gardening.ps1`, and `.\scripts\agent\verify-pr.ps1` all passed.
- Simplicity/readability review: Removed custom active-cue binary search, playback event listeners, animation-frame loop, split/merge cue heuristics, Laravel AI transcription adapter, and unused `laravel/ai` dependency. The remaining path is one provider request, one WebVTT parser, one cue validator, and one browser-native track binding.
- Residual risk: Real provider smoke still depends on local `yt-dlp`, `ffmpeg`, and `OPENAI_API_KEY`; browser TextTrack behavior should still be visually smoke-tested on a live YouTube page before release.
- Follow-up debt: `TD-004` now tracks deliberate future AI SDK/provider integration reassessment; `TD-005` still tracks the blocked real provider proof.

