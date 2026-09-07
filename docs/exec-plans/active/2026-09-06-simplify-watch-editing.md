# Simplify Watch editing

## Goal

Keep the transcript central and separate word corrections from whole-track tasks. User approved implementation for personal UI review on 2026-09-06.

## Decisions

- Reuse existing backend requests, validation, confirmation, cancellation and progress stages.
- Put Edit on each line; reveal tokens only for that line and render the correction form below it.
- Replace the global Edit modes and Regenerate toggle with one transcript actions menu.
- Whole-track forms and detailed progress occupy their own Watch screen. Back preserves replacement text.
- Running replacement defaults to a compact progress strip beside the usable transcript.
- Playback highlights without scrolling or stealing editor focus.

## Validation

- Extension unit tests, TypeScript and production build are required.
- Browser QA uses the actual panel entrypoint and CSS with a local synthetic background, without calling providers or changing user tracks.
- Checked 320px and 360px layouts, line edit, draft and focus during playback, retryable save error, replacement confirmation, compact progress and detailed cancellation.
- Screenshots: app/extension/.output/watch-review/{transcript,edit,replace}.png (local ignored artifacts).
- Native extension/backend end-to-end review remains with the user.

## Results

- All 170 extension tests pass; TypeScript and production build pass.
- Browser review passed for editing, draft preservation across Back, replacement confirmation/progress/cancel, and reopening generation after cancellation. No browser script errors.
- Full harness: contracts and documentation pass; backend reports 400 passed and the same two pre-existing LyricsCorrectionContinuationTest cancellation failures (lines 457 and 475). Log: app/backend/storage/logs/local-runtime/watch-ui-check.log.
