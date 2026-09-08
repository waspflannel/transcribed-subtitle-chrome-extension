# Plan: Merge lyrics into smoothening fixes

Status: completed
Owner: agent
Created: 2026-09-08
Last updated: 2026-09-08

## Goal

Merge `codex/lyrics-editing-and-full-replacement` at `026e75a` into `smoothening-fixes` at `ded7cb2`, preserving both histories and the smoothing fixes.

## Scope

Merge the existing branch changes and resolve integration failures. No new product scope or deployment.

## Acceptance Criteria

- Both original branch heads are ancestors of the merge commit.
- Lyrics editing, exact generation-operation checks, active-cue synchronization, and account navigation remain present.
- Repository checks pass and the working tree is clean.

## Decisions

- Retain native panel ports and inline polling from the lyrics cleanup; initialize polling state before startup.
- Combine lyrics transcript callbacks with the active-cue snapshot draft.
- Keep account billing navigation and honest preference labels; word cards now run on click.
- Preserve generation run/status locks. Correction calls explicitly record costs on completed jobs.
- Refresh the stale-run test model before replacing its run so its stage reset is actually persisted.
- Read Laravel best practices and subtitle-pipeline skills; preserve existing locking and validation patterns. Boost search-docs was not available among callable tools.

## Validation

Run `scripts/agent/check.ps1`, PHP formatting, and `git diff --check`. Existing correction-cost, stale-run, and panel startup tests cover the integration changes.

## Progress

- Fetched origin and confirmed a clean initial working tree.
- Resolved conflicts in background, panel startup, account rendering, and frontend documentation.
- Initial checks identified cost accounting and test fixture incompatibilities; fixed these without changing generation guards.

## Completion Notes

Validation: repository check passed with 426 backend tests and 186 extension tests, contract validation, TypeScript compile, and extension build. PHP formatting and diff whitespace checks passed. Final review retains a synchronous read of the latest track after enrichment recovery so concurrent word-card responses merge into current state. Existing smoothing audit work, including its R11 draft, remains in scope of the original audit rather than being declared finished by this merge. No live browser or provider checks are included.
