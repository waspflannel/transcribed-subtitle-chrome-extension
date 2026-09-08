# Plan: Preserve pasted lyrics during alignment

Status: completed
Owner: agent
Created: 2026-09-07
Last updated: 2026-09-07

## Goal

Fix the repeated alignment failure reported for M8vDwlHigJA and separate the transcript error banner from the toolbar.

## Evidence and Scope

The local log records attempt 5da92e34-a6ba-4d52-8429-653b2493aff4 failing both alignment calls with source_text_gap on track 19 / job 29 (49 cues). Generated text skipped authoritative pasted content. Failed attempt text was cleared, so exact-paste reproduction is unavailable. Preserve original subtitles on any failure; do not republish the job during diagnosis.

## Implementation

- [x] Replace generated lyric text with numbered part boundaries in the private alignment response.
- [x] Reconstruct authoritative text on the server; validate indices, cue identity, length, song match, and completeness.
- [x] Keep long unspaced strings together at grapheme boundaries.
- [x] Include safe numeric boundary feedback on the existing bounded retry.
- [x] Add 12px vertical spacing to correction notices.
- [x] Update Hindi, punctuation, malformed-boundary, and combining-mark regressions.
- [x] Update product behavior documentation.
- [x] Run full harness checks and refresh local workers.

## Validation

Focused correction/API tests passed: 134 tests, 810 assertions before three additional regression tests. The three new tests passed (10 assertions). Pint completed. Browser QA used the real panel with a mock failed correction: at 320px, toolbar-to-banner gap is 12px and there is no horizontal overflow. Screenshot: app/extension/.output/lyrics-review/banner-320.png.

## Decisions and Risks

The model chooses timing boundaries only; the server owns lyric text. This removes text omission during model transcription without relaxing publication validation. Timing alignment still depends on provider quality and may reject unreliable or incomplete input. Exact submitted lyrics were requested but are not available. Existing unrelated Watch edits are preserved.

## Completion Notes

Full scripts/agent/check.ps1 passed: 411 backend tests (2852 assertions), 172 extension tests, contract validation, TypeScript compilation, and production extension build. git diff --check passed. Log: app/backend/storage/logs/local-runtime/lyrics-boundaries-full-check.log.

Confirmed no active jobs or corrections and all six queues empty, then gracefully restarted all 31 existing local workers with their original executable and arguments. All are running with empty stderr; backend server and environment were preserved. The extension must be reloaded to pick up the rebuilt banner CSS. The exact failed paste has not been rerun, and the saved track was not modified.
