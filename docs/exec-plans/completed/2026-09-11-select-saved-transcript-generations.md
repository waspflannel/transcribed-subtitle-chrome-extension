# Plan: Select saved transcript generations

Status: completed
Created: 2026-09-11

## Goal and scope

Add a transcript dropdown for saved generations of the same video: Auto → English (Luna), Auto → English (Cerebras), and other language pairs. Each option selects an exact job and updates the transcript and overlay without a generation request. Preserve next-generation settings. No provider or pipeline changes.

## Decisions

- Extend the owner-scoped history endpoint with an optional video filter. Return all completed, unexpired generations readable by the current backend. Keep global history capped at 25.
- Load track metadata only for the list, then fetch the chosen track by job ID.
- List on transcript change or explicit refresh, avoiding extra calls on each panel poll. Discard stale list responses.
- Reuse the existing mutation claim to exclude simultaneous generation/correction changes. Check session, tab, current track, and local reset ownership before publishing; remember the selected track per video.
- Backend skill rule review delegated to the existing review agent. FormRequest validation and lightweight eager-loading follow its recommendations.

## Acceptance criteria

- [x] Identical language pairs from different models stay separate.
- [x] Choosing a generation updates the overlay and remembered track without creating a job.
- [x] Load failures preserve the displayed transcript; stale responses cannot replace a different tab/account's state.
- [x] Filtered lists include more than 25 results and exclude unavailable/foreign jobs.
- [x] Full harness and final review pass.

## Validation

`scripts/agent/check.ps1` passed: 508 backend tests (4,021 assertions), 241 extension tests, contract checks, TypeScript compile, and Chrome extension build. Log: `app/backend/storage/logs/saved-generations-check.log` (ignored).

## Completion notes

- Native labeled selector preserves distinct job IDs, shows provider names, supports refreshing saved options, and leaves the active transcript visible on errors.
- Ownership tests cover delayed selection after navigation, session changes, local reset, and failed fetches. Correction-in-progress switching is blocked.
- UI tests cover language/model labels, exact job selection, list retry, correction disabling, and stale account responses. Options are retained through normal panel polls so an open dropdown is not rebuilt.
- Current display drafts and quick-fix selections reset when the track changes.
- No migrations, new dependencies, provider requests, or backend restart performed. Deploy backend code and reload the built extension together.
- Model descriptions, the saved-generation API, and the transcript selector are grouped into separate reviewable commits.
