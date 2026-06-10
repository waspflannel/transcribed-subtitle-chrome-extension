# Product Specs

## Product Summary

- Product name: Transcribed Subtitle Extension for beta marketing and account surfaces; repository/package name remains `transcribed-subtitle-extension`.
- Primary user: language learners watching public YouTube videos.
- Primary problem: YouTube captions are often missing, inaccurate, poorly segmented, or not useful for language study.
- Core promise: Generate AI subtitle tracks from YouTube audio for a user-selected subtitle language or Auto detect, optionally translate subtitle cues and word cards into a user-selected target language, and render a synchronized overlay with timed subtitles, pronunciation metadata when available, and word-level study data on demand.

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

The `docs/history/revamped-design-document.md` and `docs/history/detailed-design-document.md` files are historical pre-refactor notes. They do not override the current source-language plus target-language workflow.

## First Release Scope

- YouTube watch pages and YouTube Shorts only.
- Public videos only.
- User-triggered subtitle generation.
- Backend Laravel job pipeline.
- Backend YouTube audio acquisition.
- Timestamped transcription.
- Selectable subtitle/source language, defaulting to Auto detect.
- Selectable translation/target language, defaulting to English.
- Optional cue-level subtitle translation into the selected translation/target language.
- Word-card metadata in the selected target language on demand, or for the full track when explicitly enabled.
- Learner-friendly cue tokenization for every generated transcript, with tokenizer-agent boundaries, structural/source-order validation, same-agent split retry for invalid multi-cue batches, and visible generation failure when a single cue remains unreliable.
- Optional non-Latin-script romanization when the user enables romanization, with visible generation failure when enabled romanization output is invalid.
- Synchronized in-page overlay.
- Local study controls for blurring token cards, full cue romanization, and translation, revealing token text plus token romanization per token on token hover/focus/pin while revealing full cue romanization and translation by layer on their own hover/focus, temporarily pausing playback on source-word hover by default, replaying/copying the active cue, navigating cues with keyboard shortcuts, and inspecting/searching the generated Transcript view in the side panel.
- Caption display preferences for local overlay font size, density, and high-contrast rendering.
- Laravel SaaS web app for beta registration, billing, usage, recent jobs, support-safe job details, and public marketing/legal pages.

## Explicit Non-Goals

- Netflix or other platforms.
- Real-time live captioning.
- Cloud sync beyond the account, billing, extension-token, usage, and job-history records required for the paid beta.
- Subtitle editing.
- Direct provider calls from the extension.

## Deferred Learning Upgrades

The first release does not include a vocabulary review system, sentence mining, Anki export, listening/speaking practice, or AI current-line coaching. These are now accepted as post-first-release product work and are tracked in `../exec-plans/active/00-learning-upgrade/`.
