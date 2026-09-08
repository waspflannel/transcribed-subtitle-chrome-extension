# Builder Notes

Temporary implementation handoff for `codex/lyrics-editing-and-full-replacement`.

Delete this file after every item below is implemented and reviewer validation passes. Do not keep it as permanent project documentation.

## Implementation Rules

- Fix the shared boundary or state transition, not only the reported caller.
- Keep the current product scope. Do not add dependencies, migrations, compatibility layers, or new workflow abstractions.
- Add focused regression tests for each race or boundary fixed below.
- Follow the builder execution limits in `docs/product-specs/lyrics-editing.md`. The reviewer will run validation and browser QA.
- Do not commit, switch branches, restart backend processes, or delete this file before reviewer acceptance.

## Required Correctness Fixes

### 1. Recover Corrections By The Active Job

Files: `app/extension/entrypoints/background.ts`

Problem: `syncLyricsCorrection()` selects the first completed history item matching only `youtubeVideoId`. One video can have multiple completed jobs for different language or generation variants, so recovery can poll, cancel, or publish a correction belonging to another track.

Guidance:

- Derive the correction job from the current ready track's `jobId` or the existing tab correction state's `jobId`.
- Do not use video ID alone to choose a correction resource.
- Preserve the selected job identity through polling and publication.
- If the tab has no current ready track or tracked correction job, return no correction instead of guessing from history.

Acceptance:

- Two completed jobs for the same YouTube video cannot cross-load correction state.
- Reopening the panel recovers the correction for the track currently active in that tab.

### 2. Preserve Quick-Fix Results When A Tab Becomes Inactive

Files: `app/extension/entrypoints/background.ts`, `app/extension/utils/backend-subtitle-state.ts`

Problem: after a successful Quick fix, `activeReadyTrackMatches()` rejects the response when the user merely switches to another tab. The canonical track is then neither stored nor remembered. Returning to the original tab leaves its old ready state permanently cached.

Guidance:

- Validate the captured target tab with `browser.tabs.get(tabId)` rather than requiring it to remain the active tab.
- Confirm that the captured tab still shows the expected video and that its cached track still matches the request's job and track IDs.
- Store and publish the returned track to that captured tab when it is still on the expected video, even if another tab is currently active.
- If the captured tab navigated away, do not publish into the new page. Ensure the canonical result can still be recovered instead of leaving an unrecoverable stale ready cache.

Acceptance:

- Start Quick fix, switch tabs before the response, then return. The edited transcript and overlay are present.
- Navigating the original tab to another video before the response never publishes the old video's track there.

### 3. Recheck The Target Tab Before Correction Publication

Files: `app/extension/entrypoints/background.ts`

Problem: immediate completion and polling completion publish a returned track after an awaited request without rechecking the captured tab's current video and track. Navigation can therefore publish the previous video's correction into the new page.

Guidance:

- Use one target-tab validation helper for immediate completion, polled completion, and Quick fix.
- Immediately before publication, read the captured tab by ID and verify its YouTube video ID.
- Verify the current local track belongs to the expected job and base track before replacing it.
- Never use whichever tab happens to be active as a substitute for the request's captured tab.

Acceptance:

- A correction response arriving after same-tab navigation is ignored for the new video.
- A response arriving after switching tabs still updates the unchanged originating tab.

### 4. Correct Mutation Ordering

Files: `app/extension/entrypoints/sidepanel/main.ts`

Problem: `mutationVersion` increments both when a mutation starts and in every mutation's `finally`. With overlapping mutations, completion of the older request invalidates the newer request, so both responses can be discarded.

Guidance:

- Increment the latest-mutation version only when a mutation starts.
- A mutation response may apply only when its captured version equals the latest started mutation version.
- Track `mutationsInFlight` separately so polls cannot apply while any mutation is running.
- A poll may apply only when no mutation is running and no mutation started after that poll captured its version.
- Disable or guard repeat cancellation clicks, but keep ordering correct even if overlapping messages still occur.

Acceptance:

- If mutation A starts, then mutation B starts, A can be ignored without invalidating B.
- A poll started before a mutation cannot overwrite the mutation response.
- A later successful mutation remains visible regardless of response order.

### 5. Enforce Regeneration Lock In The Background

Files: `app/extension/entrypoints/background.ts`, `app/extension/entrypoints/sidepanel/main.ts`

Problem: generation is disabled only after synchronized correction state says a replacement is active. It remains available while correction submission is in flight, and the background generation handler does not enforce the invariant.

Guidance:

- Disable Generate while correction submission or any conflicting mutation is in flight.
- Add a background-side guard before starting generation. Check the current ready job's authoritative correction status, including after a background restart.
- Serialize generation and correction submission for the same tab so they cannot both pass their preflight checks concurrently.
- Keep the guard tab-scoped and direct; do not add a generic workflow framework.

Acceptance:

- Generate cannot start after correction submission begins but before its response arrives.
- Generate cannot start after reopening the panel or restarting the background context while a correction is queued or running.

### 6. Keep Cancellation Responses Attempt-Bound Locally

Files: `app/extension/entrypoints/background.ts`, `app/extension/utils/lyrics-correction.ts`

Problem: the server validates `attemptId`, but a delayed cancellation response for attempt A can still overwrite newer local attempt B after the response returns.

Guidance:

- Before reducing a cancellation response into tab state, confirm the current tab correction still has the request's `jobId` and `attemptId`.
- Prefer an explicit reducer action carrying the expected attempt ID over an unconditional `submit` action.
- Ignore an old cancellation response when another attempt replaced it.

Acceptance:

- A delayed cancellation response for attempt A cannot change attempt B's status or unlock regeneration while B is active.

### 7. Recover From Stale-Track Conflicts By Error Code

Files: `app/extension/entrypoints/background.ts`, `app/extension/entrypoints/sidepanel/main.ts`, `app/extension/utils/messages.ts`

Problem: the Quick-fix UI detects stale tracks by searching an error-message substring that does not match the canonical backend message. The runtime response also strips the structured API error code.

Guidance:

- Preserve the safe `SubtitleApiError.code` in failed panel responses.
- Branch on the structured conflict code, not human-readable copy.
- On stale track, close the stale editor, clear the selected cue/token, invalidate the stale ready cache, and fetch the current canonical track.
- Keep public error text safe; do not include lyrics or replacement text.

Acceptance:

- A stale expected track response automatically closes the stale editor and refreshes the track.
- Changing backend copy does not break conflict recovery.

### 8. Stop Provider Retries After Cancellation

Files: `app/backend/app/Services/Subtitles/LyricsCorrectionService.php`, `app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php`

Problem: alignment and derived-data reprompts can start a second provider request after cancellation commits. Late publication is protected, but unnecessary provider work and cost can still occur.

Guidance:

- Recheck correction status, attempt ID, and expected revision before every retry or reprompt, not only before the first provider call and final commit.
- Reuse the current attempt/revision guard. Add only the narrow hook needed for provider-internal reprompts.
- When cancellation won, return without another provider call and without changing the current track.

Acceptance:

- A test that cancels after the first invalid provider response proves no second provider request starts.
- In-flight responses that return after cancellation cannot queue more work or publish.

### 9. Match Quick-Fix Spans With Canonical Case Normalization

Files: `app/backend/app/Services/Subtitles/LyricsCorrectionService.php`

Problem: token validation accepts case-normalized tokens, but `tokenSpan()` compares case-sensitively. A token `hello` for source `Hello` can be valid persisted data but cannot be edited.

Guidance:

- Compare token text and candidate source slices with the same `normalizeTokenText()` logic used by `LearningTokenOutputValidator`.
- Continue returning offsets into the original source string so replacement preserves original punctuation and surrounding text.
- Keep forward-only matching for repeated tokens.

Acceptance:

- Lowercase token text can edit its title-case source span.
- Repeated case-insensitive tokens still replace only the selected occurrence.

### 10. Document Cancellation Error Responses

Files: `packages/contracts/openapi.json`

Problem: the DELETE correction operation omits responses that the implementation returns: `422` for invalid or missing `attemptId` and `409` for a stale attempt.

Guidance:

- Add the existing canonical validation response for `422`.
- Add the existing canonical conflict response for `409`.
- Do not create new error codes for cancellation.

Acceptance:

- OpenAPI matches the controller and service behavior for success, stale attempt, invalid input, authentication, authorization, missing resources, and rate limiting.

### 11. Return Validation Failure For Resulting Cue Overflow

Files: `app/backend/app/Services/Subtitles/LyricsCorrectionService.php`

Problem: replacement text can satisfy its own 84-character limit while making the resulting cue exceed 84 characters. This currently returns generic `lyrics_correction_failed` with full-replacement copy.

Guidance:

- Treat resulting cue overflow as request validation, using the existing API validation response pattern.
- Associate the message with replacement text and explain that the resulting subtitle line is too long.
- Keep the current track unchanged.

Acceptance:

- A valid-sized replacement that makes the cue too long returns canonical `validation_failed` behavior, not a pasted-lyrics processing error.

### 12. Expose Edit-Mode Selection Accessibly

Files: `app/extension/entrypoints/sidepanel/index.html`, `app/extension/entrypoints/sidepanel/main.ts`, `app/extension/entrypoints/sidepanel/style.css`

Problem: the mode controls are ordinary buttons, but assistive technology receives no selected state and the visual distinction relies only on color.

Guidance:

- Keep ordinary buttons rather than rebuilding full tabs.
- Give the container an appropriate group role and accessible label.
- Set `aria-pressed` on each mode button from the current mode.
- Add a non-color selected indicator such as an underline, border, or weight change.

Acceptance:

- Screen readers announce which mode is selected.
- Selection remains clear without color perception.

### 13. Make Replacement Progress Semantic And Accurate

Files: `app/extension/entrypoints/sidepanel/index.html`, `app/extension/entrypoints/sidepanel/main.ts`

Problem: the shared progress surface remains labeled `Generating` and `Generation stages` during replacement. Percent and stage changes are not exposed as semantic progress, and the active step lacks `aria-current="step"`.

Guidance:

- Update the card label and stage-list label based on generation versus replacement.
- Give the progress indicator `role="progressbar"` with current, minimum, and maximum values.
- Mark the active checklist item with `aria-current="step"`.
- Announce meaningful stage changes through one polite live region without making the entire changing card noisy.

Acceptance:

- Replacement progress is announced as replacement, not generation.
- Current percentage and stage are available to assistive technology.

### 14. Move Focus When Forms Are Hidden

Files: `app/extension/entrypoints/sidepanel/main.ts`

Problem: Continue hides the form containing the focused button without moving focus to the confirmation action. Quick-fix success similarly hides its focused form.

Guidance:

- After revealing confirmation, focus `Replace entire track`.
- When confirmation is cancelled, return focus to the lyrics input or Continue button.
- After Quick-fix success, move focus to a stable visible control such as the Edit disclosure or transcript region.
- Do not use automatic focus on initial mobile panel load.

Acceptance:

- Keyboard focus never falls back to the document body during either workflow.

### 15. Bound The Token Index Route

Files: `app/backend/routes/api.php`, `app/backend/app/Http/Controllers/Api/SubtitleJobController.php`, `packages/contracts/openapi.json`

Problem: `whereNumber()` accepts an arbitrarily long digit string that cannot be injected into a PHP `int`, resulting in a 500.

Guidance:

- Add a bounded non-negative numeric route constraint suitable for real cue token counts, or accept a string and validate its integer range before casting.
- Keep malformed and out-of-range indexes outside the typed controller call.
- Reflect any explicit maximum in OpenAPI.

Acceptance:

- Nonnumeric, negative, and extremely long numeric indexes return a bounded 404 or validation response, never 500.

### 16. Use One Unicode Character Limit

Files: `app/extension/entrypoints/sidepanel/index.html`, `app/extension/entrypoints/sidepanel/main.ts`

Problem: native `maxlength="84"` counts UTF-16 code units, while extension and backend validation count Unicode code points. Emoji and other astral characters are rejected too early by the browser.

Guidance:

- Remove native `maxlength` from the Quick-fix input.
- Keep the existing Unicode-aware JavaScript count and disable submission above 84 characters.
- Expose the limit and validation message next to the input.
- Keep backend `mb_strlen` enforcement as the trust boundary.

Acceptance:

- Up to 84 Unicode code points are accepted consistently across browser, extension validation, contract expectations, and backend.

## Required Regression Coverage

### Backend

- Add isolated completeness tests for below-80% timeline coverage and the exact 80% boundary.
- Add the required real-fixture calibration proving 64 of 69 timing slots passes.
- Keep half-song, one-verse, and every-other-line rejection coverage explicit.
- Add cancellation coverage for a running nonzero revision, a late provider return, retry suppression after cancellation, failed-job callbacks, stalled cleanup, and completed/failed idempotency.
- Add Quick-fix coverage for case-normalized spans, resulting cue overflow, missing cue/token, nonnumeric/negative/overflowing token indexes, wrong owner, expired tracks, punctuation and timing preservation, WebVTT rebuilding, identity rotation, and unchanged billing/expiry data.
- Validate real DELETE and PATCH responses against canonical schemas.

### Contracts

- Add invalid fixtures for every impossible status/stage pair.
- Prove cancellation request validation and the documented `409` and `422` response shapes.
- Ensure generation still includes both cancellation and Quick-fix request types in `dist/index.d.ts`.

### Extension

- Add handler-level tests for target-tab publication after tab switching and suppression after navigation.
- Add recovery tests with two jobs for the same YouTube video.
- Add overlapping mutation and mutation-versus-poll ordering tests.
- Add regeneration-lock tests for correction submission in flight, active correction, panel startup, and background restart recovery.
- Add stale-track conflict recovery using structured error codes.
- Add cancellation response tests where a newer attempt replaces the requested attempt.
- Add UI tests for visible Quick-fix errors, focus movement, mode selection state, replacement progress semantics, active-stage styling, and duplicate-translation suppression.

## Simplification Pass

Apply these only after the correctness fixes above. Do not trade away validation, cancellation safety, or accessibility.

### Backend And Contracts

- `app/backend/app/Http/Controllers/Api/SubtitleJobController.php`: replace the duplicate owner-scoped query in `correctLyrics()` with `ownedJob()`.
- `app/backend/app/Http/Requests/CancelSubtitleLyricsRequest.php`: remove the always-true authorization override and one-call `attemptId()` accessor; use validated data at the controller call site.
- `app/backend/app/Http/Requests/QuickFixSubtitleTokenRequest.php`: remove the always-true authorization override and one-call payload copier; use a direct non-whitespace validation rule and validated data.
- `app/extension/utils/api-response-guards.ts`: remove the broad stage check that is immediately repeated by exhaustive status-specific checks.
- `packages/contracts/schemas/lyrics-correction-status.schema.json`: replace repeated status/stage conditionals with one closed `oneOf` while preserving track and error-field exclusivity.

### Extension

- `app/extension/entrypoints/sidepanel/main.ts`: make the lyrics form submit reveal confirmation only; the separate confirmation button owns the actual request.
- `app/extension/utils/lyrics-correction.ts`: share one correction stage, label, and percentage table with checklist rendering.
- `app/extension/entrypoints/sidepanel/main.ts`: keep cancel-button visibility in one renderer.
- `app/extension/entrypoints/sidepanel/style.css`: reuse the existing current-stage class instead of parallel `active` styling.
- `app/extension/entrypoints/sidepanel/style.css`: remove ineffective `gap`, default restatements, nonexistent-element selectors, and repeated field/link declarations.
- `app/extension/entrypoints/sidepanel/style.css`: share existing token layout and typography declarations with editable token buttons; keep only button-specific differences.

## Handoff Checklist

- Every required correctness item above is implemented.
- Every required regression category has focused coverage.
- No lyrics, replacement text, provider payloads, or private work state enter logs, URLs, storage, or runtime errors.
- No new dependencies, migrations, queues, workflow frameworks, or compatibility paths were added.
- The builder returns control without running validation or changing branches.
- The reviewer runs `./scripts/agent/check.ps1` and browser QA.
- After reviewer acceptance, delete `docs/BUILDERS_NOTES.md` before committing the final feature.

## Reviewer Follow-Up: Validation Round 2

The previous builder handoff did not include changes for this review round. The blocker lines and remaining findings below are still present. Implement this entire section in addition to the earlier requirements. Do not run validation; return control to the reviewer after implementation.

### Release Blockers

#### 1. Replace The Fatal PHP Retry Callback

File: `app/backend/app/Services/Subtitles/LyricsCorrectionService.php:575`

Problem: `$beforeRetry = fn (): void => $this->ensureCorrectionCurrent($correction);` returns an expression from a void function. PHP terminates when the callback executes.

Guidance:

- Replace the arrow function with a block closure that calls `ensureCorrectionCurrent()` without returning its value.
- Keep the callback narrow and retain the current correction instance.
- Confirm every retry callback supplied to translation analysis follows the same valid shape.

Acceptance:

- Loading and invoking `LyricsCorrectionService` no longer terminates PHP.
- Cancellation checks still run immediately before provider retries.

#### 2. Remove The Duplicate Side-Panel Identifier

File: `app/extension/entrypoints/sidepanel/main.ts:632-644`

Problem: `sendPanelRequest()` names its `PanelRequest` parameter `request`, then declares another `const request` for request-order state. TypeScript reports duplicate identifiers and reads ordering fields from the wrong type.

Guidance:

- Keep the message parameter named `request` or rename it to `panelRequest`.
- Give the `beginPanelRequest()` result a distinct name such as `ordering`.
- Read `state`, `version`, and `startedDuringMutation` only from that ordering result.
- Preserve the corrected mutation-versus-poll ordering behavior.

Acceptance:

- `sendPanelRequest()` has no shadowed or duplicate identifier.
- The newest mutation can apply, older mutations are ignored, and polls cannot overwrite in-flight mutations.

#### 3. Make Correction Status A Real Discriminated Union

Files: `packages/contracts/schemas/lyrics-correction-status.schema.json`, `packages/contracts/dist/index.d.ts`, `app/extension/tests/lyrics-correction.test.ts`

Problem: generated types restrict status/stage pairs but still leave completed `track` and failed error fields optional. The broad test factory also creates independently typed status/stage values and fails TypeScript after generation.

Guidance:

- Express each status as a complete closed schema variant so generation produces a useful discriminated union.
- Each variant must include the common required fields plus its exact status/stage pair.
- `completed` must require `track` and forbid error fields.
- `failed` must require `errorCode` and `message` and forbid `track`.
- `queued`, `running`, and `cancelled` must forbid track and error fields.
- Make test fixture builders variant-aware instead of accepting unrelated broad status and stage unions.
- Do not manually patch generated declarations as the source of truth.

Acceptance:

- Generated types reject completed-without-track, failed-without-error, and mismatched status/stage objects.
- Extension test factories compile against the generated union.

### Remaining State And Concurrency Fixes

#### 4. Invalidate Stale Tab Cache After Navigation-Away Quick Fix

Files: `app/extension/entrypoints/background.ts:742-745,1099-1119`, `app/extension/utils/active-tracks.ts`

Problem: when Quick fix succeeds after the originating tab navigates away, the fresh track is remembered but the tab's old ready entry remains. Returning that tab to the original video restores the stale map entry before consulting remembered storage.

Guidance:

- When target-tab validation fails after a successful mutation, remove or replace the captured tab's stale ready state.
- Remember the returned canonical track without publishing it into the tab's new page.
- On return to the original video, recovery must select the fresh track rather than the stale in-memory entry.

Acceptance:

- Quick fix, navigate away before response, then navigate back: the edited track is restored.
- The old track is never published into the interim video.

#### 5. Recover Stale Tracks By Exact Job Identity

Files: `app/extension/entrypoints/background.ts:733-737`, `app/extension/utils/backend-subtitle-state.ts`

Problem: stale-track conflict handling knows `message.jobId` but discards it, clears local state, and later chooses a job by video. Another language variant or recent job can be selected.

Guidance:

- Recover the exact `message.jobId` through the canonical job endpoint.
- Publish only when the captured tab still shows the expected video.
- Do not use first-by-video history selection for stale-track recovery.

Acceptance:

- With two completed jobs for one video, a stale conflict refreshes the job that produced the conflict.

#### 6. Survive Background Restarts Without Duplicate Work

Files: `app/extension/entrypoints/background.ts:47-50,278-353`, `app/extension/utils/active-tracks.ts`, `app/extension/utils/backend-subtitle-state.ts`

Problem: generation locks and tab/job identity are memory-only. After a background restart, a remembered ready track can mask an active generation and permit duplicate generation or correction checks against the wrong same-video variant.

Guidance:

- Persist the minimum tab-scoped job/track identity needed across extension background restarts, using existing extension storage facilities.
- Reconcile that identity with authoritative job and correction status before enabling generation or mutation.
- An active generation must take precedence over a remembered ready track when rebuilding panel state.
- Do not persist lyrics, replacement text, correction work state, or provider data.
- Do not add a generic workflow framework.

Acceptance:

- Restarting the background context during generation cannot enable duplicate generation.
- Restarting during correction still checks the correct job and keeps generation locked.
- Same-video variants do not replace one another accidentally after restart.

### Remaining Backend And Contract Fixes

#### 7. Return Canonical Field Errors For Cue Overflow

Files: `app/backend/app/Services/Subtitles/LyricsCorrectionService.php:242-244`, `app/backend/bootstrap/app.php:70-81`

Problem: resulting cue overflow uses `SubtitleProcessingException`, so the response lacks `error.details.errors.text` even though it uses the `validation_failed` code.

Guidance:

- Raise the existing Laravel validation exception shape for the `text` field.
- Keep the public message specific to the resulting subtitle line being too long.
- Strengthen the HTTP test to assert the full canonical field-error response.

Acceptance:

- Cue overflow returns `validation_failed` with a `text` field error and leaves the track unchanged.

#### 8. Align Whitespace Validation Across Contract And Laravel

Files: `packages/contracts/schemas/quick-fix-token-request.schema.json`, `app/backend/app/Http/Requests/QuickFixSubtitleTokenRequest.php`

Problem: JSON Schema accepts whitespace-only replacement text while Laravel rejects it.

Guidance:

- Add a Unicode-aware non-whitespace pattern to the canonical request schema.
- Use the equivalent direct Laravel validation rule rather than a custom after-validator.
- Add an invalid whitespace-only contract fixture and HTTP test.

Acceptance:

- Contract validation and Laravel reject the same whitespace-only inputs.

#### 9. Type The Stale-Track Error Discriminator

Files: `app/backend/bootstrap/app.php:119-124`, `packages/contracts/schemas/error-object.schema.json`, `app/extension/entrypoints/background.ts:733-737`

Problem: extension recovery depends on `details.reason === "stale_track"`, but the discriminator is not represented by the canonical error contract or generated types.

Guidance:

- Add the optional safe `reason` discriminator and `stale_track` value to the canonical error details shape without adding a new product error code.
- Assert the detail on stale identity conflicts and its absence on ordinary correction-in-progress conflicts.
- Keep UI branching on structured data, not message copy.

Acceptance:

- Backend, generated contracts, runtime guards, and extension recovery agree on the discriminator.

#### 10. Reject Unknown Correction Status Fields

Files: `app/extension/utils/api-response-guards.ts:115-146`

Problem: the canonical status schema has `additionalProperties: false`, but the runtime guard accepts unknown fields. Private fields such as lyrics or work state could cross the extension boundary unnoticed.

Guidance:

- Reject keys outside the exact public status shape before returning the typed object.
- Add tests for unknown private-looking fields on every relevant state shape.

Acceptance:

- Runtime validation matches the closed canonical schema.

### Remaining UI And Accessibility Fixes

#### 11. Include Queued In The Shared Stage Checklist

Files: `app/extension/utils/lyrics-correction.ts:5-16`, `app/extension/entrypoints/sidepanel/main.ts`

Problem: queued progress falls back to a label and percentage but is absent from the checklist table. No checklist item becomes current and none receives `aria-current="step"`.

Guidance:

- Add the queued `Waiting to start` row at 0% to the shared stage table.
- Keep one table as the source for progress labels, percentages, and checklist rendering.

Acceptance:

- Queued replacement visibly and semantically marks `Waiting to start` as current.

#### 12. Keep Terminal Correction Feedback Visible

Files: `app/extension/entrypoints/sidepanel/index.html`, `app/extension/entrypoints/sidepanel/main.ts`

Problem: if the panel reopens during correction and that correction later fails or is cancelled, progress disappears and feedback is written only inside the collapsed Edit view.

Guidance:

- Put terminal replacement feedback in a visible status surface outside the collapsed editor, or reveal the relevant editor status when terminal state arrives.
- Keep the current transcript visible and clearly state that it was unchanged on failure or cancellation.

Acceptance:

- Failure and cancellation remain visible when Edit was closed before the terminal response.

#### 13. Finish Progress Accessibility

Files: `app/extension/entrypoints/sidepanel/index.html:100-104`, `app/extension/entrypoints/sidepanel/main.ts`

Problem: the progressbar has values but no accessible name. Polling also rewrites the live region even when operation and stage did not change.

Guidance:

- Associate the progressbar with the dynamic operation label through `aria-labelledby`, or update a specific `aria-label`.
- Update the polite live region only when operation or stage changes.
- Keep `aria-current="step"` on exactly one active stage.

Acceptance:

- Screen readers receive one meaningful announcement per stage change, not one per poll.

#### 14. Finish Focus Management

Files: `app/extension/entrypoints/sidepanel/main.ts`

Problem: local Quick-fix cancel, confirmed replacement submission, and successful cancellation still hide focused controls without moving focus.

Guidance:

- Move focus to a stable visible control before or immediately after each hiding transition.
- Quick-fix cancel and success should return focus to the Edit control or transcript region.
- Confirmed replacement should move focus to the visible progress surface or cancellation control.
- Successful cancellation should return focus to Edit or the transcript.

Acceptance:

- Keyboard focus never falls back to the document body during editing, confirmation, progress, cancellation, or success.

#### 15. Finish Input And Long-Content Handling

Files: `app/extension/entrypoints/sidepanel/index.html:152`, `app/extension/entrypoints/sidepanel/style.css`

Problem: the Quick-fix input lacks `name` and `autocomplete="off"`. Long unbroken source or edited token text can overflow the 320px panel.

Guidance:

- Add a meaningful input name and disable irrelevant browser autocomplete.
- Add `overflow-wrap: anywhere`, `max-width: 100%`, and required flex `min-width: 0` behavior to source and editable token content.

Acceptance:

- An 84-code-point unbroken token remains usable without horizontal clipping at 320px.

### Missing Regression Coverage

#### 16. Exercise Real Provider Cancellation Paths

Files: `app/backend/tests/Feature/LyricsCorrectionContinuationTest.php`, `app/backend/tests/Feature/RecordingTranslationAnalysisProvider.php`

Problem: the current retry test manually invokes fake hooks and does not exercise the production single-cue reprompt path. There is also no valid provider response returning after cancellation test.

Guidance:

- Use the production provider with fake agent responses for a first invalid single-cue response followed by a potential retry.
- Cancel between responses and assert no second prompt starts.
- Cancel from an in-flight before-result hook, return a valid response, and assert no progress commit, continuation dispatch, duplicate cost, or track mutation.

#### 17. Complete Completeness Calibration

File: `app/backend/tests/Feature/LyricsCorrectionContinuationTest.php`

Problem: current tests combine slot and timeline failures and use synthetic uniform cues for the named `64/69` case.

Guidance:

- Add independent below-threshold and exact-boundary cases for 60% slot coverage.
- Add independent below-threshold and exact-boundary cases for 80% timeline coverage.
- Use the real `Y_vB-3R_BYc` timing fixture for the required 64-of-69 acceptance case.
- Keep explicit half-song, one-verse, and every-other-line failures.

#### 18. Complete HTTP And Contract Boundaries

Files: `app/backend/tests/Feature/SubtitleJobApiTest.php`, `app/backend/tests/Feature/ContractResponseValidationTest.php`, `packages/contracts/scripts/validate.mjs`

Guidance:

- Test cancellation with missing/invalid attempt ID, stale attempt, wrong owner, expired track, and running nonzero revision.
- Validate real cancellation `409` and `422` responses against canonical schemas.
- Test Quick-fix missing cue/token, nonnumeric, negative, maximum accepted, and overflowing route indexes.
- Test punctuation and timing preservation, repeated case-insensitive occurrence selection, WebVTT rebuilding, identity rotation, unchanged usage, and unchanged expiry.
- Cover every valid status/stage pair and generate the complete invalid status/stage matrix instead of five samples.
- Add extension handler tests for tab switching, navigation, same-video jobs, restart locking, exact-job stale recovery, immediate and polled publication, and attempt-bound cancellation.
- Add UI regression tests for mode pressed state, queued current stage, progress labels, focus movement, visible terminal errors, long token handling, and duplicate-translation suppression.

### Validation Harness Fix

#### 19. Make Repository Check Fail Fast

File: `scripts/agent/check.ps1`

Problem: PowerShell continues after failed native commands and prints `Agent scaffold checks completed` after backend, TypeScript, or build failures.

Guidance:

- Check `$LASTEXITCODE` after each native command and throw or exit nonzero immediately.
- Print the completion message only when every check succeeds.
- Keep the existing command order and `-SkipAppChecks` behavior.

Acceptance:

- A failing backend test, TypeScript compile, contract check, or extension build makes the script return nonzero and skips the success message.

### Follow-Up Ponytail Pass

Apply these after correctness and tests:

- `app/backend/app/Http/Controllers/Api/SubtitleJobController.php`: pass validated Quick-fix fields directly instead of reconstructing them.
- `app/backend/app/Http/Requests/QuickFixSubtitleTokenRequest.php`: replace the custom after-validator with the direct non-whitespace rule.
- `app/backend/app/Services/Subtitles/LyricsCorrectionService.php`: compare selected normalized token values inside the selected branch and remove sentinel variables.
- `app/extension/entrypoints/background.ts`: use one per-tab subtitle mutation lock when generation and correction are mutually exclusive.
- `app/extension/entrypoints/sidepanel/main.ts`: use one Quick-fix error object and one reset function.
- `app/extension/entrypoints/sidepanel/style.css`: remove selectors for disabled textarea and editable-token child markup that never exist.
- `app/extension/utils/active-tracks.ts`: require `expectedTrackId` because its only caller always supplies it.
- `app/extension/utils/panel-request-order.ts`: make completion mutation-only and decrement the balanced counter directly.

### Follow-Up Handoff

- Inspect the exact blocker lines before returning; they must no longer contain the fatal void arrow function or duplicate `request` declaration.
- Keep `docs/BUILDERS_NOTES.md` for the reviewer.
- Do not run tests, generators, formatters, builds, browser automation, or service commands.
- Do not commit, switch branches, or restart services.
- Return a concise list of implemented follow-up items and any exact blocker.
