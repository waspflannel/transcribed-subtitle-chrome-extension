# Ponytail application review — 2026-09-12

Reviewed commit: `dcf6dc29fafbfcf4345025388167539bfea8aca4`.

The application has useful foundations: transcript reuse, progressive subtitles, bounded provider work, combined analysis calls, and a small dependency set. The worthwhile cuts are duplicate generated code, unused font payloads, stale state, and repeated work. A broad rewrite or dependency purge would not help.

The highest-value fixes are the stale lyrics snapshot, saved-track recovery, transient transcription retries, duplicate card work, and misleading quality scores. Packaging cleanup can remove **274,980 bytes, approximately 31.3% of the current uncompressed extension**. At least **813 lines** can be removed from duplicate declarations and two confirmed obsolete code paths; most are generated types, not runtime code.

This is a review document. No application fixes or dependency changes were applied. P2 means a concrete issue worth fixing in the next maintenance pass; P3 means smaller cleanup or optimization. Observed code behavior is distinguished from unmeasured performance opportunities below.

Follow-up implementation and resumed-session evidence are tracked in the [implementation plan](exec-plans/active/2026-09-12-implement-ponytail-application-review.md). The findings and baseline measurements below describe the reviewed commit, before those fixes.

## Ponytail: things to remove or simplify

Ranked primarily by the size of the cut. These recommendations preserve current functionality.

- **C1 · P3 · `shrink:` Stop emitting duplicate contract declarations.** [generate-types.mjs:38](C:/transcribed-subtitle-extension/packages/contracts/scripts/generate-types.mjs:38) compiles each root schema with its referenced declarations, then concatenates the results. The generated file contains 36 declarations but only 21 unique declarations: **804 exact duplicate lines**, including two extra copies of the 298-line `TrackResponse`. Use the installed compiler's `declareExternallyReferenced` option to avoid re-emitting separately generated types, while retaining the history and partial-track schemas' local `$defs`. An in-memory generation experiment produced 21 declarations identical to the original unique declarations. Keep the generation/compile check. This is a maintenance improvement; these declarations are not browser JavaScript.

- **C2 · P3 · `delete:` Remove redundant font formats and the unused Geist 500 face.** [sidepanel/style.css:1](C:/transcribed-subtitle-extension/app/extension/entrypoints/sidepanel/style.css:1) imports Fontsource CSS that bundles both WOFF and WOFF2. The production build contains **13 WOFF files totaling 240,264 bytes**, each with a WOFF2 counterpart. [overlay.ts:11](C:/transcribed-subtitle-extension/app/extension/utils/overlay.ts:11) additionally loads the **34,716-byte** Geist Sans 500 face, while overlay rules using weight 500 select IBM Plex Mono. Use WOFF2-only declarations, preserve multilingual subsets and used weights, and remove the unused face. Combined saving: **274,980 raw bytes** from an **879,754-byte** build. Rebuild and visually check the panel and overlay. This reduces package size; it does not shorten AI calls or imply every redundant font is downloaded during use.

- **C3 · P3 · `shrink:` Inline the one-caller enrichment callback framework.** [SubtitleCueBatchProcessor.php:89](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitleCueBatchProcessor.php:89) is the only caller of `runCueBatch`; its stage and artifact type are always `enriching` and `ENRICHED_CUES`. Put that flow directly in `enrichCueBatch`, removing the callback, invariant parameters, forwarding method, and one-use storage wrapper. Approximately **20–35 lines** could disappear. Preserve retries, telemetry, run checks and atomicity, and address F4 below in the same change.

- **C4 · P3 · `delete:` Remove obsolete overlay and history state.** [overlay.ts:338](C:/transcribed-subtitle-extension/app/extension/utils/overlay.ts:338) retains input-selection capture/restoration although editing moved to the side panel; retain ordinary focus restoration for accessibility. [overlay/types.ts:35](C:/transcribed-subtitle-extension/app/extension/utils/overlay/types.ts:35) exports an unreferenced `OverlayTokenClickHandler`. [account-state.ts:44](C:/transcribed-subtitle-extension/app/extension/utils/account-state.ts:44) produces `publicJobId` and maintains its formatter even though History never reads it. Approximately **20–25 source lines** and the obsolete field assertion can go. Keep the behavior tests that still cover real functionality.

- **C5 · P2 · `delete:` Stop loading full tracks for the dashboard list.** [DashboardController.php:74](C:/transcribed-subtitle-extension/app/backend/app/Http/Controllers/DashboardController.php:74) eager-loads `track`, including `cues` and `web_vtt`, for eight recent jobs. The mapping and language-label helper use only job properties. Delete `->with('track')`: **one line, one query, and up to eight unused track payloads per dashboard request**. The separate job-detail page still needs its track data.

- **C6 · P3 · `delete:` Retire two unused billing model fields during adjacent schema work.** [User.php:34](C:/transcribed-subtitle-extension/app/backend/app/Models/User.php:34) and its casts retain `billing_trial_ends_at` and `billing_ends_at`; references are confined to model configuration, migrations and schema docs. Neither is populated or consumed by current application behavior. Remove four model-configuration lines and retire the columns with a forward migration when touching this area. This is low priority and offers no meaningful generation-speed gain.

## Functional, generation and quality findings

### F1 · P2 — A completed lyrics snapshot can erase newly loaded word-card metadata locally

**Evidence:** [background.ts:2040](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:2040) republishes `status.track` whenever a cached correction is completed. [lyrics-correction.ts:119](C:/transcribed-subtitle-extension/app/extension/utils/lyrics-correction.ts:119) returns that cached snapshot for `syncBackend:false`. Loading a word card updates the active and remembered track at [background.ts:880](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:880), but does not update the correction snapshot.

**Trigger and impact:** Complete a lyrics replacement, load a word card, then change a Study setting. The local panel refresh can publish the older completed snapshot and remove the new card metadata locally. It also rewrites the full track cache and updates the overlay unnecessarily. This does not establish loss of the backend's stored card.

**Smallest fix:** Publish a completed correction once per newly accepted result. Do not replay cached terminal snapshots during local refreshes. Preserve session and track identity checks. Verify the sequence above through the background entrypoint with a delayed response. The path is traced; it was not reproduced in a live browser.

### F2 · P2 — Older saved tracks become undiscoverable after leaving two small caches

**Evidence:** Content and panel recovery use the global history path at [background.ts:309](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:309) and [background.ts:1598](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:1598). Its backend query is capped at **25 jobs** at [SubtitleJobController.php:62](C:/transcribed-subtitle-extension/app/backend/app/Http/Controllers/Api/SubtitleJobController.php:62). The local remembered-track cache holds **five** tracks. The saved-generation picker loads only after a ready track exists: [saved-generations.ts:88](C:/transcribed-subtitle-extension/app/extension/entrypoints/sidepanel/saved-generations.ts:88).

**Impact:** Reopening an older, unexpired video's track can show no generated track, even though the backend still has it. The user may regenerate work unnecessarily.

**Smallest fix:** On a local miss, use the existing video-filtered completed-history API, already called at [background.ts:185](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:185). Keep global history for the History UI and active jobs. Verify recovery with more than 25 jobs and the desired video evicted from the local five-track cache.

### F3 · P2 — One transient Scribe failure can discard the entire generation run

**Evidence:** [ElevenLabsScribeTranscriptionService.php:193](C:/transcribed-subtitle-extension/app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php:193) converts unsuccessful responses, including temporary 429/5xx responses, to generic transcription failure. [TranscribeSubtitleAudioChunk.php:37](C:/transcribed-subtitle-extension/app/backend/app/Jobs/TranscribeSubtitleAudioChunk.php:37) allows one exception. Terminal failure removes completed artifacts and the audio workspace through [SubtitleJobFailureHandler.php:81](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php:81).

**Impact:** A temporary error on one chunk can lose already completed transcription and analysis. Retrying generation repeats acquisition and transcription because the transcript cache is only populated after a successful merge. This is already identified as unfinished work in the September 9 pipeline plan and remains present.

**Smallest fix:** Classify transient responses/connections and retry the affected chunk with bounded backoff. Preserve completed chunk artifacts; fail permanent input/auth/quota errors and exhausted retries visibly. Test a 503-then-success chunk alongside already completed chunks, plus terminal cleanup. No provider failure rate or latency saving was measured here.

### F4 · P2 — Full word-card batches can repeat paid requests on queue redelivery

**Evidence:** [SubtitleCueBatchProcessor.php:119](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitleCueBatchProcessor.php:119) checks that the run is active, then calls enrichment without checking for its existing `ENRICHED_CUES` artifact. [EnrichSubtitleCueBatch.php:7](C:/transcribed-subtitle-extension/app/backend/app/Jobs/EnrichSubtitleCueBatch.php:7) lacks the per-batch overlap guard already used by analysis and transcription.

**Trigger and impact:** If a worker saves a result but dies before acknowledging the queue message, redelivery can call the provider again while sibling batches remain active. Concurrent duplicate delivery can do the same. A failed repeat request can fail a run that already had a valid result.

**Smallest fix:** Reuse the existing analysis pattern: per-run/per-batch overlap protection, an existing-artifact early return, and atomic result/cost recording. Test redelivery and simultaneous duplicates while another batch remains pending. The missing protection is confirmed; production duplication frequency is unknown.

### F5 · P2 — The quality evaluator gives invented extra tokens a perfect score

**Evidence:** [TokenizationBoundaryMetric.php:47](C:/transcribed-subtitle-extension/app/backend/app/Services/TranslationAnalysis/TokenizationBoundaryMetric.php:47) drops predicted tokens that cannot be located in the source. Line 79 then counts only the remaining tokens in the word-precision denominator. Both evaluation commands consume this metric.

An offline reproduction returned:

| Source and gold | Predicted tokens | Word precision / recall / F1 | Boundary F1 |
| --- | --- | --- | --- |
| `hello world` | `hello`, `world` | 1 / 1 / 1 | 1 |
| `hello world` | `hello`, `world`, `invented` | 1 / 1 / 1 | 1 |

**Impact:** Prompt/model comparisons can score an invented-word response as perfect. This undermines decisions about generation quality.

**Smallest fix:** Count every predicted lexical token in word precision and report unlocatable predictions separately. Do not interpret boundary F1 alone as text fidelity. Update the class comment that still claims production enforces substring matching. Preserve the intentional production policy accepting model wording. Add extra-token, entirely invented-token and out-of-order cases.

### F6 · P2 · `delete:` — Language synchronization silently weakens a contract

**Evidence:** [sync-language-schemas.mjs:43](C:/transcribed-subtitle-extension/packages/contracts/scripts/sync-language-schemas.mjs:43) replaces the history item's required fields with an obsolete six-field list. This removes requirements for `jobId`, provider/model, stage/progress, last update, enrichment mode, and translation/romanization flags.

An in-memory AJV check rejected a running history item missing those nine fields under the current schema, then accepted it after the sync transformation. The normal contract check does not run this separate maintenance command.

**Smallest fix:** Delete the **eight-line `item.required` assignment**. The language sync should change language properties only. Verify that synchronization preserves all unrelated schema requirements.

### F7 · P2 — Failed and cancelled jobs have no automatic expiry

**Evidence:** New/reset runs have no expiry. Neither [SubtitleJobFailureHandler.php:78](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php:78) nor cancellation at [SubtitleJobService.php:287](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitleJobService.php:287) assigns one. [PruneExpiredSubtitleTracks.php:32](C:/transcribed-subtitle-extension/app/backend/app/Console/Commands/PruneExpiredSubtitleTracks.php:32) only deletes jobs whose `expires_at` has elapsed. History hides terminal jobs older than 30 days, so those rows can become invisible while remaining stored with their trace events.

**Reproduction:** Two 45-day-old failed/cancelled jobs and two associated events survived `subtitles:prune-expired` in an isolated in-memory database.

**Smallest fix:** Give terminal jobs an explicit retention policy, with a backfill for existing null-expiry rows. A 30-day diagnostic window would match the current History visibility window. Preserve billing ledger records and active runs. Also define retention for completed/failed queue metadata: the checked-in [scheduler](C:/transcribed-subtitle-extension/app/backend/routes/console.php:5) does not schedule pruning of `job_batches` or `failed_jobs`; an external production schedule was not inspected. Test old and recent terminal jobs, active jobs, trace cleanup and retained billing entries.

### F8 · P2 — Panel refreshes duplicate the generation monitor's polling

**Evidence:** [background.ts:1629](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:1629) fetches an active generation even when `syncBackend:false` and its per-tab monitor already polls it at [background.ts:768](C:/transcribed-subtitle-extension/app/extension/entrypoints/background.ts:768). The open side panel polls every ten seconds during generation; settings changes also request a local panel snapshot.

**Impact:** An active open panel adds approximately six redundant status requests per minute, plus local interactions. Local settings responses can wait for the status request's four-second timeout. This amplifies F10's server-side work.

**Smallest fix:** Let an active monitor own current generation state. Fetch for recovery when the monitor is absent or has restarted, and honor local-only refreshes. Preserve persisted-operation recovery and ownership guards. Count requests in a one-minute active generation test and verify worker-restart recovery separately.

### F9 · P2 · `shrink:` — Account balance summaries make four queries where one suffices

**Evidence:** [UsageLedger.php:200](C:/transcribed-subtitle-extension/app/backend/app/Services/Billing/UsageLedger.php:200) issues four sums over the same account and period. `granted` has no production reader; `availableMinutes()` requests all four even when only available balance is needed. These calls occur during generation admission while the user's row is locked, as well as account and dashboard requests.

**Smallest fix:** Fetch the required sums in one aggregate and remove the unused `granted` result. **Four queries become one per summary call.** Preserve ledger history, reservations, idempotency and atomic admission. Run the existing billing tests and verify the aggregate query count. The reduction is established from code; a production latency gain was not benchmarked.

### F10 · P2 — Every progress request rebuilds the full partial track from token-heavy artifacts

**Evidence:** [SubtitleJobResource.php:72](C:/transcribed-subtitle-extension/app/backend/app/Http/Resources/SubtitleJobResource.php:72) assembles a partial track on each running-job response. [SubtitlePartialTrackAssembler.php:14](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitlePartialTrackAssembler.php:14) loads the draft and every analyzed artifact, decodes their complete payloads, discards token details, and returns all preview cues again. The response already has a revision, but unchanged polling still performs this work.

**Synthetic measurement:** For 500 cues and 6,000 tokens, one call loaded 51 artifact rows containing **487,895 JSON bytes** to produce a **97,663-byte** preview. Seven unchanged calls repeated the query and assembly seven times. Median assembly was **5.42 ms in local in-memory SQLite**, excluding real Postgres/network/HTTP costs. This demonstrates repeated work, not production capacity or a predicted speedup.

**Smallest fix:** Address duplicate requests in F8 first. Then make unchanged progress responses cheap using an explicit run/progress revision and reuse or materialize the lightweight preview when artifacts change. Keep revision updates atomic and run-scoped; do not key solely on `updated_at`, since it need not change for every artifact. Measure DB bytes, response bytes and polling latency on long runs before choosing a broader storage redesign.

### F11 · P2 — Marketing promises a saved-word review feature that does not exist

**Evidence:** [home.blade.php:78](C:/transcribed-subtitle-extension/app/backend/resources/views/marketing/home.blade.php:78) says word cards are saved to the account for review. [Product scope](C:/transcribed-subtitle-extension/docs/product-specs/index.md:63) defers vocabulary review and saved learning items; current functionality is card lookup and generated-track history.

**Smallest fix:** Describe those implemented features accurately. Do not build a vocabulary system to justify the sentence. Check the remaining marketing copy against current product behavior.

## Speed and display improvements that need profiling

These are supported by code inspection, but their user-visible impact was not measured. Do not claim percentage speedups or introduce substantial machinery before collecting evidence.

- **E1 — Start the opening Scribe request before preparing every later chunk.** [ScribeAudioChunker.php:80](C:/transcribed-subtitle-extension/app/backend/app/Services/Audio/ScribeAudioChunker.php:80) runs chunk preparation sequentially. [SubtitleGenerationPipeline.php:195](C:/transcribed-subtitle-extension/app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php:195) waits for the whole plan's files before dispatching transcription. Measure preparation time versus first-cue time on long uploads. If material, prepare and dispatch the opening chunk while bounded later preparation continues. Preserve chunk overlap, completion accounting, cancellation, cleanup and the documented WebM codec-delay restriction. Compare M4A/WebM fixtures for first cue, first annotation, total duration, peak CPU and unchanged timing/text.

- **E2 — Coalesce overlay positioning work during scroll and resize.** [content.ts:105](C:/transcribed-subtitle-extension/app/extension/entrypoints/content.ts:105) runs player recovery on every captured scroll and on a one-second interval. It scans video elements, then [overlay.ts:85](C:/transcribed-subtitle-extension/app/extension/utils/overlay.ts:85) scans again, reads geometry, writes styles, builds render markup and constrains popovers. Some work also runs with the overlay hidden. Use one animation-frame update, reuse the resolved player/geometry, and separate positioning from content rendering. Preserve player replacement recovery. Profile scrolling while playing, with the overlay shown and hidden.

- **E3 — Avoid serializing and rebuilding the transcript on every search keystroke.** [transcript-view.ts:40](C:/transcribed-subtitle-extension/app/extension/entrypoints/sidepanel/transcript-view.ts:40) serializes all cues and tokens to compute a render signature. Each search event then rebuilds searchable text and the matching list. Cache searchable text when cue content changes and coalesce input rendering. Any revision scheme must include word-card patches and Quick Fix, which may change cue content independently of track identity. Measure a long transcript before adding virtualization.

- **E4 — Remove the website's stylesheet import chain.** [site.css:1](C:/transcribed-subtitle-extension/app/backend/public/css/site.css:1) contains eight imports. [site.blade.php:10](C:/transcribed-subtitle-extension/app/backend/resources/views/layouts/site.blade.php:10) versions only the parent URL using file timestamps; child URLs stay unchanged. Ordered stylesheet links with each file's own version would expose all CSS resources immediately and make invalidation explicit, without a new frontend build dependency. Confirm with a cold-load waterfall and a cached child-stylesheet update. Existing server cache headers may already mitigate staleness; they were not inspected.

- **E5 — Check native subtitle reloads for visible gaps during partial updates.** [content.ts:436](C:/transcribed-subtitle-extension/app/extension/entrypoints/content.ts:436) tears down/reloads the native WebVTT track when the partial revision changes, including annotation-only updates. A new blob load clears the active cue temporarily; current mocks deliver cue callbacks immediately. Record a real browser run before treating flicker as confirmed. If it occurs, keep the timing track for annotation-only changes and update rendering separately; consider append-only timed cues only when needed.

## Keep these parts

- Transcript caching keyed by relevant transcription options, and owner scoping for vocabulary hints.
- Progressive cue publication, fixed cue identities/timings, and completeness checks before final publication.
- One analysis call combining tokenization and requested translation/readings, with shared neighboring context.
- Bounded provider concurrency, run fencing, ownership checks, serialized local mutations and atomic billing.
- Provider-output validation, temporary-audio cleanup, native timed tracks, keyboard/focus behavior, and meaningful tests.

No backend or contract dependency was shown to be unnecessary. The three extension runtime font packages have real uses; removing redundant formats does not require replacing those packages. No evidence here supports switching models, adding a second transcription pass, or rewriting the pipeline. Use matched reference audio and a bounded evaluation plan before making those choices.

## Validation and limits

The full `.\scripts\agent\check.ps1` run passed:

| Check | Result |
| --- | --- |
| Documentation harness | Passed |
| Contract validation and generated types | Passed |
| Backend | 531 tests passed, 4,199 assertions |
| Extension | 242 tests passed across 32 files |
| TypeScript compile | Passed |
| Production Chrome extension build | Passed; 43 files, 879,754 raw bytes |

Additional offline checks covered duplicate declarations, schema weakening, the invented-token score, terminal retention, partial-preview assembly, and packaged font bytes. Database probes used isolated in-memory SQLite with stray HTTP requests blocked. No production database, provider-backed generation, paid AI evaluation, browser performance recording, or live deployment was exercised. Existing passing tests therefore do not close the new cases listed above.

Recommended order: fix F1/F2/F5/F6, then F3/F4/F7; remove F8/F9/C5's repeated work; apply the low-risk generated-code/asset cleanup; use traces to prioritize F10 and E1–E5. Keep each change narrow and validate its actual behavior.

The conservative line count below includes only 804 duplicate generated lines, eight obsolete language-sync lines and one unused eager-load line. Other cleanup estimates are additional; fixes may also require new code. It is deletion potential, not a promised final patch size.

net: -813 lines, -0 deps possible.
