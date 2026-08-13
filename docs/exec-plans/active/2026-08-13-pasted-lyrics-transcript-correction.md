# Plan: Pasted Lyrics Transcript Correction

Status: active
Owner: agent
Created: 2026-08-13
Last updated: 2026-08-13

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

- [ ] Run the baseline harness and record existing failures without modifying unrelated user changes.
- [ ] Add the two API operations, request/status schemas, generated types, correction table, model, and focused persistence tests.
- [ ] Add validation, owner/plan checks, the structured alignment agent, one queued correction job, atomic publication, stale cleanup, and focused backend tests.
- [ ] Add the inline panel form, submit/status polling, attempt-ID guard, clicked-token race guard, and focused extension tests.
- [ ] Update product, architecture, frontend, reliability, security, and quality docs only where the shipped behavior changes them.
- [ ] Run focused checks, the full harness, visual QA at normal and 320px widths, and a final simplicity review.

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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-08-13 | Initial plan created after tracing the completed-track, AI analysis, WebVTT, retention, and clicked-token flows. | Repository docs and source listed in the prior draft. |
| 2026-08-13 | Plan simplified with Ponytail full mode. | Removed restore storage, track revisions, a restore endpoint, speculative thresholds, and a multi-slice framework while keeping validation, privacy, accessibility, and atomic publication. |
| 2026-08-13 | Baseline completed before feature edits. | `doctor.ps1` passed; contracts passed; backend 318 tests passed; extension 127 tests passed; TypeScript compile and WXT build passed. Existing unrelated worktree edits were preserved. Loaded `laravel-best-practices`, `laravel-security`, `subtitle-pipeline`, and `ai-sdk-development`; local skill guidance materially affected queue, encrypted persistence, provider, and logging decisions. |

## Completion Notes

- What changed:
- Validation results:
- Simplicity review:
- Residual risk:
