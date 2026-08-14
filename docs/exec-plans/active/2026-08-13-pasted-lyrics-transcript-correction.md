# Plan: Pasted Lyrics Transcript Correction

Status: active (second automated review found implementation blockers; product QA remains)
Owner: agent
Created: 2026-08-13
Last updated: 2026-08-14

## Goal

Let a learner paste accurate song lyrics and replace an inaccurate generated transcript. The pasted text supplies the words. The existing transcript supplies the timing. AI aligns and formats the text, the backend rebuilds the derived learning data, and the corrected track replaces the old one only after the whole operation succeeds.

This is not a general subtitle editor. There is no file upload, lyrics search, timestamp editing, preview, history, or restore action in the first version. If the result is not good enough, the learner can paste the lyrics again.

## User Flow

1. A completed transcript shows **Use pasted lyrics**.
2. The learner pastes plain text into one labeled textarea and selects **Format and apply**.
3. The current transcript keeps working while the backend processes the correction.
4. On success, the transcript and video overlay update without a page reload.
5. On failure, the current transcript stays unchanged and the learner can retry.

Suggested copy:

```text
Use pasted lyrics
Paste the complete lyrics. We will keep the wording and fit it to the generated timing.
Format and apply
Formatting lyrics... Your current subtitles will keep playing.
Pasted lyrics applied
```

## Scope

In scope:

- Plain-text paste on a completed, unexpired track.
- AI alignment against the track's existing timed cues.
- Readable cue breaks with the existing 84-character limit.
- Exact preservation of pasted wording, case, punctuation, and order.
- Rebuilding tokens, translation, romanization, word-card data, and WebVTT with the original job settings.
- Atomic replacement after all validation and analysis succeeds.
- One queued or running correction per account and per track.
- Queue-safe bounded work: correction work runs as one attempt row driven by narrow continuation executions, each performing at most one bounded unit (alignment, or one derived batch, or finalization) and persisting progress in encrypted per-attempt state before the next revision is dispatched.
- Focused contract, backend, and extension tests.

Out of scope:

- Manual transcript or timestamp editing.
- File upload, URL input, online search, or scraping.
- Preview, undo, restore, version history, or shared corrections.
- Partial and still-running tracks.
- A second alignment algorithm or a new correction pipeline framework.
- Charging generated-video minutes again.

## Acceptance Criteria

### User experience

- [ ] **Use pasted lyrics** appears only for a completed track.
- [ ] The inline form is keyboard accessible and usable at the 320px panel width.
- [ ] The textarea accepts plain text, shows the 25,000-character limit, and never renders pasted HTML.
- [ ] Submit is disabled for empty or over-limit input and while the current attempt is queued or running.
- [ ] Closing and reopening the panel recovers the current attempt status.
- [ ] The existing transcript remains active until a corrected track is ready.
- [ ] Success updates the transcript, search, copy actions, overlay, and WebVTT without a reload.
- [ ] Failure leaves the existing track unchanged and shows a stable retry message.

### Alignment and formatting

- [ ] Normalize line endings, trim lines, collapse repeated spaces and blank lines, and reject text with no Unicode letters or numbers.
- [ ] Preserve the pasted words, case, punctuation, and source order. Line breaks are hints, not fixed cue boundaries.
- [ ] The AI returns cue identity and corrected `sourceText` only. It never returns timestamps or derived learning data.
- [ ] The AI returns one corrected text span for each existing timing slot, in the same order.
- [ ] The server verifies that every returned span occurs in forward order in the normalized paste, so invented or reordered text cannot be published.
- [ ] Common non-sung labels such as `[Verse 1]` may be skipped.
- [ ] The server copies timing from the existing cues, creates fresh cue IDs, and enforces non-empty text, monotonic timing, and the 84-character limit.
- [ ] A clear wrong-song or invalid alignment fails without changing the track.
- [ ] Invalid AI output gets one retry. There is no heuristic fallback.

### Data and safety

- [ ] No derived field from replaced text survives. Tokens, translation, romanization, full word cards, and WebVTT are rebuilt.
- [ ] Track replacement and attempt completion happen in one database transaction.
- [ ] Each attempt has a UUID. A stale worker or polling response cannot publish over a newer attempt.
- [ ] Fresh cue IDs prevent a late clicked-token response from merging data into corrected cues.
- [ ] The extension re-reads the active track after clicked-token network calls and ignores a response when its cue no longer exists.
- [ ] Pasted lyrics are encrypted at rest, excluded from logs/traces, and cleared on success, failure, or stale-attempt cleanup.
- [ ] Correction does not extend the track's expiry or debit video minutes.

### API and access

- [ ] Endpoints use the existing install-ID middleware, Sanctum auth, write ability, throttles, and owner scoping.
- [ ] Starting provider work requires an active or trialing plan.
- [ ] Empty input, input over 25,000 Unicode characters, wrong ownership, expired tracks, and concurrent attempts are rejected before dispatch.
- [ ] API schemas remain canonical under `packages/contracts`; generated extension types stay in sync.

## Technical Design

### 1. API

Add two authenticated endpoints under the existing subtitle job routes:

```text
POST /v1/subtitle-jobs/{jobId}/lyrics
GET  /v1/subtitle-jobs/{jobId}/lyrics
```

`POST` accepts:

```json
{
  "lyrics": "complete pasted lyrics"
}
```

It returns HTTP 202 with:

```text
attemptId
status: queued | running | completed | failed
updatedAt
track      only when completed
errorCode  only when failed
message    only when failed
```

`GET` returns the same status resource. No correction ID is needed in the URL because a track has one current attempt. `attemptId` lets the extension ignore a late response from an older attempt.

Use existing error responses where possible. Add only:

```text
lyrics_correction_in_progress
lyrics_do_not_match
lyrics_correction_failed
```

Do not change `TrackResponse`.

### 2. Persistence and queueing

Add one `subtitle_track_lyrics_corrections` table:

```text
id
subtitle_track_id   unique foreign key, cascade delete
attempt_id          UUID
status              queued | running | completed | failed
lyrics              nullable encrypted text
error_code          nullable stable code
error_message       nullable public-safe message
created_at / updated_at
```

Submission uses one transaction:

1. Resolve the owned, completed, unexpired track and lock the owning account row.
2. Reject another queued/running correction for the track or account.
3. Normalize and store the paste with a new attempt ID.
4. Dispatch one correction job after commit on the existing account-tier batch queue.

The account lock serializes concurrent submissions before the active-attempt query. The queued payload contains database IDs and the attempt ID, never the lyrics. Every worker status write, including `failed()`, is scoped to the same attempt ID. Reuse the existing batch concurrency middleware and provider cost recorder. Do not add a new queue, pipeline abstraction, or feature flag.

Clear the encrypted paste in `handle()` and `failed()`. Extend the existing scheduled subtitle cleanup path to fail and clear attempts that remain queued/running beyond the worker timeout.

### 3. Alignment and formatting

Add one structured-output `LyricsAlignmentAgent` using the configured analysis model and existing Laravel AI SDK.

Input:

```text
effective source language
normalized pasted lyrics
existing cues: cueId, index, startMs, endMs, sourceText
```

Output:

```text
isMatch
cues[]: cueId, index, sourceText
```

Prompt rules:

- Pasted lyrics are the only text source.
- Existing cue text is alignment evidence only.
- Return one entry for every timing slot, in the same order.
- Split on natural phrase boundaries and keep each cue at or below 84 characters.
- Preserve pasted wording, case, punctuation, and order.
- Skip obvious non-sung labels and credits.
- Return `isMatch: false` for a different song or unreliable match.

Server validation:

- Require `isMatch: true` and exactly one result for each input cue.
- Require the original cue identities and order.
- Find each collapsed `sourceText` as an exact, forward-only span of the normalized paste.
- Reject empty, duplicated, reordered, invented, or overlong cue text.
- Retry once on invalid structured output, then fail the attempt.

Keep normalization as a small private function in the correction service or reuse an existing text helper. Do not add a standalone normalization subsystem.

### 4. Rebuild and publish

The server builds draft corrected cues by copying the original timing, reindexing, and assigning IDs such as `lyrics-{attempt-prefix}-0001`. It then reuses the existing analysis methods:

1. Tokenize, or tokenize and translate when translation is enabled.
2. Romanize when the original job settings require it.
3. Run full enrichment only when the original job used full word cards.
4. Generate WebVTT with the existing formatter.

Run these steps inside one queued job. Extract shared batch planning from the existing generator only if direct reuse is not possible; do not create a general correction pipeline.

After every step succeeds, lock the attempt and track rows in one transaction. Confirm the attempt ID is still current, replace `cues` and `web_vtt`, mark the attempt completed, and clear `lyrics`. Any earlier failure updates only the attempt and leaves the track untouched.

### 5. Extension

Keep the feature in the completed Watch view:

- Add one collapsible paste form beside the transcript controls.
- Add typed submit/status messages and API guards from the canonical contracts.
- Poll the correction status at the existing active interval while queued/running.
- Keep the textarea only in panel memory; never put lyrics in extension storage.
- Accept a completed response only when its `attemptId` matches the active attempt.
- Publish and remember the returned track so the existing transcript and content-script update paths replace the display.
- Escape all displayed text through the existing helpers.

## Implementation Steps

- [x] Run the baseline harness and record existing failures without modifying unrelated user changes.
- [x] Add the two API operations, request/status schemas, generated types, correction table, model, and focused persistence tests.
- [x] Add validation, owner/plan checks, the structured alignment agent, one queued correction job, atomic publication, stale cleanup, and focused backend tests.
- [x] Add the inline panel form, submit/status polling, attempt-ID guard, clicked-token race guard, and focused extension tests.
- [x] Update product, guardrail, and contract docs where the shipped behavior changed.
- [ ] Run the full harness and visual QA at normal and 320px widths; automated focused checks and the final simplicity review are complete.

## Likely Touchpoints

- Contracts: `packages/contracts/openapi.json`, two new schemas, generated types, and fixtures.
- Backend: subtitle routes; one form request/controller; one migration/model; one alignment agent; one service/job; existing analysis, WebVTT, cleanup, and clicked-token paths; focused tests.
- Extension: side-panel transcript markup/controller/styles; background messages and API client/guards; clicked-token response handling; focused tests.
- Docs: `docs/product-specs/index.md`, `ARCHITECTURE.md`, `docs/FRONTEND.md`, `docs/RELIABILITY.md`, `docs/SECURITY.md`, and `docs/QUALITY_SCORE.md` where applicable.

## Validation

```powershell
Push-Location .\packages\contracts
npm run check
Pop-Location

Push-Location .\app\backend
vendor\bin\pint --dirty --format agent
php artisan test --compact --filter=PastedLyrics
php artisan test --compact --filter=LearningToken
Pop-Location

Push-Location .\app\extension
npm test
npm run compile
npm run build
Pop-Location

.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Minimum behavior checks:

- Valid same-language, translated, non-Latin, and full-word-card corrections.
- Whitespace, stanza breaks, combining marks, punctuation, emoji, and section labels.
- Wrong song; invented, reordered, missing, duplicated, empty, and overlong AI output.
- One retry, terminal failure, unchanged active track, and cleared stored lyrics.
- Anonymous, wrong owner, inactive plan, expired track, invalid input, and concurrent submit.
- Stale worker, stale polling response, stale clicked-token response, and deleted track.
- Successful UI update without reload; panel reopen; 320px layout; keyboard and focus behavior.
- No pasted or corrected text in logs/traces and no second video-minute debit.

## Decisions

| Date | Decision | Reason |
| --- | --- | --- |
| 2026-08-13 | Accept pasted plain text only. | This is the requested narrow workflow. |
| 2026-08-13 | Treat pasted lyrics as text and existing cues as timing. | Untimed lyrics cannot supply reliable timestamps. |
| 2026-08-13 | Use one structured AI call plus server validation. | AI handles semantic alignment; the server prevents invented text and owns timing. |
| 2026-08-13 | Keep the old track until atomic success. | Provider or validation failure must not destroy working subtitles. |
| 2026-08-13 | Rebuild all derived data. | Existing learning metadata is stale after source text changes. |
| 2026-08-13 | Skip preview, restore, history, track revisions, and a new pipeline. | None is required for paste-and-replace; attempt IDs and fresh cue IDs cover the real races. |
| 2026-08-13 | Require an active plan but do not debit video minutes. | Correction has provider cost but does not transcribe another video. |
| 2026-08-14 | Run correction as one attempt row driven by narrow continuation executions of one `LyricsCorrectionJob` class, not one long-running queue job. | The reused derived-data providers recursively split invalid multi-cue output, so a monolithic job timeout cannot bound real provider work; the 22-cue admission cap that made the old timeout plausible also rejected ordinary full-song tracks. Each execution performs one bounded unit (at most two provider calls), persists encrypted progress plus a revision, and dispatches exactly one continuation after commit. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-08-13 | Initial plan created after tracing the completed-track, AI analysis, WebVTT, retention, and clicked-token flows. | Repository docs and source listed in the prior draft. |
| 2026-08-13 | Plan simplified with Ponytail full mode. | Removed restore storage, track revisions, a restore endpoint, speculative thresholds, and a multi-slice framework while keeping validation, privacy, accessibility, and atomic publication. |
| 2026-08-13 | Baseline completed before feature edits. | `doctor.ps1` passed; contracts passed; backend 318 tests passed; extension 127 tests passed; TypeScript compile and WXT build passed. Existing unrelated worktree edits were preserved. Loaded `laravel-best-practices`, `laravel-security`, `subtitle-pipeline`, and `ai-sdk-development`; local skill guidance materially affected queue, encrypted persistence, provider, and logging decisions. |
| 2026-08-14 | Implemented the continuation design prescribed by the second-review remediation. | `subtitle_track_lyrics_corrections` now persists `work_revision` and encrypted `work_state`; `LyricsCorrectionService` processes one unit per `process()` call with the prescribed stages; `LyricsCorrectionJob` carries `expectedRevision`, uses an attempt-keyed `WithoutOverlapping` lock, a `(provider timeout * 2) + 60` second timeout, and clears private state on terminal failure; the two-batch/22-cue admission cap, `maxBatchCountForCueCount()`, and correction-only timeout config were removed; stalled cleanup no longer fails `queued` corrections; the extension adopts newer server attempts at the latest request revision, keeps `syncBackend: false` local, and clears panel paste state on video change; the runtime guard enforces status-state exclusivity. Regressions cover full-song tracks, batch-plan division, split-retry opt-out, one-stage-per-revision, stale/duplicate deliveries, transient resumption, wrong-song termination, entitlement loss, queued/running stalled cleanup, rebuilt settings variants, atomic publication, encrypted-at-rest state, and per-unit timeout bounds. Contracts, backend (350 tests), extension (143 tests), compile, build, and Pint pass. Manual browser and visual QA remain outstanding for the product owner. |

## Review Findings

Reviewed branch: `codex/pasted-lyrics-transcript-correction` after the correction refactor

Review verdict: **automated findings resolved**. The design direction is retained with bounded queue work, stricter validation, fresh entitlement checks, and revisioned extension synchronization. Manual browser and visual testing remain assigned to the product owner.

### Must fix

- [x] **Fix extension compilation.** `background.ts` now narrows one locally stored subtitle state, and correction syncs reject out-of-order responses by per-tab request ID. `npm run compile` passes.
- [x] **Make transient provider retries resumable.** Transient failures return the attempt to `queued` before rethrowing, and `running` attempts are safely resumable. The regression test covers a first-call 429 followed by success.
- [x] **Prevent label filtering from dropping sung text.** Only complete, tightly defined label or credit lines are removed before flattening; all remaining text must be consumed in forward order. The regression test covers a sung line beginning with `Chorus`.
- [x] **Give valid corrections a queue-safe execution bound.** Corrections are capped at two shared analysis batches, use a 1,080-second job timeout, and enforce the timeout below queue `retry_after` with a configuration invariant test.
- [x] **Recheck entitlement immediately before provider work.** The worker reloads the owning user and checks the active plan before alignment and before each derived-data provider batch. The regression test confirms no alignment prompt after entitlement expiry.
- [x] **Use the real active attempt ID when accepting a completed track.** Correction status synchronization is revisioned per tab, stale responses are ignored, and only the locally active attempt is accepted while queued or running. The extension test covers an out-of-order response.

### Should improve

- [x] **Record sanitized unexpected failures.** Catch-all failures now log only internal IDs, stage, and exception class; lyrics, prompts, and corrected text are excluded.
- [x] **Complete the API contract.** OpenAPI now declares 402 and 409, 202 describes asynchronous acceptance, and the status schema forbids track/error fields in the wrong states. Invalid status fixtures are rejected.
- [x] **Update durable documentation.** Product scope and guardrails document pasted-lyrics correction as the narrow exception. This plan records the bounded-work decision and validation evidence.
- [x] **Add regression coverage for the trust boundaries.** Added focused coverage for label safety, transient retry, entitlement expiry, bounded timeout, stale sync revision, and contract state validity; existing alignment and atomic-publication tests remain in place.

### Ponytail review

- [x] `background.ts` and `lyrics-correction.ts`: removed the tautological track helper and kept one revisioned stale-response check per tab.
- [x] `sidepanel/main.ts`: removed `effectiveState`; the background now returns the corrected subtitle state.
- [x] `LyricsCorrectionService`: trusts the boundary-normalized encrypted lyrics during processing.
- [x] `LyricsCorrectionService`: reuses shared batch planning and WebVTT formatting helpers.
- [x] `LyricsCorrectionService` and `LyricsCorrectionJob`: keep one queue-connection assignment in the job constructor.
- [x] `SubtitleTrackLyricsCorrectionResource` and `SubtitleJobController`: use the eager-loaded one-to-one relation without a second query or sort.

Ponytail estimate: `net: -85 lines possible.`

### Automated review evidence

| Check | Result |
| --- | --- |
| Contract validation and generated types | Passed |
| Backend suite | Passed: 330 tests, 2,486 assertions |
| Extension test suite | Passed: 131 tests |
| TypeScript compile | Passed |
| Extension production build | Passed |
| Pint on changed PHP files | Passed |
| Full-repository Pint | Existing failure outside this branch diff in `tests/Feature/SubtitleRuntimeTracingTest.php` |
| Documentation harness | Passed |
| `scripts/agent/check.ps1` | Passed |
| `git diff --check main...HEAD` | Passed |
| Manual browser and visual QA | Not run; assigned to product owner |

### Second review — 2026-08-14

Reviewed state: uncommitted remediation changes on `codex/pasted-lyrics-transcript-correction`, on top of `8b661e9`.

Review verdict: **needs refactor before merge**. The compilation, transient retry transition, label filtering, entitlement recheck, sanitized logging, contract, shared WebVTT, and returned-panel-state fixes are sound. The new queue-bound solution is not sound: it rejects ordinary full-song tracks and still does not cover the recursive provider work it claims to bound. Manual browser and visual testing remain assigned to the product owner.

#### Must fix

- [ ] **Remove the two-batch admission cap as the product limit.** `LyricsCorrectionService::ensureCorrectionWorkFitsQueue()` and `SubtitleJobArtifactStore::maxBatchCountForCueCount()` use the 1,000-character batch budget, 84-character maximum cue size, and two-batch limit to reject every track above 22 cues. The transcript normalizer permits at most six seconds per cue, so this excludes many ordinary songs and reduces the practical sung-text ceiling to about 1,848 characters despite the documented 25,000-character paste limit. Rewriting the plan to call this cap in scope does not make it consistent with the original full-song correction goal. Support the completed track or expose a deliberately chosen product limit with user-visible validation and product approval.
- [ ] **Replace the inaccurate timeout calculation.** `LyricsCorrectionJob::timeoutSeconds()` budgets at most three provider calls per accepted batch, but the reused tokenization, analysis, and enrichment services recursively split invalid multi-cue output. An 11-cue batch can make up to 21 prompt calls in one derived stage, so the 1,080-second timeout is not a real upper bound even after rejecting larger tracks. Use a correction execution path with an actually bounded retry count, or decompose the work into queue units whose timeout and retry behavior are independently enforceable.
- [ ] **Prevent duplicate workers from processing the same running attempt.** `LyricsCorrectionService::process()` accepts both `queued` and `running`, so two deliveries of the same queue message can both pass the row lock and perform the full provider workflow. The final transaction prevents a second publication, but it does not prevent duplicate AI cost, rate-limit use, or long-running work. Atomically claim only `queued` attempts; transient failures already return the attempt to `queued`. If crash resumption is required, add an exact-attempt overlap lock instead of treating every `running` row as available work.
- [ ] **Allow polling to adopt a newer server attempt.** `background.ts` rejects every backend response whose attempt ID differs from a locally cached queued/running attempt. The per-tab request revision already rejects stale local requests. If another extension instance starts a new attempt after the cached attempt completes on the server but before this panel observes completion, this guard ignores the new attempt forever. Accept the latest response for the latest request, including a changed attempt ID.
- [ ] **Do not expire queued corrections on the worker execution timeout.** `FailStalledSubtitleJobs` applies the 1,200-second worker timeout plus slack to both `queued` and `running` corrections. A valid correction waiting in a busy batch queue for about 22 minutes is failed and its encrypted lyrics are deleted before a worker starts it. Use separate queue-wait and running-work thresholds, or restrict this execution-time watchdog to `running` rows.

#### Should improve

- [ ] **Keep `syncBackend: false` local.** `syncLyricsCorrection()` still calls the status endpoint when no correction is cached because it returns early only for `!syncBackend && current`. Initial panel load therefore waits on a status request and then immediately performs a second backend refresh. Return `current` for every non-sync call.
- [ ] **Clear panel-only lyrics when the active video changes.** `showPanelState()` resets other watch-view state on a video change but leaves the textarea populated. Lyrics pasted for one song remain visible and submit-ready on another completed song in the same panel session. Clear the textarea and rerender its counter when `watchVideoId` changes.
- [ ] **Make the runtime guard enforce the status schema.** `guardLyricsCorrectionStatus()` requires the positive fields for completed and failed states but still accepts `track` on queued/running/failed responses and error fields on queued/running/completed responses. Mirror the contract's mutually exclusive state fields and add guard tests for both invalid shapes.
- [ ] **Add regression tests for the unmodeled boundaries.** Cover a normal track above 22 cues, recursive split-retry timeout behavior, duplicate delivery of one attempt, adoption of a newer attempt ID, a long queue wait, non-sync panel state without a cache entry, and textarea reset on video change.

#### Ponytail review

- `app/backend/app/Services/Subtitles/LyricsCorrectionService.php:L540-552` and `app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php:L430-442`: `delete:` worst-case cue-count estimator that rejects normal songs without actually bounding recursive provider retries. Replace it with the corrected queue execution boundary.
- `app/extension/utils/lyrics-correction.ts:L18-20`: `shrink:` one-use equality wrapper plus a dedicated two-assert test. Inline the request-revision comparison in `background.ts`.
- `app/backend/app/Http/Requests/CorrectSubtitleLyricsRequest.php:L25-32`: `shrink:` duplicate 25,000-character check after Laravel's `max:25000` string rule. Keep the rule and the Unicode letter/number validation.
- `packages/contracts/scripts/validate.mjs:L71-115`: `delete:` the same two invalid correction states are tested once through fixture files and again through inline objects. Keep one representation.
- `app/backend/app/Http/Resources/SubtitleTrackLyricsCorrectionResource.php:L18-22`: `yagni:` fallback relation query even though both controller paths supply `track.job` eagerly. Read the loaded relation directly.
- `app/backend/app/Services/Subtitles/LyricsCorrectionService.php:L258-273`: `delete:` a second paid alignment call after the model has already returned the definitive `lyrics_do_not_match` state. Retry malformed alignment output, not an explicit mismatch.

`net: -45 lines possible.`

#### Second-review automated evidence

| Check | Result |
| --- | --- |
| `scripts/agent/check.ps1` | Passed |
| Contract validation and generated types | Passed |
| Backend suite | Passed: 330 tests, 2,486 assertions |
| Extension test suite | Passed: 131 tests |
| TypeScript compile | Passed |
| Extension production build | Passed |
| Pint on feature PHP files | Passed |
| Documentation harness | Passed |
| `git diff --check main` | Passed |
| Manual browser and visual QA | Not run; assigned to product owner |

## First Remediation Completion Notes

- What changed: Fixed extension narrowing and stale status application; made correction retries resumable; removed broad label-gap skipping; added fresh entitlement checks, sanitized failure logs, and a queue-safe two-batch/1,080-second execution bound; reused batch planning and WebVTT formatting; tightened contracts and durable scope documentation.
- Validation results: Contracts validation and generated types passed; extension tests (131), TypeScript compile, and production build passed; backend suite passed with 330 tests and 2,486 assertions; Pint and `scripts/agent/check.ps1` passed. Visual QA remains a product-owner follow-up.
- Simplicity review: Removed the tautological track-acceptance helper, side-panel shadow-state repair, duplicate lyric normalization, duplicate batch planning, duplicate WebVTT formatting, duplicate queue connection assignment, and redundant relation query/sort.
- Residual risk: Manual browser/visual QA at normal and 320px widths remains outstanding. Corrections larger than two shared analysis batches are rejected before provider work so the single job stays below queue `retry_after`.
