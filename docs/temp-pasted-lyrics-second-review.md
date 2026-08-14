# Temporary Review: Pasted Lyrics Transcript Correction

Reviewed state: uncommitted remediation changes on `codex/pasted-lyrics-transcript-correction`, on top of `8b661e9`.

Review date: 2026-08-14

## Verdict

**Needs refactor before merge.** The earlier compilation, validation, entitlement, logging, contract, and state-return issues were fixed correctly. However, the remediation introduced five reliability and product blockers.

The fixes are currently uncommitted on top of `8b661e9`.

## Must Fix

### 1. The feature rejects normal full-song transcripts

- **Category:** Product correctness / queue design
- **Severity:** Must fix
- **Problem:** `LyricsCorrectionService::ensureCorrectionWorkFitsQueue()` rejects corrections estimated above two batches. With the defaults in `SubtitleJobArtifactStore::maxBatchCountForCueCount()`, that means a maximum of 22 cues.
- **Why it matters:** Generated cues are capped at six seconds, so a song with more than roughly 132 seconds of sung material can exceed the limit. It also creates an effective maximum of about 1,848 lyric characters despite the UI and API advertising 25,000.
- **Suggested improvement:** Remove this cap and design queue execution that supports the complete generated track. If a smaller product limit is genuinely required, it needs user-visible validation and explicit product approval.

Locations:

- `app/backend/app/Services/Subtitles/LyricsCorrectionService.php:540-550`
- `app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php:430-442`
- `app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php:13-17`

### 2. The timeout calculation does not model the real provider work

- **Category:** Reliability
- **Severity:** Must fix
- **Problem:** `LyricsCorrectionJob::timeoutSeconds()` assumes at most three provider calls per derived-data batch. The reused translation-analysis provider recursively splits invalid batches.
- **Why it matters:** An 11-cue batch can make as many as 21 prompt calls during a single derived stage. The claimed 1,080-second upper bound is therefore not an upper bound.
- **Suggested improvement:** Use correction-specific calls with a truly bounded retry count, or split correction into independently bounded queue units. Add a test that exercises recursive split retries.

Locations:

- `app/backend/app/Jobs/LyricsCorrectionJob.php:66-79`
- `app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php:49-75`
- `app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php:313-345`

### 3. Duplicate queue deliveries can run the same attempt concurrently

- **Category:** Concurrency / provider cost
- **Severity:** Must fix
- **Problem:** `LyricsCorrectionService::process()` allows both `queued` and `running` attempts to enter processing.
- **Why it matters:** Two deliveries can both observe `running` after acquiring the row lock and then perform the complete AI workflow. Finalization prevents a second publication, but not duplicate provider cost, rate-limit usage, or worker occupancy.
- **Suggested improvement:** Atomically claim only `queued` attempts. Transient errors already transition back to `queued`. If recovery of abandoned `running` work is required, use an exact-attempt overlap lock or a separate recovery transition.

Location: `app/backend/app/Services/Subtitles/LyricsCorrectionService.php:122-139`

### 4. Polling can permanently ignore a newer attempt

- **Category:** Extension state synchronization
- **Severity:** Must fix
- **Problem:** `background.ts` rejects a response with a different attempt ID whenever the cached attempt is queued or running.
- **Why it matters:** If attempt A completes and another extension instance submits attempt B before this panel observes A's completion, every response for B is rejected. The panel remains stuck showing A forever.
- **Suggested improvement:** The per-tab request revision already protects against out-of-order local requests. Accept the server attempt returned by the latest request, even when its attempt ID changed.

Location: `app/extension/entrypoints/background.ts:750-752`

### 5. The stalled-job watcher can fail work that is still waiting in the queue

- **Category:** Queue reliability
- **Severity:** Must fix
- **Problem:** `FailStalledSubtitleJobs` applies the execution timeout to both `queued` and `running` corrections.
- **Why it matters:** A correction waiting approximately 22-25 minutes in a busy queue is failed and its encrypted lyrics are deleted before a worker starts it.
- **Suggested improvement:** Use separate queue-wait and running-execution thresholds, or apply this watchdog only to `running` attempts.

Location: `app/backend/app/Console/Commands/FailStalledSubtitleJobs.php:79-86`

## Should Improve

### Keep `syncBackend: false` local

- **Category:** Extension performance
- **Severity:** Should improve
- **Problem:** `syncLyricsCorrection()` still performs a backend status request during `syncBackend: false` when no correction is cached.
- **Suggested improvement:** Return `current` unconditionally when backend synchronization is disabled.
- **Location:** `app/extension/entrypoints/background.ts:738-744`

### Clear panel-only lyrics when the video changes

- **Category:** Privacy / UX
- **Severity:** Should improve
- **Problem:** The panel resets other watch-view state when the active video changes but leaves the pasted lyrics in the textarea.
- **Suggested improvement:** Clear the textarea and rerender its counter when `watchVideoId` changes.
- **Location:** `app/extension/entrypoints/sidepanel/main.ts:563-569`

### Enforce status-state exclusivity in the runtime guard

- **Category:** Contract enforcement
- **Severity:** Should improve
- **Problem:** `guardLyricsCorrectionStatus()` requires positive fields for completed and failed states but does not reject forbidden fields. It accepts `track` on queued/running/failed responses and error fields on queued/running/completed responses.
- **Suggested improvement:** Mirror the canonical schema's mutually exclusive state fields and add guard tests for both invalid shapes.
- **Location:** `app/extension/utils/api-response-guards.ts:115-131`

### Add missing boundary tests

- **Category:** Test coverage
- **Severity:** Should improve
- **Suggested improvement:** Add regressions covering a track above 22 cues, recursive split retries, duplicate delivery, adoption of an externally created attempt, long queue wait, non-sync state with an empty cache, and textarea reset on video change.

## Confirmed Fixed

- TypeScript compilation and corrected panel-state return.
- Transient provider failures transition back to `queued`.
- Section-label filtering no longer broadly discards sung lines.
- Entitlement is rechecked before provider work.
- Unexpected failures are logged without lyrics or prompt contents.
- OpenAPI and status-state schemas are materially stronger.
- Shared WebVTT formatting removes meaningful duplication.
- All derived cue data is rebuilt before atomic publication.

## Ponytail Review

`app/backend/app/Services/Subtitles/LyricsCorrectionService.php:L540-552`: `delete:` cue-count estimator that rejects normal songs without bounding real provider retries. Replace it with the corrected queue execution boundary.

`app/extension/utils/lyrics-correction.ts:L18-20`: `shrink:` one-use equality wrapper plus dedicated two-assert test. Inline the revision comparison.

`app/backend/app/Http/Requests/CorrectSubtitleLyricsRequest.php:L25-32`: `shrink:` duplicate length check after Laravel's `max:25000` string rule. Keep the rule and Unicode content validation.

`packages/contracts/scripts/validate.mjs:L71-115`: `delete:` identical invalid states represented as fixtures and inline objects. Keep one form.

`app/backend/app/Http/Resources/SubtitleTrackLyricsCorrectionResource.php:L18-22`: `yagni:` fallback relation query when both controller paths eagerly supply `track.job`. Read the loaded relation.

`app/backend/app/Services/Subtitles/LyricsCorrectionService.php:L258-273`: `delete:` second paid alignment request following an explicit `lyrics_do_not_match` result. Retry malformed output, not a definitive mismatch.

`net: -45 lines possible.`

## Automated Validation

All automated checks passed:

- Contracts and generated types.
- Backend: 330 tests, 2,486 assertions.
- Extension: 131 tests.
- TypeScript compile.
- Extension production build.
- Feature PHP Pint check.
- Documentation harness.
- `git diff --check main`.
- Full `scripts/agent/check.ps1`.

Manual browser and visual testing were not performed and remain assigned to the product owner.

## Final Recommendation

The implementation is generally readable, and the original first-pass fixes are mostly sound. The timeout/cap remediation is patch-driven: it models a simplified call graph and then changes the plan to match the implementation restriction. Correct the queue boundary, exclusive attempt claim, attempt synchronization, and queued-work expiry before merging.
