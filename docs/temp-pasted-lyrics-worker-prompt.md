# Worker Implementation Specification: Finish Pasted-Lyrics Transcript Correction

Implement all unresolved second-review findings for the pasted-lyrics transcript correction feature on the current `codex/pasted-lyrics-transcript-correction` working tree.

Do not stop after making the existing tests pass. The current tests pass while the feature still rejects normal songs and has queue, retry, and synchronization bugs. Implement the prescribed design below, add every listed regression test, run the complete automated harness, and leave the working tree ready for the primary agent to review.

Manual browser and visual testing are explicitly out of scope. The product owner will do that.

## Required Context

Before editing:

1. Read the repository `AGENTS.md` and follow it.
2. Read these files completely:
   - `docs/exec-plans/active/2026-08-13-pasted-lyrics-transcript-correction.md`
   - `docs/temp-pasted-lyrics-second-review.md`
   - `docs/RELIABILITY.md`
   - `docs/SECURITY.md`
   - `docs/references/project-guardrails.md`
   - `docs/references/boost-skill-routing.md`
3. Inspect `git status` and preserve unrelated user changes. Do not reset, discard, reformat, or absorb unrelated dirty files.
4. Trace the existing correction flow end to end before changing it: request, correction row, queue dispatch, alignment, all derived-data provider calls, status polling, track publication, stale cleanup, and extension rendering.
5. Use current Laravel documentation or the repository's Laravel Boost routing before relying on queue middleware, timeout, retry, or failed-job behavior.
6. Reuse existing project patterns, add no dependency, add no generic pipeline framework, and delete the flawed cap/config machinery once its replacement works.

## Non-Negotiable Product Behavior

- A user can paste complete plain-text lyrics for a completed, unexpired generated track.
- The existing cues provide timing; the paste provides the authoritative words.
- Support the complete generated track. Do not impose the current two-batch or 22-cue limit.
- Keep the 25,000-character API/UI boundary. Do not add a smaller internal character, cue-count, or batch-count admission limit.
- Preserve pasted wording, case, punctuation, and order after the documented whitespace and label normalization.
- Rebuild every derived field required by the original job settings before publication.
- Keep the old track active until the corrected track is complete and valid.
- Publish the corrected cues and WebVTT atomically with attempt completion.
- On any terminal failure, leave the old track unchanged and clear all persisted pasted/intermediate lyric content.
- Require a current active plan immediately before every provider call.
- Never log pasted lyrics, corrected lyrics, prompts, intermediate cues, tokens, translations, or romanization.
- The panel must recover correction state after reopening or background-worker restart.
- Another extension instance may create a newer attempt; the latest successful status request must be able to adopt it.

## Required Queue Correction

The present fix is not acceptable:

- `ensureCorrectionWorkFitsQueue()` rejects every track above 22 cues.
- `LyricsCorrectionJob::timeoutSeconds()` counts a simplified provider path that does not include recursive split retries.
- `process()` treats every `running` attempt as claimable, allowing duplicate provider work.
- One long correction job does not fit the existing batch-worker timeout and concurrency-slot lifetime safely.

Replace this with the continuation workflow specified below. Do not substitute a larger timeout, a cue limit, a batch limit, a synchronous request, or a general-purpose workflow framework.

### Required execution design

Use one correction attempt row, one correction service, and one continuation job class. Each job execution performs at most one bounded unit of work, persists progress, increments an attempt-local revision, and dispatches the next revision after commit.

The required stages are:

1. `aligning`: run alignment with the existing one invalid-output retry, validate exact text consumption, store draft cues and the real batch plan.
2. `analyzing` or `tokenizing`: process exactly one actual batch.
3. `romanizing`: process exactly one actual batch when required.
4. `enriching`: process exactly one actual batch when required.
5. `finalizing`: validate the assembled cues and WebVTT, then atomically update the track and complete the attempt.

This specification explicitly replaces the plan's original single long-running queue execution with narrow continuation executions. Keep one user-visible correction attempt, one continuation job class, and the existing correction service. Do not create additional job classes, a workflow interface, a state-machine package, a repository layer, or a reusable workflow engine.

### Required database shape

Edit the branch's still-unmerged correction-table migration rather than adding a second migration. Add exactly these persistence fields to `subtitle_track_lyrics_corrections`:

- `work_revision`: unsigned integer, default `0`.
- `work_state`: nullable `longText`, cast as `encrypted:array`.

Keep normalized pasted lyrics in the existing encrypted `lyrics` column. Do not add separate columns for every stage or batch.

The initial row for a new attempt must contain:

```text
status = queued
attempt_id = new UUID
work_revision = 0
work_state.stage = aligning
```

When retrying after a completed or failed attempt, replace the attempt ID, reset status to `queued`, reset revision to `0`, replace encrypted lyrics, set work state to only the initial `aligning` state, and clear prior errors.

### Required persisted work-state behavior

- Persist only the state needed to resume the next bounded unit in the one encrypted `work_state` field.
- Intermediate state contains private transcript content and must remain encrypted at rest.
- Reset work state and revision when a new attempt replaces a completed or failed attempt.
- Clear pasted lyrics and all intermediate work state on completed, failed, expired, or deleted attempts.
- Do not expose work state through API resources.

Use these stage names only:

- `aligning`
- `tokenizing`
- `analyzing`
- `romanizing`
- `enriching`
- `finalizing`

Store `batchIndex`, the actual `batchPlan`, the current assembled cues, and only the temporary per-stage output needed for the next transition inside `work_state`. Do not persist duplicate copies of arrays after a stage has been merged. At each stage transition, discard temporary arrays from the completed stage.

The job must derive stage transitions from the original subtitle-job settings:

1. `aligning` always comes first.
2. Use `analyzing` when translation is requested; otherwise use `tokenizing`.
3. Use `romanizing` only when romanization is enabled and corrected cues contain non-Latin letters.
4. Use `enriching` only for full enrichment where the existing implementation currently requires it.
5. Otherwise proceed directly to `finalizing`.

### Required bounded provider calls

- A continuation execution must make no more than two provider calls.
- The `aligning` execution may make two calls only when the first result is structurally invalid. An explicit wrong-song result is terminal after the first call.
- Every `tokenizing`, `analyzing`, `romanizing`, or `enriching` execution must process exactly one actual batch from the stored shared batch plan.
- The existing translation-analysis methods recursively split malformed multi-cue batches. Add a clearly named optional boolean such as `splitInvalidBatches`, defaulting to `true`, to the relevant public/provider methods and propagate it to their private recursion checks. Correction calls must pass `false`; existing subtitle generation callers must retain the default behavior unchanged.
- Do not introduce a strategy interface, retry-policy class, enum, or additional service for this one distinction.
- Alignment may retain its one invalid-output retry. A definitive `isMatch: false` / `lyrics_do_not_match` result is terminal and must not trigger a second paid alignment call.
- Transient transport/rate-limit failures may use queue retry. Structured invalid output must not create an unbounded internal retry path.
- Calculate the correction job timeout as `(provider timeout * 2) + 60 seconds`. With current defaults this is 300 seconds. Validate that it is lower than both `subtitles.queue.worker_timeout_seconds` and the selected queue connection's `retry_after` for non-sync drivers.
- Set terminal timeout behavior so the failed callback marks the current attempt failed and clears encrypted private state.
- Remove `subtitles.correction.max_batches`, `subtitles.correction.worker_timeout_seconds`, `subtitles.correction.timeout_margin_seconds`, `ensureCorrectionWorkFitsQueue()`, and `maxBatchCountForCueCount()`.

### Required duplicate and stale job protection

- Add `expectedRevision` as a required constructor field on `LyricsCorrectionJob` and include it in the initial and every continuation dispatch.
- Before provider work, verify the current attempt ID, nonterminal status, expected stage, and expected revision.
- Add Laravel's existing `WithoutOverlapping` queue middleware to `LyricsCorrectionJob`, keyed by the attempt ID. Retain the existing per-account concurrency middleware. Set the overlap lock expiry to the calculated job timeout plus 60 seconds and use a short release delay for overlap contention.
- A stale or duplicate job with an old revision must no-op without clearing current lyrics, failing the attempt, dispatching another continuation, or calling a provider.
- The first valid revision changes `queued` to `running`. Later valid revisions require `running`. Do not make arbitrary `running` rows claimable without the matching attempt ID and revision.
- After successful provider/validation work, reopen a transaction, lock the correction row, recheck attempt ID, status, stage, and revision, persist the next work state, and increment revision by exactly one.
- Dispatch exactly one job for the incremented revision with `afterCommit()` on the same queue and connection. Do not dispatch when the transaction recheck fails.
- On a transient exception, leave stage and revision unchanged so the same job can retry safely.
- On a nontransient exception or job failure, update only the matching nonterminal attempt to `failed`, clear `lyrics` and `work_state`, and leave the track unchanged.

### Entitlement and accounting

- Recheck the active plan immediately before every provider unit, including alignment and every derived batch.
- Record provider cost exactly once after each successful provider unit.
- A stale or duplicate job must not record cost.
- Do not debit video-generation minutes for correction.

### Required stalled cleanup

- Do not apply the running-work timeout to `queued` attempts.
- A correction that has waited in the queue for 30 minutes must remain queued and retain its encrypted lyrics.
- Remove `queued` from the correction query in `FailStalledSubtitleJobs`. Do not add a new queued-age config value or queue-wait failure rule.
- A genuinely abandoned `running` unit must eventually fail and clear private work state. Derive its cutoff from the selected queue connection's `retry_after`, the maximum correction backoff, and existing stalled-job slack so cleanup cannot beat a legitimate retry.
- Keep cleanup race-safe by checking attempt ID, revision/status where needed, and terminal state in the update.

## Required Extension Corrections

### Status synchronization

- Keep the per-tab monotonically increasing request revision.
- Remove the guard that rejects every different attempt ID while local state is queued/running.
- For the latest request revision, accept the server's current attempt even if another extension instance created it.
- A response from an older request revision must never overwrite a newer local submit or newer status response.
- `syncBackend: false` must never call the correction status endpoint. Return the cached value, including `null`, immediately.
- Do not add a one-line helper solely to test numeric equality. Test the real background synchronization flow or a reducer that owns meaningful state behavior.

### Panel-only paste state

- When the active YouTube video ID changes, clear the lyrics textarea, reset the count, and rerender button state.
- Keep lyrics only in panel memory. Never add them to extension storage, logs, analytics, runtime error payloads, or URLs.
- Preserve the pasted textarea after a correction failure on the same video so the user can edit and retry.

### Runtime contract guard

Make `guardLyricsCorrectionStatus()` enforce the canonical state shapes:

- `queued` and `running`: no `track`, `errorCode`, or `message`.
- `completed`: required `track`; no `errorCode` or `message`.
- `failed`: required `errorCode` and `message`; no `track`.

Reuse existing record/field guard helpers. Do not add a schema-validation dependency to the extension.

## Required Implementation Order

Implement in this order so each step has one clear dependency:

1. Update the existing correction migration and model with encrypted work state and revision.
2. Add the correction-only opt-out for recursive derived-batch splitting while preserving default generation behavior.
3. Refactor `LyricsCorrectionService` from whole-workflow processing to one-revision processing with the required stage transitions.
4. Refactor `LyricsCorrectionJob` to carry `expectedRevision`, apply exact-attempt overlap middleware, use the bounded timeout, and fail terminally on timeout.
5. Remove the cue/batch admission cap and obsolete correction timeout config.
6. Correct stalled cleanup so queued work is not failed by the running-work cutoff.
7. Correct background status synchronization and non-sync behavior.
8. Clear panel paste state on video changes and enforce runtime response-state exclusivity.
9. Add the backend and extension regressions below.
10. Update durable implementation/reliability/security documentation for the behavior actually implemented.

Do not implement a partial compatibility path alongside the continuation path. There must be one active correction execution path when finished.

## Required Simplification

Remove or simplify the following after the corrected flow is working:

- The two-batch admission cap and `maxBatchCountForCueCount()` if it has no remaining legitimate caller.
- The inaccurate `max_batches` timeout calculation and correction-only timeout config values that no longer represent the real per-unit job.
- The one-use `isLatestLyricsCorrectionSync()` equality wrapper and its helper-only test.
- The duplicate manual 25,000-character check after Laravel's `max:25000` string rule; keep Unicode letter/number validation.
- One of the duplicate invalid-status test representations in `packages/contracts/scripts/validate.mjs`; keep either fixtures or inline invalid objects, not both.
- The resource fallback relation query if every real call site continues to eager-load `track.job`.
- The second alignment call after a definitive wrong-song result.

Keep the shared WebVTT formatter and shared batch planner; those are justified reuse.

## Required Automated Tests

Add focused tests that would fail against the current implementation:

### Backend

- A completed track with more than 22 cues is accepted and can progress to completion.
- Work is divided by the actual shared batch plan, not the old 84-character worst-case admission estimate.
- A malformed multi-cue derived batch does not recursively create an unbounded correction call tree.
- Each continuation execution advances exactly one expected stage/batch revision.
- Replaying a stale job revision performs no provider call and no state change.
- Two deliveries for the same attempt cannot perform the same provider unit twice.
- A transient provider failure leaves revision/stage resumable and a retry can complete.
- A definitive wrong-song result prompts once, fails, clears private state, and leaves the track unchanged.
- Entitlement loss between units prevents the next provider call and fails safely.
- A queued correction older than 30 minutes is not failed by the stalled-job command.
- An abandoned running correction is eventually failed and clears pasted/intermediate state.
- Same-language, translated, non-Latin/romanized, and full-enrichment settings still rebuild correctly.
- Final publication is atomic and stale attempts cannot publish.
- Raw database values do not contain pasted lyrics or encrypted work-state plaintext.
- Completed and failed attempts clear both pasted lyrics and intermediate work state.
- The per-unit job timeout is below worker timeout and queue `retry_after`, with a test that reflects the real maximum calls in one unit.

### Extension

- An older request response is ignored.
- The latest response with a different/newer attempt ID is accepted.
- A local submit invalidates an older in-flight status response.
- `syncBackend: false` with no cached correction makes zero status API calls.
- Changing videos clears the textarea and counter; failure on the same video does not.
- The runtime guard rejects queued/running responses with a track, completed responses with errors, and failed responses with a track.

Do not satisfy these tests by extracting tautological helpers. Exercise the real state transition or the smallest meaningful unit that owns it.

## Documentation Requirements

- Treat the original full-song correction goal as authoritative.
- Remove the plan language that declares the two-batch/22-cue cap to be product scope.
- Record the narrow continuation-job decision and why it is needed for bounded queue work.
- Do not rewrite requirements to legitimize implementation shortcuts.
- Do not change review verdicts or mark Review Findings resolved. The primary agent will perform that review after implementation.
- Keep manual browser/visual QA marked outstanding and assigned to the product owner.
- Update reliability/security/architecture docs only where the final behavior or persisted state genuinely changed.

## Validation

Run focused tests while iterating, then run all of the following before stopping:

```powershell
Push-Location .\packages\contracts
npm run check
Pop-Location

Push-Location .\app\backend
vendor\bin\pint --dirty --format agent
php artisan test --compact
Pop-Location

Push-Location .\app\extension
npm test
npm run compile
npm run build
Pop-Location

.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
git diff --check main
```

Do not run browser automation or claim manual/visual QA passed.

## Completion Standard

Do not stop at a partial implementation or ask for design confirmation unless a concrete repository constraint makes this prescribed design impossible. If that happens, report the exact conflicting file, invariant, and failing command; do not substitute a cue cap, larger monolithic timeout, synchronous workflow, or unencrypted temporary state.

Before finishing:

1. Run every validation command listed above and fix all failures caused by the implementation.
2. Confirm every required regression test exists and passes.
3. Confirm `git diff --check main` passes.
4. Confirm unrelated dirty changes were preserved.
5. Report only implementation facts:
   - the final execution design;
   - files changed;
   - migrations or config changes;
   - tests added;
   - exact validation commands and results;
   - any remaining automated limitation;
   - manual browser/visual QA still left to the product owner.

Do not perform or claim a code review, correctness review, architecture review, security review, or Ponytail audit. The primary agent will do those after you finish coding.

Do not commit, push, reset, or discard changes unless separately instructed.
