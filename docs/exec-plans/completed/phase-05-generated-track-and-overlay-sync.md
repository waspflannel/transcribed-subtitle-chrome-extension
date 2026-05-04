# Plan: Phase 05 - Generated Track And Overlay Sync

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-05-02

## Goal

Turn timestamped transcripts into validated generated subtitle tracks and render source subtitle cues in sync with YouTube playback.

This phase connects real backend transcription output to the extension overlay. It should prove the core subtitle experience before translation and word-level learning data are added.

## Scope

- In scope:
  - Transcript-to-cue segmentation.
  - Generated subtitle track validation.
  - Track storage and lookup by compatibility key.
  - Track expiration metadata.
  - Extension track loading.
  - Efficient active cue selection.
  - Overlay rendering for source text.
  - Sync behavior for play, pause, seek, playback speed changes, and missing cues.
  - Sync diagnostics for obvious drift or duration mismatch.
- Out of scope:
  - Translation.
  - Arabic token analysis.
  - Hover/click token detail UI.
  - User correction or timing adjustment tools.
  - Final release hardening.

## Acceptance Criteria

- [x] Backend converts timestamped transcript segments into subtitle cues.
- [x] Cue validation rejects invalid timing and empty source text.
- [x] Generated track response conforms to the canonical schema.
- [x] Backend stores completed tracks with 30-day expiration metadata.
- [x] Backend lookup reuses compatible completed tracks.
- [x] Extension loads ready tracks from the backend.
- [x] Overlay displays the active source cue based on `video.currentTime`.
- [x] Overlay updates correctly during play, pause, and seek.
- [x] Overlay avoids DOM updates when the active cue has not changed.
- [x] Sync issues are logged with structured diagnostics.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `phase-04-audio-acquisition-and-transcription-proof.md`, `../active/phase-06-translation-and-arabic-learning-data.md`
- Known risks:
  - Provider segment timing may be too coarse or poorly segmented for readable subtitles.
  - Generated audio timeline may differ from YouTube playback timeline.
  - Overly complex segmentation would slow delivery; start with simple readable rules.

## Implementation Steps

- [x] Inspect `TimestampedTranscript` output from Phase 04.
- [x] Implement simple cue segmentation rules.
- [x] Validate cue timing, ordering, duration, and text.
- [x] Create generated track records from cue output.
- [x] Add compatibility-key lookup and track reuse.
- [x] Update subtitle generation to write real source-only tracks.
- [x] Update extension API client to load real tracks.
- [x] Implement active cue selection.
- [x] Render source cue in overlay.
- [x] Add sync listeners and lightweight playing loop.
- [x] Add drift/duration diagnostics.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Add tests and validation evidence; live browser screenshot deferred in residual risk.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
php artisan test
npm run build
```

Evidence to capture:

- Tests: segmentation, track validation, lookup reuse, active cue selection.
- Screenshots or video: overlay rendering source subtitles on a public YouTube video.
- Logs: track ready and overlay sync events.
- Metrics or traces: processing duration and track cue count.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Add source-only overlay before translation. | This isolates subtitle timing risk from AI enrichment risk. |
| 2026-05-02 | Keep Phase 05 on the existing synchronous `POST /v1/subtitle-jobs` API instead of adding a separate lookup route. | The current contract already returns a completed generated track and the backend compatibility key can reuse completed tracks without expanding the API surface. |
| 2026-05-02 | Use source text as temporary `translatedText` until Phase 06 enrichment. | The canonical cue schema requires `translatedText`; Phase 05 is source-only and Phase 06 owns real translation. |
| 2026-05-02 | Use direct YouTube `<video>` detection for sync and avoid polling/mutation observers. | Project guardrails prefer direct URL/video element paths until a concrete YouTube integration failure requires a fallback. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-05-02 | Reviewed harness, architecture, product, frontend, reliability, observability, security, review, and project guardrails. Loaded Laravel Boost routing plus `laravel-best-practices`, `laravel-specialist`, `laravel-patterns`, `laravel-security`, and `subtitle-pipeline` guidance. Used Context7 for Laravel 13 API resource/model/logging conventions and WXT content script/background messaging docs because Boost `search-docs` was unavailable in this session. | `.\scripts\agent\doctor.ps1` passed; `.\scripts\agent\check.ps1` baseline passed. |
| 2026-05-02 | Refined implementation into three slices: backend cue segmentation/validation and structured track diagnostics; extension active-cue selection/rendering and video sync listeners; focused backend/extension tests plus final harness validation. | Current code inspection: `TimestampedTranscript`, `TimestampedSubtitleTrackGenerator`, `SubtitleJobService`, `OverlayShell`, `content.ts`, `background.ts`, and contracts. |
| 2026-05-02 | Implemented backend source-only generated track segmentation, validation, track reuse logging, and duration mismatch diagnostics. | `php artisan test --compact tests\Unit\TimestampedSubtitleTrackGeneratorTest.php` passed; `php artisan test --compact tests\Feature\SubtitleJobApiTest.php` passed. |
| 2026-05-02 | Implemented extension active cue selection, video event sync, play-time animation loop, no-cue rendering, and structured console diagnostics for duration mismatch and significant cue gaps. | `npm test` passed; `npm run compile` passed in `app/extension`. |
| 2026-05-02 | Completed docs, quality score, final validation, and PR-readiness verification. | `php artisan test` passed with 22 tests/165 assertions; `npm run build` passed; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings. |

## Completion Notes

- What changed: Backend transcription segments now become validated source-only generated cues with simple split/merge rules, 30-day track expiration, compatible track reuse, and structured track diagnostics. The extension now binds ready tracks to the YouTube video element, selects active cues from `video.currentTime`, updates for play/pause/seek/rate/time changes, clears gaps, avoids repeat DOM content writes, and logs sync diagnostics.
- Validation results: `php artisan test` passed with 22 tests and 165 assertions; `npm test` passed with 5 files and 13 tests; `npm run compile` passed; `npm run build` passed; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings.
- Simplicity/readability review: Kept the existing synchronous API and WXT entrypoint shape; added one focused backend generator path and one focused extension sync utility; avoided lookup routes, polling, mutation observers, correction tooling, translation, and token UI.
- Residual risk: No live public YouTube screenshot/video was captured in this local run because a real generated track still depends on local audio tooling and provider credentials. The sync behavior is covered by executable unit tests and production extension build evidence.
- Follow-up debt: None added for Phase 05. Phase 06 should replace source-as-translation placeholders with real enrichment and add provider/enrichment diagnostics without logging generated learning content.
