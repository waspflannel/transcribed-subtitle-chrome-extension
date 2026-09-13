# Plan: Speed up lyrics replacement

Status: active — implementation validated; user acceptance pending
Owner: agent
Created: 2026-09-13
Last updated: 2026-09-13

## Goal

Reduce full lyrics replacement latency while preserving provider choice, matching quality, cancellation, and atomic publication. Push a reviewable branch for the user to test; do not merge main.

## Scope

- Parallel independent analysis jobs after validated alignment, using existing account concurrency limits.
- Job-aware batch balancing and publication in the final successful analysis transaction.
- Two-second targeted replacement polling, with normal account/history refresh every ten seconds.
- Conditional completed-track loading and safe per-unit timing logs.
- No new dependencies, tables, queues, paid experiments, provider changes, or progressive replacement UI.

## Acceptance Criteria

- [x] Independent batches can overlap and complete out of order without losing results.
- [x] Duplicates, late failures, cancellation, expired tracks, entitlement changes, and stale attempts cannot publish invalid data.
- [x] Recovery dispatches only unfinished work; already queued serial attempts remain resumable.
- [x] Replacement polling avoids frequent account/history fetches and publishes through existing guards.
- [x] Full repository checks pass; branch is ready for user testing.

## Decisions

Local Ponytail, Laravel best practices, subtitle pipeline, AI SDK and security skills informed the work. Laravel Boost documentation verified overlap locks and encrypted state. The required Laravel rules reader reviewed the concurrency design.

Keep one encrypted attempt row. Each worker returns only its own slice and merges it into current locked state. This still serializes the encrypted state per completed batch, but avoids introducing a second private-data store and cleanup lifecycle without evidence that serialization dominates latency. Provider requests execute outside database locks. Cost estimates are committed with each accepted result exactly once.

Analysis revision stays stable while batches complete; a completed-index set rejects duplicate work. Last completion assembles and publishes in the existing user/job/track/correction lock order. No separate finalization job is scheduled for new attempts. Existing serial state is normalized on claim and its remaining work is dispatched.

## Validation

Use focused backend race, middleware, recovery and API tests; extension background and real panel timer tests; PHP Pint; scripts/agent/check.ps1. User owns real-provider quality and speed acceptance. No speedup percentage claimed before that test.

## Progress

- Implemented queue fan-out, guarded slice merges, inline finalization, job-aware planning, targeted polling, and timing logs.
- Focused correction, API, interleaved completion, duplicate middleware, recovery, and panel polling regressions passed.
- Full `scripts/agent/check.ps1` passed: 551 backend tests (4417 assertions), 258 extension tests, contracts, TypeScript, extension production build, and docs lint. PHP Pint passed. Evidence: `app/backend/storage/logs/lyrics-speed-check.log`.
- Restarted the local backend/workers after verifying zero active generations/corrections. Runtime check reports `ok: true`; extension build is ready to reload. Evidence: `app/backend/storage/logs/lyrics-speed-runtime.log` and `lyrics-speed-runtime-check.json`.
- Working branch: `codex/lyrics-replacement-speed`; main remains untouched. User acceptance and real-provider speed comparison remain pending.

## Rollback

Switch back to main and restart local workers; rebuild and reload the extension. No schema migration or new configuration is required. Finish or cancel active replacement attempts before switching versions.

## Collapsed timing fix (2026-09-13)

User testing exposed a pre-existing alignment validation gap: Cerebras assigned the entire paste to the first 240ms slot; splitting produced16 cues of3–16ms. A later failed attempt preserved this previously corrupted publication. Parallel analysis did not create the timings.

Before splitting, reject assigned text longer than the maximum of12 Unicode code points, three times the source-slot text length, or60 code points per second of slot duration. These generous limits detect gross allocation errors, not ordinary subtitle reading speed. Apply to complete and partial replacement; retain the previous removal of whole-track coverage thresholds. Prompt instructions now explicitly prohibit placing a whole song in an intro slot. The failure retains the old track and requests a retry without adding automatic AI calls.

Regressions cover the65-slot/240ms collapse, partial mode, and the short-slot boundary; existing Punjabi/Thai long-slot wrapping remains valid. Recovery of local job170 uses matching cached transcript57, fresh track/cue identities, unchanged expiry/billing, and an encrypted backup of the corrupted track. Original translations/cards cannot be reconstructed without analysis, so recovery restores source cues and timing only.

Validation passed:555 backend tests (4431 assertions),258 extension tests, contracts, TypeScript, production build and docs checks; PHP Pint passed. Evidence: `app/backend/storage/logs/lyrics-timing-guard-check.log`. Read-only validation against all56 cues of the successful Luna job171 found zero excessive slots. Local job170 recovery completed with65 source cues spanning2220–158579ms; the encrypted backup remains outside Git and the one-off recovery script was removed.
