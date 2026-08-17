# Builder Specification: Final Pasted-Lyrics Remediation

Implement the remaining pasted-lyrics correction fixes on
`codex/pasted-lyrics-transcript-correction`.

This document is the implementation authority for this remediation. The
builder's job is to code it exactly, add the specified regression coverage,
run the automated commands, and stop. Do not perform a code review, Ponytail
review, correctness review, browser test, visual test, commit, push, reset, or
rewrite of unrelated work.

The working tree already contains partial remediation edits. Preserve useful
work, but do not assume any partial edit is correct. In particular, remove all
temporary `fwrite`, `STDERR`, trace dumps, debug logging, and diagnostic hooks
before running tests. Never print pasted lyrics, prompts, cue text, work state,
or provider responses.

Manual browser and visual QA remain assigned to the product owner.

## Required context

Before editing:

1. Read `AGENTS.md` and `app/backend/AGENTS.md`.
2. Read:
   - `docs/exec-plans/active/2026-08-13-pasted-lyrics-transcript-correction.md`
   - `docs/RELIABILITY.md`
   - `docs/SECURITY.md`
   - `docs/REVIEW.md`
   - `docs/references/project-guardrails.md`
   - `docs/references/boost-skill-routing.md`
3. Inspect `git status --short` and the complete dirty diff. Do not discard or
   overwrite unrelated changes.
4. Trace submission, initial dispatch, every continuation revision, terminal
   failure, stalled cleanup, provider accounting, background status sync, and
   side-panel video changes before editing.
5. Reuse existing Laravel, queue, reducer, and test patterns. Add no dependency.

## Final execution model

Keep the continuation architecture already selected:

- One `subtitle_track_lyrics_corrections` row per track.
- One `LyricsCorrectionService`.
- One `LyricsCorrectionJob` class carrying `expectedRevision`.
- One bounded provider unit per continuation revision, except alignment may
  make a second call only after structurally invalid output.
- Persist continuation state only in encrypted `work_state` with
  `work_revision`.
- Dispatch the next revision only after its state transaction commits.
- Publish cues and WebVTT atomically only from `finalizing`.
- Keep the old track unchanged until publication.
- Clear `lyrics` and `work_state` on every terminal outcome.

Do not replace this with a monolithic job, cue limit, workflow package, state
machine package, repository, DTO layer, second job class, outbox framework, or
new queue.

## Fix 1: make every terminal write revision-safe

`LyricsCorrectionService::failAttempt()` must never fail a newer revision of
the same attempt.

Use this behavior:

```php
public function failAttempt(
    int $trackId,
    string $attemptId,
    string $errorCode,
    string $message,
    int $expectedRevision,
    ?CarbonInterface $notUpdatedAfter = null,
): bool
```

Requirements:

- `expectedRevision` is required. Do not default it to `null`.
- Match `subtitle_track_id`, `attempt_id`, `work_revision`, and a nonterminal
  status.
- When `notUpdatedAfter` is supplied, also require
  `updated_at <= $notUpdatedAfter` in the atomic update.
- Clear `lyrics` and `work_state` in the same update that writes `failed`.
- Return `true` only when exactly one row was updated.
- Import `CarbonInterface`; do not use a fully qualified type in the method
  signature.
- Every service catch path must pass the revision currently being processed.
- `LyricsCorrectionJob::failed()` must pass its `expectedRevision`.
- A stale job failure must be a no-op and must not clear current private state.

Update `FailStalledSubtitleJobs` so it:

- Selects `work_revision` with the attempt ID.
- Passes the selected revision and the original cutoff to `failAttempt()`.
- Increments its failed count only when `failAttempt()` returns `true`.
- Continues to ignore every `queued` correction.

This closes the race where cleanup selects revision 3, revision 4 commits, and
cleanup then destroys revision 4.

## Fix 2: make queue dispatch failure recoverable

An initial queue outage must not leave a permanent `queued` attempt that blocks
the account forever.

### Initial dispatch

Refactor `submit()` to use this order:

1. The database transaction validates and persists the revision-0 attempt.
2. The transaction returns successfully.
3. Dispatch `LyricsCorrectionJob(..., expectedRevision: 0)` outside the
   transaction on the existing tier batch queue.
4. If dispatch throws, call `failAttempt(..., expectedRevision: 0)` to clear
   private state, then rethrow the original queue exception.
5. Return a fresh eager-loaded correction resource after successful dispatch.

Do not wrap the whole transaction in a broad catch that assumes every
exception is a post-commit dispatch failure. Do not use reference variables or
debug output to discover transaction state. A normal validation, entitlement,
or database exception should follow its existing behavior.

Because the dispatch now occurs physically after `DB::transaction()` returns,
do not add `afterCommit()` to it.

### Continuation dispatch

Remove the `DB::afterCommit()` callback from `commitProgress()`.

- The progress transaction must return the next revision number when it commits
  a nonterminal next state.
- It must return `null` after completion or when the transaction recheck says
  the delivery is stale.
- After the transaction returns, dispatch exactly one job for that returned
  revision.
- If that dispatch throws, fail the attempt using the returned next revision,
  clear private state, and rethrow.
- A dispatch failure after revision 4 is persisted must fail revision 4, not
  the revision 3 job that produced it.
- Do not publish or dispatch anything when the transaction recheck fails.

Keep the implementation inside the existing service. Do not introduce a
dispatcher class or queue abstraction.

## Fix 3: make timeout failure terminal

`LyricsCorrectionJob` must declare:

```php
public bool $failOnTimeout = true;
```

Keep:

- `tries = 0`, because normal concurrency releases require unlimited delivery
  attempts.
- `maxExceptions = 3` for non-timeout exceptions.
- The existing `(provider timeout * 2) + 60` timeout calculation.
- The attempt-keyed `WithoutOverlapping` middleware.
- The account batch-concurrency middleware.

The timeout callback must use the revision-safe `failAttempt()` behavior from
Fix 1, so a timed-out stale delivery cannot fail a newer revision.

## Fix 4: enforce entitlement and accounting at each alignment call

Remove the broad `requireActivePlan()` call at the start of `process()`. It is
both redundant for derived stages and too early for an alignment retry.

Instead:

- `derivedUnit()` keeps its active-plan check immediately before its one
  provider call.
- The alignment loop calls `requireActivePlan($job)` immediately before every
  `promptAlignment()` call, including the invalid-output retry.
- If entitlement disappears after the first response, do not make the second
  call. Fail safely and clear private state.

Record each returned alignment provider call exactly once:

- Add or keep `SubtitleProviderCostRecorder::recordCorrectionAlignment()`.
- Use the configured OpenAI analysis model.
- Record one `alignment_call` unit per returned provider call.
- Use `subtitles.costs.openai_alignment_microusd_per_call`.
- Record a definitive `isMatch: false` response before the attempt is failed.
- Record a structurally returned but backend-invalid first response before the
  one permitted retry, because the provider call incurred cost.
- Do not record transport/rate-limit failures that produced no response.
- Never debit generated-video minutes.

Do not log the prompt or response as part of accounting.

## Fix 5: make wrong-song structured output coherent

`LyricsAlignmentAgent` must allow an empty `cues` array when `isMatch` is
`false`.

Remove the schema's unconditional `->min(1)` on `cues`. Keep `cues` required
and keep the cue item schema. Backend validation already checks `isMatch`
before accepting cues and already requires the exact nonempty cue count when
`isMatch` is `true`.

A definitive mismatch must:

- Make one provider call.
- Record that one alignment call.
- Return `lyrics_do_not_match`.
- Clear private state.
- Leave the track unchanged.

It must not require fabricated dummy cues and must not trigger the invalid
output retry.

## Fix 6: keep extension request revisions truly monotonic

Use the existing correction reducer as the one owner of per-tab synchronization
state.

Required reducer behavior:

- `cleared` sets `jobId` and `status` to `null` and increments
  `latestRequestId` by one.
- `response` is accepted only when its `requestId` equals the current
  `latestRequestId` and its `jobId` equals the current `jobId`.
- A changed server `attemptId` is accepted when those request/job checks pass.
- `submit` increments the request revision and stores the returned attempt.

Required synchronization behavior:

- `syncBackend: false` returns the cached status only when it belongs to the
  requested job; otherwise it returns `null`.
- A non-sync call must not allocate a request ID, mutate the map, or call the
  status endpoint.
- After an awaited status request, do not fall back to the captured `started`
  state when the map entry has been deleted or replaced. A missing entry means
  the response is stale and must be ignored.
- Never allow an old response for job A to overwrite job B after a video
  change, unsupported-page transition, logout, local-state clear, 404, or
  background restart.

Replace correction-state `delete()`/`clear()` calls with revisioned tombstones
where an in-flight response could still resolve. A tab-removal path must also
prevent an in-flight response from recreating usable state for that closed tab.
Do not add a second revision map.

## Fix 7: keep pasted text only in the textarea

Delete the `LyricsPasteBuffer`, `EMPTY_LYRICS_PASTE`, and
`lyricsPasteForVideo()` abstraction and their helper-only tests.

The textarea is already the panel-memory buffer:

- On a real active YouTube video ID change, set
  `lyricsCorrectionTextarea.value = ''`.
- The normal render path must update the count and button state immediately
  afterward.
- Do not clear the textarea merely because correction status becomes failed.
- Do not add pasted text to extension storage, logs, analytics, URLs, error
  payloads, or background state.

Do not recreate another pure helper solely to obtain a unit test. The direct
DOM assignment is preferable. Manual browser verification remains with the
product owner.

## Fix 8: remove redundant and temporary code

Complete these deletions:

- Remove `LyricsCorrectionService::MAX_LYRICS_CHARACTERS` and the second
  25,000-character check from the service. The FormRequest owns the API
  boundary. Keep normalization and Unicode letter/number validation.
- In `lyrics-correction-status.schema.json`, keep field schemas in top-level
  `properties`; conditional branches should only express required and
  forbidden fields.
- Delete the obsolete `docs/temp-pasted-lyrics-second-review.md` and
  `docs/temp-pasted-lyrics-worker-prompt.md` handoff artifacts. Keep this final
  specification until the primary reviewer finishes the next review.
- Remove all unused imports, unused reducer actions, debug comments, temporary
  exception traces, and test-only production hooks left by partial attempts.

Do not simplify away encrypted storage, ownership checks, boundary validation,
revision checks, queue locks, accessibility, or failure cleanup.

## Fix 9: correct durable documentation

Update the active implementation plan's authoritative architecture sections,
not only its progress log.

The plan must say:

- The table includes `work_revision` and encrypted `work_state`.
- Only `running` corrections are subject to execution-time stalled cleanup.
- Each continuation executes alignment, one derived batch, or finalization.
- Progress is committed before the next revision is dispatched.
- Dispatch failure fails the exact persisted revision and clears private state.
- Timeout failure is terminal.
- Manual browser and visual QA are still outstanding for the product owner.

Keep historical review findings as historical evidence. Do not mark the new
review findings resolved and do not claim a new review occurred.

Correct `docs/SECURITY.md` so it states the real data flow:

- Pasted lyrics are sent to configured OpenAI only for alignment.
- Corrected cue text is sent through the same derived OpenAI stages used by
  generation.
- Lyrics and work state are excluded from logs, traces, analytics, API
  responses, extension storage, URLs, and runtime error payloads.
- Encrypted database columns are the only persisted private copies and are
  cleared on completion, failure, expiry, or deletion.

Keep these as normal top-level security bullets; do not accidentally nest them
under the password-reset bullet.

## Required regression tests

Add or correct tests that fail against the pre-remediation implementation.

### Backend

1. A stale `failAttempt()` revision cannot fail or clear a newer running
   revision.
2. A stale cleanup observation cannot fail a correction whose revision or
   `updated_at` advanced before the atomic update.
3. The stalled command counts only rows it actually fails.
4. A stale job's `failed()` callback cannot fail the current revision.
5. `LyricsCorrectionJob::$failOnTimeout` is `true` and timeout still remains
   below worker timeout and queue `retry_after`.
6. Initial queue push failure leaves a failed revision-0 attempt with null
   `lyrics` and null `work_state`, so a later submission is allowed.
7. Continuation queue push failure after progress commits fails the new
   revision and clears private state.
8. Entitlement loss after an invalid first alignment response prevents a
   second provider call.
9. Each returned alignment response records exactly one alignment-call cost;
   a one-call definitive mismatch is included.
10. A definitive mismatch with an empty cue list makes one provider call,
    reports `lyrics_do_not_match`, clears private state, and leaves the track
    unchanged.
11. Existing full-song, continuation, stale-delivery, transient retry,
    encryption, atomic publication, derived-data, and queued-cleanup tests
    continue to pass.

Use Laravel fakes/mocks at the real queue and provider boundaries. Do not add
debug output, sleep-based races, production test hooks, or tautological helper
tests.

### Extension

1. Clearing/tombstoning while a request is in flight makes its later response
   a no-op.
2. Deleting/replacing map state while a request is in flight cannot resurrect
   the captured old state.
3. An old job-A response cannot overwrite a newer job-B state with the same
   tab.
4. The latest response for the current job may adopt a different server
   attempt ID.
5. `syncBackend: false` makes zero API calls and leaves the request revision and
   map unchanged, both with and without cached state.
6. A local submit invalidates an older in-flight status response.
7. Runtime status guards continue to reject fields forbidden for each status.

Do not reintroduce a lyrics paste buffer merely to test video-change clearing.

## Implementation order

Work in this order:

1. Remove debug output from the current partial changes.
2. Make `failAttempt()` revision-safe and update every caller.
3. Refactor initial and continuation dispatch to occur after transactions with
   exact-revision failure handling.
4. Add terminal timeout behavior.
5. Move alignment entitlement/accounting to the per-call boundary and fix the
   mismatch schema.
6. Finish the extension tombstone/request-equality logic.
7. Delete the textarea shadow buffer and other redundant code.
8. Add focused backend and extension regressions.
9. Correct the active plan and security documentation.
10. Run formatting and the complete validation set.

## Validation

Run all commands below and fix every failure caused by this work:

```powershell
Push-Location .\packages\contracts
npm run check
Pop-Location

Push-Location .\app\backend
vendor\bin\pint --dirty --format agent
php artisan test --compact tests/Feature/LyricsCorrectionContinuationTest.php
php artisan test --compact tests/Feature/FailStalledSubtitleJobsTest.php
php artisan test --compact tests/Feature/SubtitleJobApiTest.php
php artisan test --compact
Pop-Location

Push-Location .\app\extension
npm test
npm run compile
npm run build
Pop-Location

.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
git diff --check main
git status --short
```

Do not run browser automation and do not claim manual or visual QA passed.

## Completion standard

Before stopping, confirm all of the following in the implementation itself:

- No debug output remains.
- No terminal write can affect a different revision.
- Initial and continuation queue-push failures clear the exact persisted
  private state instead of stranding work.
- Timeout invokes terminal cleanup.
- Entitlement and cost accounting wrap every alignment provider call.
- Non-sync extension reads are side-effect free.
- Old responses cannot survive a clear, job change, or revision change.
- The textarea is the only panel-memory copy of the paste.
- Durable security and execution docs describe the real implementation.
- All required automated commands pass.

Report only implementation facts, files changed, tests added, exact command
results, remaining automated limitations, and that manual browser/visual QA is
still assigned to the product owner. Do not provide a review verdict.
