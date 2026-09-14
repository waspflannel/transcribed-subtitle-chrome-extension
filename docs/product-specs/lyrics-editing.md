# Lyrics Editing And Full Replacement

Status: proposed
Owner: product
Created: 2026-08-16
Implementation status: Quick fix AI refresh supersedes the original provider-free design as of 2026-09-06. Older builder/reviewer instructions below are historical; current work is tracked in [Learning and editing](../exec-plans/active/2026-09-09-whole-project-review/learning-and-editing.md).

## Problem

The current **Use pasted lyrics** form looks like a small edit, but it replaces the full transcript and can take several minutes. It has no meaningful progress, no cancellation, and no protection against replacing a complete song with an excerpt.

Learners also need a quick way to fix one wrong token without running full-track AI work.

## Product Shape

Keep editing inside the completed **Watch** state. Do not add a fifth top-level tab at the 320px panel width.

Each transcript line exposes **Edit**, followed by selecting a word in that line. The correction form appears beneath the unchanged source line. Only one line is editable at a time.

A transcript actions menu exposes **Replace full lyrics…** and **Generate again…**. Each opens a dedicated Watch screen with **Back to transcript**, preserving the replacement draft. Replacement progress defaults to a compact strip above the transcript; **View progress** opens detailed stages and cancellation.

The current track stays active until a replacement succeeds. Neither workflow debits generated-video minutes or extends track expiry.

## Replace Full Lyrics

### Hybrid replacement (2026-09-13)

Full replacement uses the configured OpenAI/Luna model for alignment and the configured Cerebras model for translation, tokens and romanization in parallel analysis batches. Both Luna and Cerebras generations use this same replacement flow. Generation selection and Quick Fix still use saved job models. Analysis preserves Luna's cue text and timing, and publication remains atomic. Stage logs and cost estimates report the model actually used. No language-specific fallback is enabled; Arabic/Japanese enrichment quality remains part of user acceptance testing. Replacement-specific validation has been removed as described below.

### Replacement validator removal (2026-09-13)

The replacement validator implementation and its configuration switch have been deleted at the user's request. Alignment now requests cue allocations only; there are no song-match/completeness classifications, semantic rejection rules, exact-consumption checks, allocation caps or replacement WebVTT validation pass. Submission keeps whitespace normalization and basic nonempty/string/25,000-character request limits. Headings and credits remain part of the supplied text.

The converter reconstructs usable pasted ranges in model response order, skips unknown or empty allocations and nonadvancing ranges, clamps array slices to available parts, groups repeated cue IDs and emits in original timing order. It wraps long text within the selected slot; tiny slots or unsplittable text can remain longer than 84 characters. Unallocated text does not block publication. If no usable cue remains, no replacement can be formed.

Replacement analysis always takes the permissive path: fixed source text and timing remain authoritative, returned details attach by cue ID or remaining response order, usable tokens are reindexed, and missing details are allowed. Missing translation falls back to source text. Normal generation and Quick Fix retain their existing validation and saved model selection.

The old partial-merge prompt and incomplete-lyrics confirmation flow have been removed. All pasted text is treated as a full replacement, including excerpts. The optional public `allowPartial` field remains accepted for older clients but is ignored; legacy error codes remain readable in stored status responses. Cancellation, ownership, current-track checks, provider error handling and atomic publication remain. The regular full-track replacement confirmation remains in the panel.

A completed replacement can contain missing words, poor timing or mismatched analysis. Later replacements reuse current timing slots, so a bad published timing map may require regeneration. Provider transport/JSON errors can still fail the operation. No audio alignment or new validator is introduced. Earlier replacement rules below are historical and superseded by this section; Git history retains the removed implementation.

### Long aligned text (2026-09-10)

An otherwise valid alignment must not fail only because one reconstructed timing slot contains more than 84 Unicode code points. The server wraps it into shorter cues at word boundaries, or grapheme boundaries for an overlong unspaced part, without deleting text. It divides that slot's duration in proportion to text length, preserves its original start/end and all neighboring slots, and rejects any split that cannot retain positive durations. These internal times are estimates; this does not add acoustic alignment. Identity, exact source consumption, match/completeness, cancellation and atomic publication checks still apply.

For complete replacements, alignment returns the original cue ID and each pasted segment's ending part index. The server derives consecutive starts, separators and cue indexes. Confirmed partial replacements still supply explicit source ranges and separators so uncovered existing text can be preserved.

### Partial-lyrics confirmation update (2026-09-08)

The alignment AI assesses whether the paste belongs to the song and whether it appears complete. Unrelated lyrics remain a hard rejection, including when the user permits partial merging. Completeness is an AI assessment against the existing transcript, not audio verification. The former 60% slot and 80% timeline thresholds no longer reject replacements.

Suspected incomplete lyrics stop before derived learning work and leave the current track unchanged. The existing `lyrics_incomplete` result prompts a warning in the panel: AI can combine the supplied lyrics with the current lyrics and keep existing text where needed. The user can proceed or edit the paste. Proceed submits a new attempt with `allowPartial: true`; ordinary submissions omit that optional boolean. Permission applies only to the submitted draft and current track. Editing the draft or changing the video or track invalidates it. No lyrics are persisted in extension storage.

After confirmation, AI chooses placement and combines the two sources. Supplied lyrics take priority where they correspond; uncovered sections retain existing lyrics, including any existing mistakes. Server-side reconstruction preserves pasted text and only accepts valid source references and timing slots. The model must not invent missing lyrics. Complete replacements still consume all pasted text. Invalid output, unrelated lyrics, cancellation, or stale state never publish a partial result.

The hard input, maximum capacity, per-cue length, timing, authorization, expiry, concurrency, and atomic publication checks remain. Match and completeness assessments are probabilistic; confirmation permits merging, not bypassing unrelated-song rejection.

### Reliability update (2026-09-07)

The local text-processing model and example environment use `gpt-5.6-luna` with `xhigh` reasoning effort. Fast mode is enabled by default and sends `service_tier: fast`. This applies to tokenization, combined analysis and translation, romanization, word cards, quick fixes, and lyrics alignment. Fast processing carries a premium and does not guarantee latency. Audio transcription retains its existing provider and model.

The POST requires `expectedTrackId`; stale identities return the existing conflict response. The correction retains its source track and job-run identities in encrypted work state and rechecks them, expiry, and entitlement before committing progress or publication.

Recognized section headings and credits are removed before both alignment and exact-text validation. All remaining text must be consumed. Requests that exceed the maximum character capacity of the existing timing slots fail validation before queueing. Alignment retries receive the rejected validation reason, and safe diagnostics retain attempt, revision, stage, reason, and cue counts without lyrics or provider output.

Alignment receives numbered authoritative pasted parts and numbered existing parts per cue. It returns ordered source segments with inclusive start/end indices for existing timing slots. The server copies text from those parts, preserving repetitions and punctuation instead of asking the model to reproduce the lyrics. Pasted boundaries must be contiguous, consume every pasted part, and produce cues within the 84-code-point limit. Confirmed partial merges may also copy existing segments and add a space at source switches; complete replacements use only pasted segments. Long unspaced text uses grapheme boundaries. Song-match and completeness checks still apply before publication. Correction notices have 12px of vertical separation from neighboring controls.

The existing stalled-job sweep recovers a missing delivery once per revision after the queue retry window and slack. A second stalled running unit fails safely; queued work waiting for worker capacity is preserved. Existing attempt locks and revision checks reject duplicate or cancelled deliveries.

The panel shows terminal outcomes recovered after reopening, without requiring a locally observed transition. Explicit dismissal applies to that attempt during the panel session; navigation does not dismiss it. Status polling failures show automatic-retry feedback beside the last known state.

### Copy

Rename **Use pasted lyrics** to **Replace full lyrics**.

Use this description:

> Paste lyrics for this song. We will fit them to the existing timing and rebuild translations, pronunciation, and word data. If they appear incomplete, we will ask before combining them with your current lyrics.

Use **Replace entire track** as the confirmation action and **Cancel replacement** while work is active.

### Flow

1. The learner opens Watch > transcript actions > Replace full lyrics.
2. The learner pastes plain-text lyrics for this song.
3. The existing input validation runs.
4. One confirmation explains that the action replaces the entire transcript and has no undo.
5. The correction workflow assesses the paste. Unrelated lyrics are rejected; suspected incomplete lyrics require explicit confirmation before AI merges them with existing lyrics. Accepted lyrics are aligned, rebuilt, and atomically published.
6. The current transcript and overlay keep working until publication.
7. Success updates the transcript, overlay, search, copy actions, and WebVTT without reload.

### Progress

Reuse the existing generation progress card and progress bar. Expose the current safe correction stage and derive a coarse percentage in the extension:

| Stage | Label | Percent |
| --- | --- | --- |
| queued | Waiting to start | 0% |
| aligning | Checking and aligning lyrics | 15% |
| rebuilding | Rebuilding words and translations | 45% |
| romanizing | Rebuilding pronunciation | 70% |
| enriching | Rebuilding word cards | 85% |
| finalizing | Applying replacement | 95% |
| completed | Complete | 100% |

Fixed stage percentages avoid another persisted progress field and cannot move backward when a provider batch is split. Skipped stages simply cause a forward jump.

A compact status strip appears above the current transcript and recovers from the backend when the panel reopens. Detailed progress and cancellation open on their own Watch screen.

### Complete-Lyrics Safety

Exact text consumption prevents invented or dropped pasted text, but it does not prove that the learner pasted the whole song.

The AI assesses completeness before derived work. A suspected excerpt returns `lyrics_incomplete` and leaves the current track unchanged until the learner explicitly permits merging. A confirmed excerpt can proceed with AI-selected placement and existing lyrics filling uncovered sections. Slot count and timeline span are not proof of completeness and are not hard rejection thresholds.

Do not add an aligned preview yet. Add one only if the confirmation and completeness gate prove insufficient in manual QA.

### Cancellation

Allow cancellation while status is `queued` or `running` through the current correction resource.

Cancellation must:

- Match the current attempt and revision.
- Mark it `cancelled`.
- Clear encrypted lyrics and work state.
- Leave the current track unchanged.
- Make queued or late continuations no-op.
- Discard an in-flight provider response when cancellation won first.

An in-flight provider request may still finish. If final publication commits before cancellation, the completed result wins. Cancellation is not undo.

The status contract needs the safe correction stage and one additional terminal status:

```text
status: queued | running | completed | failed | cancelled
stage: queued | aligning | rebuilding | romanizing | enriching | finalizing | completed | failed | cancelled
```

The extension derives whether cancellation is available from `queued | running`; no separate `canCancel` field is needed.

## Quick Fix

### Flow

1. The learner clicks Edit on a transcript line.
2. The learner selects one existing source token.
3. A plain-text input appears beneath the source line, keeping the original words visible.
4. Save refreshes the cue learning data and then updates the transcript and overlay without reload; local cancel closes the input without a request.

The replacement may contain spaces, but remains one tappable token or phrase. It must be non-empty and keep the resulting cue at or below 84 Unicode characters.

### Data Rules

Quick fix uses one synchronous backend AI request for the edited cue. The learner's replacement is authoritative and stays one token or phrase. Timing, punctuation, other tokens' boundaries, and other cues remain unchanged.

Before provider work, verify ownership, an active plan, a completed unexpired track, expected track identity, and no active full replacement. Build the edited cue in memory and remove its stale derived data. Do not hold database locks while waiting for AI.

Rebuild word translation, gloss, and word-card metadata for every token in the affected cue, since context can change their meaning. Rebuild whole-line translation and cue/token romanization according to the original job's enabled settings. Same-language tracks retain source text as the line translation. Word cards are refreshed even in on-demand mode.

Validate cue and token identities, required meanings, enabled translation, and enabled non-Latin romanization. Do not silently accept incomplete AI output. Recheck entitlement, job run, track identity, expiry, and replacement state before atomically publishing the refreshed cue with fresh track/cue IDs and rebuilt WebVTT. Preserve current data on failure and allow retry. Never debit generated-video minutes or extend expiry.

The editor shows Saving and refreshing feedback while awaiting the response. The backend agent timeout is 45 seconds; the extension allows 60 seconds. No new queue workflow or persistence table is needed for this single-cue operation.

### Concurrency

- Reject Quick fix while full replacement is queued or running.
- Reject stale expected track IDs with the existing conflict response pattern.
- Use the fresh cue ID so late clicked-token responses cannot patch the edited cue.
- Do not add edit history or a new track-version table.

## API Shape

Keep the existing full-replacement POST and GET endpoints.

Add one cancellation operation on the same current correction resource:

```text
DELETE /v1/subtitle-jobs/{jobId}/lyrics
```

The cancellation body must include the current `attemptId` so a delayed request cannot cancel a newer attempt:

```json
{
  "attemptId": "current-attempt-uuid"
}
```

Add one token mutation:

```text
PATCH /v1/subtitle-jobs/{jobId}/cues/{cueId}/tokens/{tokenIndex}
```

Request:

```json
{
  "expectedTrackId": "current-track-public-id",
  "text": "replacement token or phrase"
}
```

Use canonical schemas under `packages/contracts`. The only new product error code is `lyrics_incomplete`; reuse existing validation, conflict, correction-in-progress, and public failure responses everywhere else. Cancelled is a status, not an error.

## Security And Reliability

- Keep pasted lyrics and replacement text out of logs, traces, analytics, extension storage, URLs, and runtime error payloads.
- Keep provider calls backend-only.
- Keep full-replacement private state encrypted and clear it on every terminal outcome.
- Scope every operation to the authenticated owner.
- Preserve attempt, revision, track, cue, and token identity checks at their existing trust boundaries.

## Timing allocation guard (2026-09-13)

Before wrapping a reconstructed timing slot, reject text exceeding the greatest of12 Unicode code points, three times its original source-text length, or60 code points per second of its duration. This generous anomaly check prevents an entire song collapsing into a short intro; it does not verify acoustic alignment or impose the removed whole-track coverage thresholds. It applies equally to complete and partial replacement and preserves the current track on failure.

## Replacement latency update (2026-09-13)

After complete alignment validation, analysis batches run independently under existing account concurrency limits with the job’s saved provider. Batch balancing includes translation and pronunciation work. Completed slices merge under lock into the encrypted attempt; the last batch publishes atomically without a separate finalization queue wait. The current track remains usable throughout. The panel checks active correction status every two seconds and refreshes account/history on the normal ten-second cadence. No additional AI calls are introduced by concurrency or polling.

## Out Of Scope

- Timestamp, cue, translation, romanization, gloss, or word-card editing.
- Adding, deleting, splitting, or merging cues.
- File upload, bulk replacement, preview, undo, restore, or version history.
- Cancelling after publication.

## Builder Instructions

### Role And Authority

This section is the implementation handoff for builder agents. The builder writes the complete production and regression-test code described here, then returns control to the reviewer. The builder does not redesign the feature, review its own work, validate the repository, or operate the local runtime.

The priority order is:

1. The current user instruction.
2. This product specification.
3. Repository `AGENTS.md` files and project guardrails.
4. Existing contracts, architecture, and code patterns.

If current code conflicts with this specification in a way that cannot be resolved without changing product behavior, stop that part of the implementation and report the exact blocker. Do not invent compatibility layers, alternate workflows, feature flags, or fallback behavior.

### Required Reading

Before editing, read only the relevant parts of:

- `AGENTS.md`
- `app/backend/AGENTS.md`
- This specification
- `ARCHITECTURE.md`
- `docs/FRONTEND.md`
- `docs/RELIABILITY.md`
- `docs/SECURITY.md`
- `docs/references/project-guardrails.md`
- `docs/references/boost-skill-routing.md`
- [2026-08-13-pasted-lyrics-transcript-correction.md (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/active/2026-08-13-pasted-lyrics-transcript-correction.md)
- Existing lyrics-correction contracts, service, job, resource, background state, side-panel UI, and focused tests

Trace the current POST, GET, polling, continuation, final publication, clicked-token enrichment, and track-publication paths before changing them. Reuse those paths rather than creating parallel infrastructure.

### Branch And Integration Workflow

This feature is built on a child branch and must not be implemented directly on the open integration branch or `main`.

Parent integration branch:

```text
codex/pasted-lyrics-transcript-correction
```

Builder feature branch:

```text
codex/lyrics-editing-and-full-replacement
```

Before builder handoff, the reviewer or product owner must make the parent integration branch clean and ensure all intended parent work is committed. The builder must not carry uncommitted parent changes onto the child branch.

The builder may run only these Git inspection and branch-creation operations:

```powershell
git status --short --branch
git branch --show-current
git switch -c codex/lyrics-editing-and-full-replacement
```

Builder branch procedure:

1. Verify the current branch is exactly `codex/pasted-lyrics-transcript-correction`.
2. Verify the working tree is clean.
3. Create and switch to `codex/lyrics-editing-and-full-replacement` from the current parent HEAD.
4. Make every feature edit on the child branch.
5. Write production code and regression tests, then return to the reviewer without committing, pushing, merging, or switching branches again.

If the parent branch is wrong, the working tree is dirty, or the child branch already exists, stop and report the condition. Do not clean the tree, delete or reuse a branch, stash work, pull, reset, or choose another base.

After the builder returns, the reviewer owns this sequence:

1. Inspect the complete child-branch diff.
2. Generate contracts, format code, and run automated and browser validation.
3. Fix review and validation findings on the child branch.
4. Commit the accepted child-branch work.
5. Merge `codex/lyrics-editing-and-full-replacement` into `codex/pasted-lyrics-transcript-correction`.
6. Validate the combined integration branch.
7. Merge the integration branch into `main` only after the full pasted-lyrics feature and this editing feature are accepted together.

Never merge the builder child branch directly into `main`.

### Working-Tree Rules

- Begin from the required clean parent branch. Preserve any unexpected concurrent changes that appear after branch creation and report direct conflicts.
- Never revert, reset, delete, or rewrite work that is outside this feature.
- Make the smallest direct change in existing files.
- Add no dependency, package, queue, table, repository, DTO layer, workflow framework, or generic state-machine abstraction.
- Add no backward-compatibility path unless a real persisted or external contract requires it.
- Keep controllers as HTTP adapters and product workflow in the existing lyrics-correction service.
- Keep provider calls backend-only.
- Never log lyrics, replacement text, prompts, cue text, token text, work state, or provider responses.
- Remove temporary debug output, test hooks, and diagnostic dumps before returning.

### Builder Execution Limits

Builders write code and required regression tests, but do not execute validation.

Do not run:

- PHPUnit, Vitest, contract checks, or any other tests.
- Pint, Prettier, ESLint, TypeScript compilation, or formatters.
- Documentation lint, repository checks, PR verification, or review commands.
- Contract generators or other code generators.
- Extension builds or browser automation.
- Migrations or database mutation commands.
- Backend servers, queue workers, schedulers, Docker profiles, or runtime restart commands.
- Git operations other than the three branch inspection and creation commands explicitly allowed above. In particular, do not commit, amend, stash, reset, switch again, rebase, push, pull, merge, delete branches, or create a PR.

Do not claim that anything passes. The reviewer owns generation, formatting, tests, builds, browser QA, runtime QA, diff review, and any resulting fixes.

### Contracts

Treat `packages/contracts` as canonical.

Update the source schemas, OpenAPI document, fixtures, generated TypeScript declarations, extension contract aliases, and runtime response guards together. Because builders do not run generators, edit generated artifacts to match the source schema exactly; the reviewer will regenerate and compare them later.

The correction status resource must:

- Add terminal status `cancelled`.
- Require a safe public `stage` for every status.
- Use only `queued`, `aligning`, `rebuilding`, `romanizing`, `enriching`, `finalizing`, `completed`, `failed`, or `cancelled` as public stages.
- Keep `track` exclusive to `completed`.
- Keep `errorCode` and `message` exclusive to `failed`.
- Return neither track nor error fields for `cancelled`.
- Preserve attempt ID and update time on every state.

Add the DELETE cancellation operation to the existing lyrics resource and the PATCH token operation defined in this specification. The token PATCH response must reuse the canonical complete track response rather than introduce a second track shape.

Add only `lyrics_incomplete` as a new public product error. Reuse existing validation, ownership, not-found, conflict, correction-in-progress, and generic correction failure behavior.

### Full-Replacement Backend

Keep the existing `SubtitleTrackLyricsCorrection` row, `LyricsCorrectionService`, and revisioned `LyricsCorrectionJob` continuation design.

Expose public stage without exposing encrypted work state:

- `queued` status maps to `queued`.
- Internal `aligning` maps to `aligning`.
- Internal `analyzing` and `tokenizing` map to `rebuilding`.
- Internal `romanizing`, `enriching`, and `finalizing` map directly.
- Terminal states map to the matching terminal stage.

Do not persist a separate progress percentage. The extension derives the fixed percentages in this specification from public stage.

Add completeness validation after structurally valid alignment and exact pasted-text consumption, but before the first derived-data continuation is committed.

Calculate:

```text
slotCoverage = aligned timing slots / original timing slots
timelineCoverage = aligned first-start to last-end span / original first-start to last-end span
```

Require `slotCoverage >= 0.60` and `timelineCoverage >= 0.80`. Keep these as small named private constants in the correction service so the reviewer can calibrate them. Reject invalid or zero source spans safely. A structurally valid alignment below either threshold is terminal `lyrics_incomplete`; do not spend another alignment call trying to make an incomplete paste complete.

Preserve all existing wrong-song, exact-consumption, cue-identity, timing, character-limit, entitlement, cost, encryption, stale-delivery, and atomic-publication behavior.

### Cancellation Backend

Add cancellation on the current owner-scoped correction resource.

Inside one database transaction:

1. Resolve the owned completed, unexpired track and lock its correction row.
2. If status is `queued` or `running`, set status to `cancelled`.
3. Increment `work_revision` so already queued jobs become stale.
4. Clear `lyrics`, `work_state`, `error_code`, and `error_message` in the same write.
5. Return the fresh correction resource.

Cancellation of an already terminal attempt is idempotent and returns its current terminal state. It must never rewrite `completed`, `failed`, or `cancelled` to another state.

Do not attempt to interrupt an active HTTP provider call. Existing revision and status rechecks must discard its result. Confirm that job `failed()` and stalled cleanup cannot convert a cancelled attempt to failed or clear a newer attempt.

Do not create a cancellation job, event table, outbox, or queue message. Do not debit or refund video minutes.

### Full-Replacement Extension

Keep the feature in Watch. Do not add another top-level tab.

Add one Edit subview with direct local switching between Quick fix and Replace all. Reuse existing side-panel DOM, tab, state, message, API, guard, and rendering patterns. Do not introduce a component framework or generic router.

For Replace all:

- Use the exact product copy in this specification.
- Keep pasted text only in the live textarea.
- Use one compact inline confirmation step. The first action reveals the warning; the confirmed action says **Replace entire track** and submits. Do not add a modal framework.
- Reuse existing progress card and bar CSS.
- Map public stages to the fixed percentages in this specification.
- Show progress above the still-usable current transcript.
- Poll through the existing correction synchronization path.
- Restore progress after panel reopen or background restart.
- Show **Cancel replacement** only for `queued` and `running`.
- Send cancellation through the background and canonical API client.
- Keep cancelled, failed, and completed states distinct.
- Preserve the textarea after failed or cancelled attempts on the same video; clear it when the active video changes or replacement completes.
- Publish and remember the returned completed track through the existing path.

Keep all rendered user text escaped and preserve keyboard, focus, live-region, and 320px behavior.

### Quick-Fix Backend

Historical instruction, superseded on 2026-09-06: Quick fix now refreshes learning data with one AI request before atomic publication. Do not add a job or persistence table.

Use a Form Request for:

- Required `expectedTrackId` matching the track public-ID format.
- Required non-empty string `text`.
- A request-level maximum of 84 Unicode characters; the service must still validate the final cue length after replacement.

Inside one database transaction:

1. Resolve and lock the owner-scoped completed, unexpired track.
2. Reject when its correction status is `queued` or `running` using existing conflict behavior.
3. Compare `expectedTrackId` to the locked track public ID.
4. Resolve the exact cue ID and token index from stored canonical cues.
5. Walk stored tokens in order through `sourceText` with a forward-only cursor to locate the target occurrence. Do not use unrestricted string replacement because repeated words can replace the wrong occurrence.
6. Replace only the target source span, preserving all surrounding characters.
7. Reject empty output or a resulting cue over 84 Unicode characters.
8. Recompute the edited token's normalized text with the existing token normalization helper.
9. Reduce every token in the edited cue to `index`, `text`, and `normalizedText`, retaining the edited text only at the selected index.
10. Remove cue-level romanization.
11. Set `translatedText` to the updated `sourceText`.
12. Assign a fresh cue ID and fresh track public ID.
13. Rebuild WebVTT with the existing formatter.
14. Persist the track once and return the canonical complete track response.

Keep cue index, start time, end time, other cues, generated time, expiry, job settings, and billing data unchanged. Do not re-tokenize other boundaries. Do not call an AI provider.

If stored cue or token data cannot prove the exact target span, fail without changing the track. Do not guess.

### Quick-Fix Extension

Add the token PATCH request and response guard to the existing extension API path and background message boundary.

In the Watch Edit subview:

- Make only existing source tokens selectable in Quick fix mode.
- Use a native labeled text input, Save, and Cancel.
- Pre-fill the current token text.
- State that spaces remain one tappable phrase.
- Disable Save for empty input, over-limit input, unchanged text, active request, or active full replacement.
- Submit the current track public ID, cue ID, token index, and replacement text.
- On success, publish and remember the returned track through the existing track-update path.
- Exit the editor and rerender transcript and overlay without reload.
- If the backend reports a stale track conflict, close the edit and refresh current track state rather than retrying the stale mutation.
- Clear local edit state when the active video changes.

When `translatedText` equals updated `sourceText`, do not render a duplicate translation line for that cue. Existing clicked-token enrichment may later restore individual word-card fields.

### Regression Tests To Write But Not Run

Builders must add focused tests as code, but must not execute them.

Contract coverage:

- Valid `cancelled` status and all public stages.
- Rejection of track or error fields in cancelled state.
- DELETE and PATCH request/response shapes.
- `lyrics_incomplete` as a canonical error.

Backend coverage:

- `Y_vB-3R_BYc`-shaped 64-of-69 alignment passes the provisional gate.
- Slot coverage below 60% fails without derived provider work.
- Timeline coverage below 80% fails without derived provider work.
- Exact boundary values pass.
- Queued and running cancellation clear private state and increment revision.
- Stale jobs, `failed()`, provider returns, and stalled cleanup cannot overwrite cancellation.
- Completed, failed, and already-cancelled cancellation is idempotent.
- Quick fix replaces the correct occurrence when a token repeats in one cue.
- Quick fix preserves timing and surrounding punctuation.
- Quick fix rejects stale track ID, missing cue, missing token, empty text, and overlong final cue.
- Quick fix clears derived cue data, changes cue and track IDs, rebuilds WebVTT, and leaves expiry and billing unchanged.
- Quick fix is rejected during active full replacement.
- Wrong owner and expired track are rejected.

Extension coverage:

- Stage-to-percentage mapping and cancelled guard.
- Progress recovery and cancellation messaging.
- No backend lyrics sync stores textarea text.
- Quick-fix validation, request shape, success publication, stale conflict refresh, and active-video reset.
- Duplicate translation suppression after a provider-free edit.
- Late clicked-token response cannot patch the fresh cue ID.

Do not add broad snapshots, duplicate helper-only tests, sleep-based races, test-only production hooks, or tests for framework behavior.

### Return Format

Return one concise implementation report containing:

- Production files changed.
- Contract and regression-test files changed.
- Behavior implemented.
- Any blocker or deliberate deviation, with file references.
- A clear statement that validation was not run by instruction.

Do not include a code review, quality verdict, passing-test claim, commit hash, or suggested speculative follow-up.

## Acceptance Criteria

- [ ] Edit remains inside Watch and works at 320px.
- [ ] Full-replacement copy says it replaces the complete track and rebuilds learning data.
- [ ] Correction progress reuses the generation component and survives panel reopen.
- [ ] A valid full paste for `Y_vB-3R_BYc` passes completeness checks.
- [ ] Half-song, one-verse, and every-other-line pastes fail without changing the track.
- [ ] Queued and running replacements can be cancelled without publishing late work.
- [ ] One Quick fix changes one token and matching source span, then refreshes the affected cue through one provider request.
- [ ] Quick fix preserves timing, rebuilds WebVTT, and replaces stale derived data with validated fresh learning data for that cue.
- [ ] Stale track edits and late clicked-token responses cannot overwrite fresh data.
- [ ] Neither workflow debits video minutes or extends expiry.
- [ ] Lyrics and replacement text remain excluded from diagnostics and extension storage.

## Implementation Plan

1. Add correction stage, `cancelled`, cancellation, and calibrated completeness validation to the existing correction path.
2. Reuse the generation progress component and add Replace all confirmation and cancellation in the Watch Edit subview.
3. Use the canonical token PATCH operation for atomic publication after cue refresh.
4. Add inline Quick fix UI and stale-track recovery.
5. Run focused contract, backend, extension, 320px, browser, completeness, cancellation-race, and stale-response checks.

## Open Decision

Approve or adjust the provisional 60% slot-coverage and 80% timeline-coverage thresholds after reviewer-run fixture and real-provider validation.
