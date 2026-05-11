# Product Specs

## Product Summary

- Product name: transcribed-subtitle-extension
- Primary user: Arabic learners watching public YouTube videos.
- Primary problem: YouTube captions are often missing, inaccurate, poorly segmented, or not useful for language study.
- Core promise: Generate AI subtitle tracks from YouTube audio and render a synchronized learning overlay with timed subtitles, romanization when available, and word-level study data on demand.

## Specs

Add one file per meaningful product area or workflow.

- First release readiness: `release-readiness.md`

Recommended format:

- User problem.
- Desired outcome.
- Main flow.
- Edge cases.
- Acceptance criteria.
- Validation evidence.

## Current Baseline

- Product pivot: `../../revamped-design-document.md`
- Detailed design: `../../detailed-design-document.md`
- Phase index: `../exec-plans/active/00-phase-index.md`

## First Release Scope

- YouTube watch pages only.
- Public videos only.
- User-triggered subtitle generation.
- Backend Laravel job pipeline.
- Backend YouTube audio acquisition.
- Timestamped transcription.
- English word-card metadata on demand, or for the full track when explicitly enabled.
- Arabic-script romanization as best-effort display metadata.
- Synchronized in-page overlay.

## Explicit Non-Goals

- Netflix or other platforms.
- Real-time live captioning.
- User accounts or cloud sync.
- Vocabulary review system.
- Subtitle editing.
- Direct provider calls from the extension.
