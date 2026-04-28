# Plan: Phase 05 - Generated Track And Overlay Sync

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-28

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

- [ ] Backend converts timestamped transcript segments into subtitle cues.
- [ ] Cue validation rejects invalid timing and empty source text.
- [ ] Generated track response conforms to the canonical schema.
- [ ] Backend stores completed tracks with 30-day expiration metadata.
- [ ] Backend lookup reuses compatible completed tracks.
- [ ] Extension loads ready tracks from the backend.
- [ ] Overlay displays the active source cue based on `video.currentTime`.
- [ ] Overlay updates correctly during play, pause, and seek.
- [ ] Overlay avoids DOM updates when the active cue has not changed.
- [ ] Sync issues are logged with structured diagnostics.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Related plans: `phase-04-audio-acquisition-and-transcription-proof.md`, `phase-06-translation-and-arabic-learning-data.md`
- Known risks:
  - Provider segment timing may be too coarse or poorly segmented for readable subtitles.
  - Generated audio timeline may differ from YouTube playback timeline.
  - Overly complex segmentation would slow delivery; start with simple readable rules.

## Implementation Steps

- [ ] Inspect `TimestampedTranscript` output from Phase 04.
- [ ] Implement simple cue segmentation rules.
- [ ] Validate cue timing, ordering, duration, and text.
- [ ] Create generated track records from cue output.
- [ ] Add compatibility-key lookup and track reuse.
- [ ] Update job processing to write real source-only tracks.
- [ ] Update extension API client to load real tracks.
- [ ] Implement active cue selection.
- [ ] Render source cue in overlay.
- [ ] Add sync listeners and lightweight playing loop.
- [ ] Add drift/duration diagnostics.
- [ ] Add tests and browser validation evidence.

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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |

## Completion Notes

- What changed:
- Validation results:
- Residual risk:
- Follow-up debt:
