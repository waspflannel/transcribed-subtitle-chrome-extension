# Plan: Transcript actions and saved generation deletion

Status: completed
Created: 2026-09-14

## Goal and scope

Replace the Actions menu with Generate again and Lyric correction buttons above transcript search. Allow deleting the selected saved generation, including the last one. Stop restoring lyrics deleted from the website when a YouTube page is opened or refreshed, without adding deletion polling.

## Decisions

- Use a delete option in the native saved-generation selector, with confirmation and the existing operation claim for switching/deletion.
- Add owner-scoped DELETE /v1/subtitle-generations/{jobId}; preserve the job-cancellation endpoint. Share dashboard deletion through SubtitleJobService so locking, usage retention, cascades, and post-commit cleanup stay consistent.
- Validate remembered lyrics on content page entry, including settled YouTube navigation. Normal panel snapshots do not validate cached generations. Missing generations are forgotten and recovered from the existing history API; network failures show retry guidance without destroying the cache.
- Apply ponytail and Laravel best-practices skills. The Laravel skill explicitly calls for a subagent to inspect its rules; delegated backend/contract work followed owner scoping, account-before-job locks and existing transaction patterns. Context7 supplied current Laravel 13 documentation because Boost search tools were unavailable.

## Acceptance criteria

- [x] Two direct buttons appear above search in the requested order and open their existing screens.
- [x] The selected generation can be deleted even when it is the only one.
- [x] Deletion restores another available saved generation or generation setup and updates the video overlay.
- [x] Page entry rejects deleted remembered lyrics without periodic deletion polling.
- [x] Operation failures, tab/account changes and concurrent mutations cannot overwrite a newer transcript.
- [x] Browser evidence, full harness and final diff review pass.

## Validation

- Backend: 30 targeted tests passed for saved deletion, dashboard deletion, existing cancellation, and filtered history. Contract check and Pint passed.
- Extension: selector, transport, background deletion/recovery, and existing panel tests passed. Covered last/remaining generation, delete failures, correction exclusion, account/tab/reset changes, cache validation on page entry, offline retry, and late responses after selection.
- Full scripts/agent/check.ps1 passed: 566 backend tests (4,498 assertions), 273 extension tests, contracts, TypeScript compile, and Chrome build. Log: app/backend/storage/logs/generation-deletion-check.log (ignored).

## Completion notes

Browser validation used the actual panel entrypoint and styles with stubbed extension messages. Both action screens, deletion with fallback, deletion of the last generation, and 320px/360px layouts passed. Screenshots: docs/exec-plans/evidence/2026-09-14-generation-deletion/. API persistence and background state behavior were checked separately by the tests. Final diff review passed. No dependencies, migrations, generation requests, or production deployment. Reload the rebuilt extension with the updated backend to use the new endpoint.
