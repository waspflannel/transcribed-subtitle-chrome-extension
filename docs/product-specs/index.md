# Product Specs

## Product Summary

- Product name: transcribed-subtitle-extension
- Primary user: language learners watching public YouTube videos.
- Primary problem: YouTube captions are often missing, inaccurate, poorly segmented, or not useful for language study.
- Core promise: Generate AI subtitle tracks from YouTube audio for a user-selected subtitle language or Auto detect, translate word cards into a user-selected target language, and render a synchronized overlay with timed subtitles, pronunciation metadata when available, and word-level study data on demand.

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

- Current architecture: `../../ARCHITECTURE.md`
- Many-to-many language refactor record: `../exec-plans/completed/2026-05-11-many-to-many-language-refactor.md`
- Phase index: `../exec-plans/active/00-phase-index.md`

The root `revamped-design-document.md` and `detailed-design-document.md` files are historical pre-refactor notes. They do not override the current source-language plus target-language workflow.

## First Release Scope

- YouTube watch pages only.
- Public videos only.
- User-triggered subtitle generation.
- Backend Laravel job pipeline.
- Backend YouTube audio acquisition.
- Timestamped transcription.
- Selectable subtitle/source language, defaulting to Auto detect.
- Selectable translation/target language, defaulting to English.
- Word-card metadata in the selected target language on demand, or for the full track when explicitly enabled.
- Non-Latin-script romanization as best-effort display metadata.
- Synchronized in-page overlay.

## Explicit Non-Goals

- Netflix or other platforms.
- Real-time live captioning.
- User accounts or cloud sync.
- Vocabulary review system.
- Subtitle editing.
- Direct provider calls from the extension.
