# Product Specs

## Product Summary

- Product name: Transcribed Subtitle Extension for beta marketing and account surfaces; repository/package name remains `transcribed-subtitle-extension`.
- Primary user: language learners watching public YouTube videos.
- Primary problem: YouTube captions are often missing, inaccurate, poorly segmented, or not useful for language study.
- Core promise: Generate AI subtitle tracks from YouTube audio for a user-selected subtitle language or Auto detect, optionally translate subtitle cues and word cards into a user-selected target language, and render a synchronized overlay with timed subtitles, pronunciation metadata when available, and word-level study data on demand.

## Specs

Add one file per meaningful product area or workflow.

- First release readiness: `release-readiness.md`
- Lyrics editing and full replacement: `lyrics-editing.md`

Recommended format:

- User problem.
- Desired outcome.
- Main flow.
- Edge cases.
- Acceptance criteria.
- Validation evidence.

## Current Baseline

- Current architecture: `../../ARCHITECTURE.md`
- Many-to-many language refactor record: [2026-05-11-many-to-many-language-refactor.md (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/completed/2026-05-11-many-to-many-language-refactor.md)
- Phase index: [00-phase-index.md (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/completed/00-phase-index.md)

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
- Learner-friendly cue tokenization for every generated transcript, with tokenizer-agent boundaries, structural validation that accepts model corrections to the transcript, same-agent split retry for invalid multi-cue batches, and visible generation failure when a single cue remains unreliable.
- Optional non-Latin-script romanization when the user enables romanization, with visible generation failure when enabled romanization output is invalid.
- Synchronized in-page overlay.
- Local study controls for blurring token cards, full cue romanization, and translation, revealing token text plus token romanization per token on token hover/focus/pin while revealing full cue romanization and translation by layer on their own hover/focus, temporarily pausing playback on source-word hover by default, replaying/copying the active cue, navigating cues with keyboard shortcuts, and inspecting/searching the generated Transcript view in the side panel.
- Caption display preferences for local overlay font size, density, and high-contrast rendering.
- Laravel SaaS web app for beta registration, billing, usage, recent jobs, support-safe job details, and public marketing/legal pages.

## Explicit Non-Goals

- Netflix or other platforms.
- Real-time live captioning.
- Cloud sync beyond the account, billing, extension-token, usage, and job-history records required for the paid beta.
- General subtitle editing. The first release includes the narrow **Use pasted lyrics** correction flow for a completed generated track; it preserves the pasted words, reuses existing timing, and rebuilds derived learning data before atomic replacement.
- Direct provider calls from the extension.

## Deferred Learning Upgrades

The first release does not include a vocabulary review system, sentence mining, Anki export, listening/speaking practice, or AI current-line coaching. These remain accepted post-first-release product work; their old implementation plans were removed on 2026-09-09. Requirements are retained below.

- Saved words and sentences: account-owned, idempotent saves with source/target languages and cue/video identity; filtering, learning states, deletion, safe CSV export and explicit AnkiConnect export with recoverable failures.
- Listening and speaking: cue auto-pause, repeat, AB loops and listen-then-reveal must preserve subtitle timing and keyboard access. Recording requires an explicit action, permission, cancellation and bounded clips; backend scoring must validate ownership/input and delete temporary audio on success or failure.
- Current-line coaching: fixed, structured modes with bounded cue context, account/track/cue ownership, safe errors and versioned response reuse. Keep coaching separate from token enrichment, translation and practice.
- Beta and public launch: onboarding/support, approved legal and pricing copy, truthful marketing, feedback handling and release evidence remain required. Growth work and public launch follow beta findings and an explicit launch decision.
