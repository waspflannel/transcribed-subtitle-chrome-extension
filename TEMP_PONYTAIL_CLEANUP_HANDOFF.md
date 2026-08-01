# Ponytail cleanup worker handoff

## Objective

Continue the approved whole-repository deletion pass on `codex/ponytail-cleanup`. Implement only the remaining findings below. Keep each finding in its own reversible commit, validate it, and stop with a clean cleanup working tree so the branch can be returned for review.

Do not use a skill. Do not push, open a pull request, merge, rebase, amend existing commits, or restore the preserved user-work stash.

## Starting state

- Branch: `codex/ponytail-cleanup`
- Expected HEAD before this handoff commit: `e7ecc98 Delete legacy fixed-size cue batching`
- A named stash protects the dirty work that existed before this cleanup:
  `codex-preserve-preexisting-before-ponytail-cleanup-2026-08-01`
- Locate that stash by its message, not by an ordinal such as `stash@{0}`. Ordinals can move.
- Do not apply, pop, drop, rename, or otherwise mutate the protected stash. It will be restored during the final review pass.
- Before editing billing, contracts, active plans, release scripts, or database fields, inspect the protected stash read-only with `git stash show --stat <stash-ref>` and `git stash show -p <stash-ref> -- <path>`. Preserve compatibility with that user work where possible.
- The working tree should be clean except for this committed handoff document.

## Completed commits

1. `2571e67 Remove speculative audio isolation path`
   - Removed ElevenLabs Audio Isolation runtime/config/readiness/test/current-doc paths.
   - Kept one FFmpeg normalization path to 16 kHz mono FLAC.
2. `1be95bc Delete duplicate local worker manager`
   - Removed the PHP worker bootstrapper and `subtitles:dev-workers` command.
   - Kept `scripts/runtime/start-local-backend-workers.ps1` for local runtime ownership and Supervisor for production.
3. `598983a Remove unused ultimate generation tier`
   - Removed the future-only tier, queues, limits, budgets, environment values, tests, and current docs.
4. `e7ecc98 Delete legacy fixed-size cue batching`
   - Removed `batchSize`, the fixed-size fallback, its config/env value, and compatibility test.
   - `batchPlan` is now required and character-budget batching is the only path.

Focused validation already passed:

- Audio prep/transcription/readiness: 22 tests, 137 assertions.
- Worker/runtime/subtitle API: 72 tests, 546 assertions.
- Tier/runtime/subtitle API: 71 tests, 540 assertions.
- Batch plan/subtitle API: 66 tests, 478 assertions.
- Pint passed for each PHP slice.

## Required commit discipline

For every remaining finding:

1. Trace all current callers and tests before editing.
2. Make only that finding's changes.
3. Run its focused tests, formatter, type check, or build as appropriate.
4. Run `git diff --check`.
5. Stage exact paths or hunks only.
6. Inspect `git diff --cached --stat` and `git diff --cached` before committing.
7. Use the commit message specified below.
8. Confirm `git status --short` is clean before starting the next finding.

Do not combine findings. If one file contains two findings, use hunk staging so each remains independently revertible. Do not rewrite the four completed commits.

## Remaining findings and commit sequence

### 1. Replace panel port wrappers with native state

Commit: `Replace panel port wrappers with native state`

- Delete `app/extension/utils/panel-port-registry.ts`.
- Delete `app/extension/tests/panel-port-registry.test.ts`.
- In `app/extension/entrypoints/background.ts`, replace `PanelPortRegistry` with a native `Set<Runtime.Port>` while keeping the same add/remove/broadcast behavior.
- In `app/extension/entrypoints/sidepanel/main.ts`, keep a small local connect/reconnect function around `browser.runtime.connect`; preserve disconnect-driven reconnect behavior without injectable scheduling/config abstractions.
- Run the relevant Vitest files, TypeScript compile, and extension build.

### 2. Delete the constant default-view abstraction

Commit: `Delete constant panel default view helper`

- Delete `app/extension/utils/panel/view-state.ts`.
- Delete `app/extension/tests/panel-view-state.test.ts`.
- Replace its single use in `app/extension/entrypoints/sidepanel/main.ts` with the literal `watch` view.
- Update current frontend documentation that claims the helper owns default selection.
- Run the relevant panel tests and TypeScript compile.

### 3. Inline the poll schedule

Commit: `Inline side panel polling schedule`

- Delete `app/extension/utils/poll-schedule.ts`.
- Delete `app/extension/tests/poll-schedule.test.ts`.
- Keep the two interval constants local to `sidepanel/main.ts`, or inline them if still clear.
- Replace `shouldPollNow` with the direct visibility check and `pollIntervalMs` with the direct ternary.
- Run panel tests and TypeScript compile.

### 4. Inline the active-tab query

Commit: `Inline the active tab query`

- Delete `app/extension/utils/active-tab.ts`.
- Remove or update the helper-only assertions in `app/extension/tests/panel-tab-sync.test.ts` without deleting unrelated tab-sync coverage.
- Inline the one browser tab query at its background call site.
- Run the focused test and TypeScript compile.

### 5. Remove the obsolete WebVTT timestamp fallback

Commit: `Require stable WebVTT cue identifiers`

- In `app/extension/utils/webvtt-track.ts`, map active cues only by stable cue ID.
- Delete the +/-25 ms timestamp matching fallback and its comment.
- Update `app/extension/tests/webvtt-track.test.ts` so fixtures use IDs and no test depends on timestamp fallback.
- Confirm backend and partial-track WebVTT producers still emit cue IDs before committing.
- Run the focused WebVTT test, TypeScript compile, and extension build.

### 6. Delete unused Eloquent inverse relations

Commit: `Delete unused model inverse relations`

- Reconfirm there are no callers, then remove only these unused methods and imports:
  - `User::billingUsageEvents()`
  - `BillingUsageEvent::user()`
  - `BillingUsageEvent::subtitleJob()`
  - `BillingUsageEvent::subtitleTrack()`
  - `SubtitleJobArtifact::job()`
  - `SubtitleJobEvent::job()`
- Do not remove used forward relations such as `SubtitleJob::user`, `SubtitleJob::track`, `SubtitleJob::artifacts`, `SubtitleJob::events`, or `SubtitleTrack::job`.
- Run Pint plus focused model/billing/subtitle tests.

### 7. Remove the dead `queue_unavailable` contract path

Commit: `Delete unused queue unavailable error code`

- Reconfirm no backend production caller emits this code.
- Remove `SubtitleProcessingException::queueUnavailable()`.
- Remove the code from `packages/contracts/openapi.json`, regenerate contract TypeScript through the repository's existing command, and remove extension message/copy branches and focused tests.
- The protected stash also modifies `packages/contracts/openapi.json`; inspect its patch read-only first and stage only this error-code hunk.
- Do not hand-edit generated files if the contracts package provides its normal generator.
- Run contracts validation/type generation, focused backend tests, focused extension tests, and TypeScript compile.

### 8. Remove unused billing feature flags

Commit: `Delete unused billing feature flags`

- Remove `cue_translation` and `romanization` from every billing plan configuration. Every plan currently enables them and no production code reads them.
- Replace the generic `BillingPlanCatalog::hasFeature(array, string)` path with direct/specific `full_word_cards` access if it remains single-purpose.
- Update only tests and current docs that assert the deleted flags.
- The protected stash modifies `app/backend/config/billing.php` and billing services/tests. Inspect the stash patch first, make the smallest compatible change, and stage only cleanup hunks.
- Run Pint and the billing feature/unit suites.

### 9. Simplify `SubtitleQueue` and owned tier configuration parsing

Commit: `Collapse subtitle queue pass-through methods`

- Re-read `SubtitleQueue.php`, `SubtitleTier.php`, and every caller after the completed ultimate-tier and worker-manager deletions.
- Remove delegation methods whose names add no value, especially `generationNameForTier` and `batchNameForTier`, by calling the owning tier methods directly or consolidating queue naming in one owner.
- Remove support for comma-separated string `queues` and string `tiers` in worker-group config. Repository-owned config provides arrays.
- Keep public queue-family constants and operations that still have several real callers; do not replace useful names with repeated config expressions.
- Update focused runtime/queue tests and current docs.
- Run Pint, runtime-profile, production-readiness, subtitle API, and queue-related tests.

### 10. Remove write-only persisted fields

Commit: `Delete write-only persisted subtitle and billing fields`

- Reconfirm current branch callers and inspect the protected stash before deleting anything.
- Candidate fields approved by the audit:
  - `subtitle_jobs.request_ip`
  - `subtitle_tracks.source_dialect`
  - `users.stripe_subscription_item_id`
  - `users.billing_trial_ends_at`
  - `users.billing_ends_at`
  - `billing_usage_events.minutes`
- Remove each field only if the completed cleanup branch plus protected user-work patch has no product read that requires it. Writes, casts, fillable entries, factories, migrations, controller/service plumbing, logs, schemas, and tests must be removed together.
- This is the highest-conflict item because the protected stash contains Stripe synchronization work. If the stash introduces a real reader or contract for a candidate field, leave that field in place and record the exception for review; do not break or overwrite the user work to satisfy a line-count target.
- Follow the repository's pre-production migration convention. Ensure both fresh migrations and an existing development database arrive at the intended schema.
- Run Pint plus billing, usage, subtitle API, artifact, and model tests.

### 11. Delete duplicate policy text

Commit: `Delete duplicate build policy document`

- Delete root `how_to_build.txt`.
- Do not delete `docs/quality/golden-principles.md` or project guardrails.
- Remove live links to `how_to_build.txt` if any remain. Historical completed audit references may stay historical.
- Documentation validation is sufficient.

### 12. Delete scaffold leftovers

Commit: `Delete unused scaffold leftovers`

- Reconfirm each target is unused, then delete:
  - empty `app/backend/database/seeders/DatabaseSeeder.php`
  - `app/backend/.npmrc` when no backend `package.json` consumes it
  - zero-byte `app/backend/public/favicon.ico`
  - `.gitkeep` files inside directories that already contain tracked files
- Check whether `app/backend/public/robots.txt` duplicates the dynamic robots route under the actual web-server serving model. Delete it only if the dynamic route remains the single intended owner and validation confirms that choice.
- Do not remove directory placeholders that are still required to retain an otherwise empty directory.
- Run relevant docs/routes checks and `git diff --check`.

## Final validation for the worker

After all remaining commits:

```powershell
Set-Location C:\transcribed-subtitle-extension
.\scripts\agent\check.ps1
git diff HEAD~12..HEAD --check
git status --short
git log --oneline --decorate -20
```

Also run `vendor\bin\pint --dirty --format agent` from `app/backend` before the final backend commit, and the extension test/compile/build commands discovered from `app/extension/package.json` for extension commits.

If the full check fails, fix only failures caused by these cleanup commits and keep the repair in the finding's commit when still unpublished. Do not amend the four commits listed under Completed commits. If a failure comes from external services or the protected stash, record the exact blocker.

## Return package for review

Return:

- branch name and final HEAD;
- ordered commit hashes/messages;
- per-commit focused validation;
- full `scripts/agent/check.ps1` result;
- remaining `git status --short`;
- any approved candidate deliberately retained because the protected stash proved it has a real consumer;
- any concern about restoring the protected stash.

Leave the protected stash intact and leave this temporary handoff document in place. The final reviewer will restore the user work, resolve any overlap, review the complete diff, and remove this document when it is no longer needed.
