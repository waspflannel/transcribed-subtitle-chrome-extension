# Pipeline & Subtitle Display Review — Findings

Date: 2026-07-01. Source prompt: `docs/agent-prompts/pipeline-and-display-review.md`.
Read-only audit of the full pipeline (audio → transcription → normalization → tokenization → romanization/translation/enrichment → track → extension display), focused on the reported symptom: *words leak onto the next line/cue, translation drifts out of sync, self-corrects eventually*.

**Root-cause verdict:** data-rooted. Cue segmentation in `ScribeTranscriptNormalizer` breaks no-space-script (CJK/Thai/etc.) cues mid-word because it counts raw provider words — which Scribe emits per-character for those scripts — against `MAX_CUE_WORDS = 14`. The tokenizer cannot repair a word already split across two cues (token text must be a substring of its own cue's `sourceText`), so translation desyncs until the next sentence punctuation or ≥0.9s pause realigns cue boundaries ("self-corrects"). Two smaller layout contributors add to the perception: mid-token visual wrapping and the rail blanking between cues.

Fixes below are the *proper* fix for each finding, not the narrowest patch. Ordered by impact.

---

## Things to fix

### 1. Rework cue segmentation so breaks land on real boundaries (blocker — the root cause)
- **File:** `app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php` — `segmentsFromWords` (158-187), `startsNewCue` (193-202), `cueCharacterCount` (255-261)
- **Problem:** the segmenter is greedy: when a hard limit trips (`>6s`, `>84 chars`, `>=14 words`), it breaks *at the current word*, wherever that is. For no-space scripts, Scribe words are single characters, so the 14-word limit fires after ~14 characters and the break lands mid-word (e.g., 友達 split into `…友` / `達…`). On top of that, the char limit is measured on text still containing provider artifact spaces, while the stored cue text is stripped — so it fires roughly twice as early for CJK.
- **Fix (proper):** replace "break at current position when a limit trips" with **windowed best-boundary selection**:
  - While accumulating words, track candidate break positions: after a word ending in sentence/clause punctuation, and after any inter-word gap above a soft threshold (~0.25s). Larger gap = better candidate.
  - When a hard limit trips, flush the cue at the *best candidate seen in the window* and carry the remainder into the next cue. Only break at the current position if the window contains no candidate at all.
  - Make counting script-aware: measure the char limit on artifact-stripped text (`normalizeTranscriptText`), and stop counting single-character no-space-script words as full "words" — for those runs the char limit and pauses govern, not word count. Reuse the `NoSpaceArtifactBoundary` character classes for the script check so segmentation and validation share one definition.
  - This fixes mid-word breaks for *all* languages, not just CJK — spaced-language breaks also snap to pauses/punctuation instead of an arbitrary 14th word.
- **Preserves:** cue timing monotonicity, downstream tokenizer/validator contract untouched (they already share `NoSpaceArtifactBoundary`).
- **Validation:** unit tests feeding per-character Japanese/Thai word streams and spaced-language streams; assert breaks land only on pause/punctuation candidates, never inside a dictionary word; assert char counting matches stripped length.
- **Note:** cue counts per job change → AI batch counts and per-batch cost estimates shift (`SubtitleCueBatchProcessor.php:137`). Not a billing-contract change, but flag it in the PR.

### 2. RTL and script handling driven by the language catalog (major — also fixes RTL display)
- **Files:** `packages/contracts/languages.json` (no script metadata today), `app/backend/app/Services/Text/NoSpaceArtifactBoundary.php:21` (hard-coded ranges), `app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php:399-413` (`shouldRomanizeTranscript`), `app/extension/utils/overlay/overlay-render.ts:53-55` (`.token-area`), `:188` (`.translation`)
- **Problem:** two symptoms of one gap. (a) The overlay sets `lang` but never `dir`, so Arabic/Hebrew tokens lay out in LTR order — an independent "off track" mechanism for RTL languages. (b) Every script-sensitive behavior (no-space artifact stripping, romanization eligibility) is a hard-coded PHP regex, so language handling can drift and adding a language means editing code.
- **Fix (proper):** add per-language script metadata to `languages.json` (e.g., `script`, `noSpace`, `rtl` flags). Consume it in three places:
  - Backend: derive `NoSpaceArtifactBoundary`'s character classes and `shouldRomanizeTranscript` from the catalog instead of hard-coded ranges.
  - Contracts/extension: expose direction (either on `TrackResponse` or via a generated TS language table) and set `dir` on `.token-area` and `.translation` from it. Flex row order follows `dir` automatically. `dir="auto"` is the acceptable stopgap if you want the display fix landed before the catalog work, but the catalog is the end state.
- **Validation:** contracts `npm run check`; unit test that catalog-derived classes match the current regex for existing languages (no behavior change for shipped languages); extension snapshot test with an Arabic cue asserting `dir="rtl"`.

### 3. Tokens never break mid-word visually (major for perception, small change)
- **File:** `app/extension/utils/overlay/overlay-styles.ts:279` (`.token-text`), `:234-256` (`.token-card`)
- **Problem:** `.token-text { overflow-wrap: anywhere }` lets a single token wrap mid-word inside its card whenever the card exceeds the rail width — visually identical to the data bug, so it must go too or fix 1 won't be *seen* to work.
- **Fix (proper):** make token text unbreakable: `overflow-wrap: normal` plus `word-break: keep-all` (the latter also stops browsers breaking CJK strings anywhere). Add `max-width: 100%` to `.token-card` so a pathological token constrains to the rail instead of blowing out layout. Keep `overflow-wrap: anywhere` on `.translation` and `.field-value` — free-flowing text should wrap. With fix 1 keeping tokens short, oversized tokens become rare enough that constrained-width is the right behavior, not ellipsis (learners need the full word).
- **Validation:** `npm test` snapshots + manual resize with a long Thai token and a long German compound.

### 4. Keep the rail visible through inter-cue gaps (major for perception)
- **Files:** `app/extension/entrypoints/content.ts` (`onCueChange`, ~line 324), display gate at `app/extension/utils/overlay/overlay-render.ts:30-32`, source of nulls at `app/extension/utils/webvtt-track.ts:35-39`
- **Problem:** every silence ≥ the cue gap empties `activeCues` → `activeCue: null` → the entire rail unmounts, then remounts on the next cue. Reads as flicker and as "it fixed itself."
- **Fix (proper):** sticky-cue behavior in the content script: when `onCueChange` reports null while the video is playing, keep the previous cue rendered until either the next cue starts or a hold timeout expires (~1.5–2s, comfortably above the 0.9s `PAUSE_BREAK` so normal pauses never blank the rail). Clear immediately on seek, video change, or track teardown (`clearBoundWebVttTrack` already handles the teardown path). Optionally render the held state slightly dimmed so it reads as "previous line" rather than active.
- **Validation:** extension unit test around the hold logic; manual playback across cue gaps and seeks.

### 5. Match active VTT cue by id, not timestamp proximity (minor)
- **File:** `app/extension/utils/webvtt-track.ts:145-152` (`findTrackCue`)
- **Problem:** matching by ±25ms tolerance and taking the first hit can map to the wrong cue when adjacent cues are within tolerance.
- **Fix (proper):** the server already writes stable VTT cue identifiers (`cue-%04d`, `ScribeTranscriptNormalizer.php:145`) that equal `SubtitleCue.cueId`. Browsers expose them as `VTTCue.id`. Match on id; keep the timestamp match only as a fallback for tracks generated before ids existed (or drop the fallback if all tracks have ids — check a stored track first).
- **Validation:** unit test with two cues 20ms apart; assert id-based mapping.

### 6. Timing offset must preserve cue ordering (minor)
- **File:** `app/extension/utils/webvtt-track.ts:68-89` (`offsetTrackTiming`), `:116-118` (`shiftedMilliseconds`)
- **Problem:** per-cue `Math.max(0, …)` clamping collapses multiple early cues onto `startMs 0` under a negative offset, creating overlaps that break the `activeCues[0]` assumption and the id/timestamp mapping.
- **Fix (proper):** treat offsetting as a track-level transform: drop cues whose shifted `endMs <= 0` entirely, clamp only the first partially-visible cue, and assert/repair strictly non-decreasing starts afterwards. Apply the same rule to the WebVTT text and the `cues` array so they can't disagree.
- **Validation:** unit test with offset −30s: early cues dropped, remainder disjoint and ordered, VTT and cue array consistent.

### 7. Stamp batch artifacts with `run_id` (minor — closes a stale-write race)
- **Files:** `app/backend/app/Services/Subtitles/SubtitleCueBatchProcessor.php:176-192`, `app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php:247-292`, migration for `subtitle_job_artifacts`
- **Problem:** check-then-write race: a stale batch passes `loadRunningJob`, the job is reset (artifacts deleted, new `run_id`), then the stale batch writes its artifact anyway. Artifacts carry no `run_id`, so the new run can assemble mixed-run cues.
- **Fix (proper):** add `run_id` to `subtitle_job_artifacts`; `put()` writes it; every read (`payload`, `cueResultFromBatchArtifacts`, `batchArtifactCount`) filters by the current job `run_id`. Include `run_id` in the artifact unique key so a stale write can never be confused for current-run data even if it lands. Reset/failure cleanup can then also be scoped by run, which makes the delete-then-write ordering irrelevant.
- **Validation:** test that writes a batch result with an old `run_id` after reset and asserts the new run's reads exclude it.

### 8. Watchdog for jobs stuck at `running` (minor — reliability gap)
- **Files:** new scheduled command (the only sweeper today is `app/backend/app/Console/Commands/PruneExpiredSubtitleTracks.php`); reuse `app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php`
- **Problem:** if a Laravel batch record is lost/cancelled without the `catch`/`failed` callbacks firing, nothing ever fails the job row — it sits at `running` forever and holds a generation-concurrency slot.
- **Fix (proper):** scheduled command (`subtitles:fail-stalled-jobs`, every minute or five) with a per-stage timeout map in `config/subtitles.php` (stage timeouts already implicitly exist via job `$timeout`s — make them explicit config). For each `running` job whose `updated_at` exceeds its stage timeout plus slack, call `SubtitleJobFailureHandler::failJob` with the job's current `run_id` and a distinct error code (`stalled_timeout`) so it's distinguishable in traces and releases the minute reservation through the existing path.
- **Validation:** feature test: job frozen mid-stage, command run, job failed + reservation released + trace event recorded.

### 9. Per-cue fallback instead of failing the whole generation on one bad cue (behavior change — flag it)
- **File:** `app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php:56-80` (split-retry), `:241-259` (`shouldRetryTokenizationBatch`)
- **Problem:** split-retry stops at `cueCount <= 1`; a single pathological cue fails the entire job (documented in `ARCHITECTURE.md:28`, so changing this is a documented-behavior change — update that line in the same PR).
- **Fix (proper):** for a single cue that still fails validation: retry it a bounded number of times (the model is nondeterministic; one re-prompt often clears it), then fall back to deterministic tokenization for that one cue — whitespace-split for spaced scripts, grapheme-cluster grouping for no-space scripts — and record a trace event marking the cue as fallback-tokenized. A slightly coarse token boundary on one cue is strictly better UX than a failed generation the user paid minutes for.
- **Validation:** unit test: agent returns garbage for exactly one cue → job completes, cue carries fallback tokens, trace event present.

### 10. Enrichment: drop the translation echo and add split-retry parity (minor)
- **File:** `app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php:472-524` (`validatedEnrichedCueResult`), `app/backend/app/Ai/Agents/CueEnrichmentAgent.php`
- **Problem:** full-mode enrichment fails the job when the model doesn't echo `translatedText` byte-exactly — validating an echo of data the server already holds. Enrichment also lacks the split-retry that tokenization has, so one bad batch response is fatal after transient retries.
- **Fix (proper):** stop asking the model for `translatedText` back (remove from the agent schema/expectations); the server copies `sourceTranslatedText` unconditionally — same guarantee ("enrichment cannot change cue translation"), enforced structurally instead of by comparison. Give enrichment the same split-and-retry treatment as tokenization for validation failures on multi-cue batches; for a single cue, degrade to the un-enriched token (tokens already work without card metadata — on-click enrichment fills them later) rather than failing the job.
- **Validation:** unit tests: paraphrased translation → job completes with source translation; one bad cue in a batch → batch splits, bad cue degrades, job completes.

### 11. Half-open cue intervals (nit)
- **File:** `app/extension/utils/cue-navigation.ts:16` (and the same comparison at `:73`)
- **Problem:** `>= startMs && <= endMs` is inclusive on both ends; draft cues allow `start == previousEnd` (`TimestampedSubtitleTrackGenerator.php:148`), so at the shared millisecond the *previous* cue wins.
- **Fix:** half-open interval (`>= startMs && < endMs`) in both places.
- **Validation:** unit test at the exact boundary millisecond.

### 12. One owner for text normalization (nit — drift prevention)
- **Files:** `ScribeTranscriptNormalizer.php:276-284`, `TimestampedSubtitleTrackGenerator.php:157-160` (+ redundant re-normalization at `:43`), `LaravelAiTranslationAnalysisProvider.php:852-861`, `LearningTokenOutputValidator.php:118-123`
- **Problem:** four copies of `trim(preg_replace('/\s+/u', ' ', …))`; the validator's variant adds `NoSpaceArtifactBoundary::strip`, the provider's doesn't — exactly the silent-drift risk the `NoSpaceArtifactBoundary` docblock warns about.
- **Fix (proper):** promote a single text service (extend `NoSpaceArtifactBoundary` or a sibling in `App\Services\Text`) exposing the two canonical operations — whitespace-collapse, and collapse+strip ("canonical comparable text"). Replace all four call sites; delete the redundant re-normalization in `draftCues`. Pin producer/comparer equivalence with a dedicated test so future drift fails CI.
- **Validation:** existing suites + the new equivalence test.

### 13. One derivation for batch counts (nit)
- **Files:** `app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php:144-154` (`batchCount`) vs `:156-168` (`batchArtifactCount`); consumers at `SubtitleGenerationPipeline.php:240` and `:260`
- **Problem:** analysis dispatch derives batch count from ceil(cues/batchSize); romanization dispatch counts tokenized artifact rows. They agree only through the implicit invariant that tokenized batch indexes mirror draft batch indexes.
- **Fix (proper):** make the batch plan explicit: the draft-cue artifact already stores `batchSize` — treat `batchCount(DRAFT_CUES)` as the single source of truth for every downstream stage's batch indexing, and delete `batchArtifactCount` (fix 7's run-scoped reads remove its only advantage, tolerance of stray rows).
- **Validation:** existing pipeline feature tests; add one asserting romanization dispatch count equals analysis dispatch count.

---

## Open questions

Things that could not be determined from code alone; each names the log/artifact needed.

1. **How often does Scribe emit per-character words, and for which languages?** Fix-1 tuning depends on this. Needed: a raw `transcript` artifact (or Scribe payload) for one Japanese, one Thai, one Korean job — specifically the distribution of `words[].text` lengths and inter-word gap sizes.
2. **Confirmation of mid-word cue splits in production.** Needed: a `draft_cues` artifact for a job in an affected language checked against a segmenter — or add a trace event in `segmentsFromWords` recording which limit (`pause|duration|chars|words`) triggered each break (trace-event infrastructure per `ARCHITECTURE.md:25,76`). Worth adding regardless: it makes fix 1 measurable before/after.
3. **Which languages the user saw the symptom in.** If Arabic/Hebrew are included, the missing `dir` handling (fix 2) is a second, independent cause.
4. **Has any job ever stuck at `running`?** Query `subtitle_jobs` for `status='running'` with `updated_at` older than stage timeouts to confirm or dismiss fix 8's urgency (the gap is real either way).
5. **Does Scribe ever leave ≥0.9s gaps *inside* a logical word** (stretched speech, song)? If so, the pause break at `ScribeTranscriptNormalizer.php:195` also splits mid-word; only real timing data can confirm, and it would inform fix 1's soft-gap threshold.
