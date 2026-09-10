# Pipeline speed, quality and reliability

Branch context: selected implementation below is on `codex/subtitle-pipeline-quality`, based on main `b454e99`. Historical records below predate this implementation; [the index explains their application revisions](00-index.md#cleanup-branch-preservation).

Created: 2026-09-09
Last updated: 2026-09-10

Use the [index](00-index.md) for shared execution rules, ownership and the old-number map. Historical package numbers below identify audit evidence; they are sections of these consolidated documents, not separate work plans. This consolidation does not authorize new implementation or experiments.

This is the single backlog for generation from source acquisition through audio preparation, transcription, learning analysis, romanization, caching and browser delivery. It combines former 02, 03, 04, 06, 07, 08, 09, 10 and 11, plus the current slowdown investigation.

## Selected implementation: audit items 1–7

Status: selected code implemented and validated locally — 2026-09-10; live comparisons deferred
Owner: current agent

The user selected boundary reconciliation (1), language-aware and honest fallback output (2), run-scoped timing and actual queue publication timing (3), direct YouTube ingestion (5), retained transcription quality signals and improved language selection (6), and optional vocabulary hints (7). The user then added combined analysis and romanization (4), explicitly excluding lower-reasoning experiments. The initial delivery retained medium reasoning. The later user-requested switch to low is recorded below.

Delivery order:

1. Correct metrics and their regression tests; pin test-only fast-mode configuration so the suite is independent of local settings.
2. Review preserved boundary commit `07682be`, recover applicable code/tests, and repair language-aware fallback and missing-translation representation through the contracts and extension.
3. Combine analysis and requested romanization in one validated response. Keep deterministic transliteration local and preserve source/token identity, same-language and translation-disabled behavior.
4. Carry allowlisted word log probabilities and language confidence through transcription, select automatic language from meaningful evidence across chunks, and expose sanitized quality diagnostics. Selective retranscription requires calibrated reference evidence before activation.
5. Add optional user vocabulary hints from extension through validated requests, stored jobs, provider requests and cache identity. Never log the hints or reuse a transcript generated with different hints.
6. Add explicitly selected direct YouTube URL ingestion with existing public/non-live/duration admission checks and distinct cache identity. Preserve local chunked ingestion until a bounded real-video comparison establishes suitable defaults.

Acceptance: focused regressions, contract checks, complete agent check, source-fidelity review, reasoning settings matching the current user instruction, no raw content in diagnostics, and documented cache invalidation and remaining live-evaluation evidence. No provider-backed experiments, deployment or runtime reconfiguration are started by this implementation. Paid comparisons need a concrete bounded sample before their final approval step.

Baseline: current main `b454e99` reproduces duplicate/missing boundary words and single-word English fallback splitting into letters. An isolated two-run metrics probe mixes old timings with current costs. Full baseline check passed with test-process `OPENAI_FAST_MODE_ENABLED=true`: 452 backend tests, 224 extension tests, contracts, TypeScript and build. Without that test setting, two preexisting instruction tests assume the local fast-mode toggle is true.

Skills: phased-implementation-v2, ponytail, code-review, Laravel best practices, Laravel AI SDK, subtitle-pipeline and Laravel security. Use existing framework queue payload metadata where possible and keep provider-specific requests inside the present provider boundary. Existing historical delivery notes below do not imply those fixes are present in this checkout.

### Implementation and validation record

- Recovered and reviewed the conservative boundary reconciliation from `07682be`, with regressions for dropped/duplicated overlap words, legitimate repetitions, conflicting sequence order, punctuation/untimed glue and non-overlap. Only unique equal-text matches with positive temporal overlap are reconciled. Touching/disjoint words remain ambiguous and are preserved through ownership rules.
- Language-aware fallback keeps single spaced-language words intact and no-space grapheme clusters intact. Independent valid translations survive rejected tokenization; absent translations are explicitly empty through final enrichment, contracts, response guards and UI.
- Metrics exclude old run events. Chained cue jobs read Laravel queue publication timestamps; test-only fast-mode configuration is pinned so local `.env` does not alter instruction tests. The initial delivery retained medium reasoning everywhere.
- Cue analysis returns tokens, requested translations and requested readings in one response; deterministic ICU readings remain local. New generation dispatches no separate romanization job. Existing romanization provider support is still used for lyrics correction.
- Scribe parsing retains bounded language probability and finite word logprob values, accepts paired null timing, and logs only aggregate quality data with job/run IDs. Auto language uses owned speech duration weighted by available confidence across chunks; supported equal-weight ties retain first occurrence. Explicit requested language remains authoritative. No selective extra ASR call is enabled.
- Optional vocabulary hints are validated across API/contracts/extension (20 terms, five words and 49 characters each), normalized for reuse, and sent as repeated multipart keyterms. Hinted transcript caches are isolated by owner and options. Cost estimates include the documented 20% keyterm surcharge; hint text is never logged. Input clears on submission and video/account changes.
- `youtube_url` is an opt-in mode pinned at job creation, with metadata/duration admission, canonical URL generation, guarded single-chunk dispatch, common merge continuation and distinct cache identity. Upload remains default; failures do not silently issue a second billed transcription. Migration and worker-drain rollout are documented in the operations runbook.
- Validation: `scripts/agent/check.ps1` and `scripts/agent/verify-pr.ps1` passed: **484 backend tests (3327 assertions), 227 extension tests**, contract validation/type generation, TypeScript compile and extension build. `pint --dirty --format=agent` and `git diff --check` passed. Existing extension test stderr includes an unrelated JSDOM `window` diagnostic in transcript-view testing; the suite has no failing tests.
- Browser: built panel with mocked browser messaging, 380×900 viewport. Invalid hints sent no generation request; valid hints were transmitted exactly and cleared; switching video cleared input; no horizontal overflow or browser errors. [Screenshot](evidence/vocabulary-hints.png). This verifies panel behavior, not a real YouTube/provider run.
- Review: checked current-run identity, queue publication semantics, source/token fidelity, same-language and feature flags, hint validation/owner isolation, canonical URL policy, cancellation guards, disabled-by-default direct mode and rollout compatibility. No provider-backed calls, live migration, deployment, worker restart, or reasoning change occurred.
- Git handoff: the user requested grouped local commits on `codex/subtitle-pipeline-quality`: `47ad810` records run metrics and queue waits; `01d5cc9` adds Scribe boundary handling and provider input support; `b2870d8` combines cue analysis and preserves fallback translations; `ec98e49` adds vocabulary hints and optional YouTube URL ingestion. A final documentation commit records validation and rollout guidance. No push/PR was requested. The consolidated plan remains active for the wider backlog and explicitly deferred live evidence.

Residual evidence: measured latency/recognition wins, combined-response reading quality on reference audio, direct-URL reliability near the duration limit, and calibrated selective retranscription remain open in the debt tracker. Implementation does not establish those claims.

### Follow-up: low reasoning effort (2026-09-10)

After the five grouped commits were pushed to origin, the user requested changing reasoning effort from medium to low. The shared OpenAI provider configuration now sends `reasoning.effort: low` for all seven AI agents, including combined analysis, tokenization, readings, word cards and lyrics editing. Model IDs and Fast mode are unchanged. This supersedes the initial medium-effort constraint without adding a provider experiment.

Applied OpenAI Docs, Ponytail, Laravel best practices, Laravel AI SDK and subtitle-pipeline guidance: retain the existing shared provider-options path and update the existing HTTP/agent-option assertions. [OpenAI reasoning documentation](https://developers.openai.com/api/docs/guides/reasoning#reasoning-effort) and [Laravel provider-options documentation](https://github.com/laravel/docs/blob/13.x/ai-sdk.md#provider-options) confirm the option shape.

Validation: `AiAgentInstructionTest` passed (10 tests, 132 assertions), confirming low effort reaches all seven agents' HTTP requests and provider options with Fast mode enabled or disabled. `pint --dirty --format agent` and the full `scripts/agent/check.ps1` passed (484 backend tests, 3327 assertions; 227 extension tests; contracts, compile and build). No live provider calls or worker restarts occurred; latency and output-quality effects remain unmeasured.

### Follow-up: local Fast mode (2026-09-10)

The user requested enabling Fast mode. Set the existing local `OPENAI_FAST_MODE_ENABLED` flag to `true`; the tracked default and example environment already enable it. Restarted the backend and all 31 workers after confirming no active generation or correction attempts. Effective provider options now contain `service_tier: fast` and `reasoning.effort: low`, with `gpt-5.6-luna` unchanged. All 32 runtime processes are alive and backend health returns HTTP 200. This is local runtime activation; no production setting or provider-backed latency claim is implied. Local environment files remain untracked.

## Current state and intended outcome

The goal is fast usable subtitles and high-quality completed output, with bounded cost and reliable recovery. The prior code delivery corrected source-validation prerequisites, request budgets and narrow chunk-boundary behavior; it did not adopt faster models, new scheduling or audio enhancement. Its [delivery and testing record](delivery-and-testing.md) remains open for user acceptance. The slowdown investigation below includes newer diagnostic work; do not mistake the old reviewed commit for the entire current working tree.

The audit's short trace measured 0.237 seconds of audio optimization, source cues at 19.409 seconds and completion at 156.539 seconds. That identifies downstream AI work as a priority in that trace, not a universal bottleneck or a benchmark of current code. The recent same-video slowdown evidence below is not a controlled identical-input comparison.

## Proposed end-to-end improvement path

These candidates capture the subsequent pipeline discussion. They are proposed work, not implemented behavior or proven speed/quality wins. Select a bounded slice before changing code; record comparisons in the relevant section of this document instead of creating another topic plan.

| Area | Concrete candidate | What must be verified |
| --- | --- | --- |
| Acquisition and preparation | Reuse source metadata and remove the full-file FLAC encode followed by per-chunk re-encoding where the chosen extraction path permits it. | Less CPU/disk work, preserved duration, timestamps, source policy and recognition quality. |
| Preparation and transcription overlap | Dispatch a ready chunk while later chunks are prepared, with bounded concurrency. | Correct batch completion barrier, run/cancellation guards, cleanup, provider bounds and measured first-cue latency. |
| Usable subtitles first | Build on existing partial delivery. Consider sealing an early source prefix before all chunks complete; derived features should not unnecessarily delay usable source cues. | Overlap must be resolved before sealing; stable cue/token identity, context and edit safety. Existing partial delivery is not evidence that early-prefix delivery already exists. |
| AI dependencies | Remove avoidable analysis/romanization serialization. Where source-only romanization is sufficient, compare independent execution with a combined response; retain dependencies needed by the learning contract. | First translated cue, full completion, provider load, linguistic consistency and source fidelity. No assumed winner from fewer calls alone. |
| Audio context and enhancement | Pause-aware cuts with overlap; optional source-format/channel/rate choices; targeted gain/denoise/isolation for labelled quiet/noisy/music cases. | Original timeline, real repetitions and reference recognition quality. Current format normalization is not loudness normalization or speech enhancement. |
| Selective additional work | Explore a second pass only for reliably identified suspicious passages rather than reprocessing everything. | A defined and validated detection signal, bounded extra requests, correct context/timing and no confidence-based claims without evidence. |

Implementation can establish less repeated work and correct overlap/dependencies with offline checks. Comparative measurements establish wall-clock benefit and which model, chunk or acoustic settings meet the quality floor. Do not require the entire proposed experiment matrix before starting a selected engineering slice, and do not claim an unmeasured quality improvement.

## Contents

- [Measurement and cost](#measurement-and-cost) — former 04.
- [Audio preparation and transcription](#audio-preparation-and-transcription) — former 08.
- [Provider compatibility and retries](#provider-compatibility-and-retries) — former 03.
- [Source text and token fidelity](#source-text-and-token-fidelity) — former 02.
- [Queue capacity and scheduling](#queue-capacity-and-scheduling) — former 06.
- [AI latency and linguistic quality](#ai-latency-and-linguistic-quality) — former 07.
- [Caching retention and data efficiency](#caching-retention-and-data-efficiency) — former 09.
- [Browser delivery and lifecycle](#browser-delivery-and-lifecycle) — former 10.
- [Architecture and scaling](#architecture-and-scaling) — former 11.
- [Current slowdown investigation](#current-slowdown-investigation), [worker/review record](#slowdown-worker-and-review-record), and [initial runtime observation](#initial-runtime-observation).

## Measurement and cost

Former audit section 04.

Status: planned — not started
Owner: unassigned
Type: Confirmed diagnostic fixes plus measurement foundation

### Goal

Measure each run and provider attempt accurately enough to choose speed, quality and capacity changes and to understand actual operating cost.

### Source and evidence

[Review F11/F12](../../../whole-project-review-2026-09-09.md:201) reproduces run-mixed metrics and identifies truncated/ready-only runtime counts. [Existing measurements](../../../whole-project-review-2026-09-09.md:263) contain eleven Pro completions, no near-limit samples and zero configured cost estimates; the sampled runs themselves were checked and not mixed.

Coverage: F11, F12; opportunity 2; E01; shared measurement inputs for E02–E08.

### Dependencies

No prerequisite for diagnostic fixes. Record the [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section/06 changes as new baseline conditions rather than pooling before/after runs.

### Scope

Run-scoped timing, total/ready/delayed/reserved counts, request-level usage/outcomes, estimated versus observed costs, reproducible workload manifests and explicit metric definitions. Include failed/canceled attempts separately from successful completion latency.

Out of scope: Selecting a winning model, increasing workers, creating customer SLA promises or collecting full prompts/transcripts in normal logs.

### Relevant files and context

- [app/backend/app/Console/Commands/ShowSubtitleGenerationMetrics.php](../../../../app/backend/app/Console/Commands/ShowSubtitleGenerationMetrics.php)
- [app/backend/app/Console/Commands/ShowSubtitleRuntime.php](../../../../app/backend/app/Console/Commands/ShowSubtitleRuntime.php)
- [app/backend/app/Services/Subtitles/SubtitlePipelineTelemetry.php](../../../../app/backend/app/Services/Subtitles/SubtitlePipelineTelemetry.php)
- [app/backend/app/Services/Subtitles/SubtitleRuntimeTracer.php](../../../../app/backend/app/Services/Subtitles/SubtitleRuntimeTracer.php)
- [app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php](../../../../app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
- [app/backend/tests/Feature/SubtitleRuntimeTracingTest.php](../../../../app/backend/tests/Feature/SubtitleRuntimeTracingTest.php)

### Implementation or investigation steps

- [ ] Add a two-completion reset regression and 26-active-job/Redis-queue-state cases. Fix grouping and count labels without rewriting all diagnostics.
- [ ] Capture available SDK usage/model metadata before reducing responses to structured arrays. Add narrow hooks for missing request/service-tier metadata only where needed; keep raw text/audio/secrets out of telemetry.
- [ ] Define admission wait, queue wait, active service time, backend first cue, browser first usable cue, full completion and all-attempt cost. Never sum concurrent durations as latency.
- [ ] Produce reproducible baseline manifests for ready-track reuse, transcript-cache hit and miss; include feature settings, prompts/models, tier, worker topology, cache versions and sample counts.
- [ ] Prepare E01's staged corpus/budget. Execute only the authorized sample, record limitations, and give downstream packages a stable baseline rather than a claimed SLA.

### Acceptance criteria

- [ ] Old-run timings cannot combine with current-run cost or configuration.
- [ ] Counts distinguish total jobs, truncated lists, ready/delayed/reserved queue work and verified versus configured workers.
- [ ] Provider usage and failed/retried attempt cost are visible or explicitly marked unavailable rather than zero.
- [ ] Latency definitions and per-run comparison data are documented and reproducible.
- [ ] Samples retain condition labels; n=1 and success-only data are not presented as reliable p95/failure-rate estimates.

### Validation and rollout

Run diagnostic regressions and the repository check; reconcile selected records back to raw sanitized run events. E01's proposed 36-generation/684-minute workload is an estimate, not an authorized budget or mandatory first batch. Use small staged samples and record exact cost caps before paid work.

## Audio preparation and transcription

Former audit section 08.

Status: boundary code reviewed and committed; real-audio validation deferred
Owner: Brain / Lead for selected code delivery
Work mode: Brain / Worker
Type: Confirmed merge defect plus acoustic/latency experiments

### Goal

Preserve speech/lyrics and media timing at chunk boundaries, then choose audio/acquisition settings using measured recognition quality, latency and resource cost.

### Source and evidence

[Review F06](../../../whole-project-review-2026-09-09.md:151) demonstrates both duplicate and missing boundary words with disagreeing timestamps. [Audio analysis](../../../whole-project-review-2026-09-09.md:297) traces M4A-first selection, metadata then download, full mono 16 kHz FLAC encoding and sequential chunk encodes. No isolation stage is active and no preprocessing improvement is proven.

Coverage: F06; opportunities 5, 7, 8; E03 and E04; source acquisition, language detection, timing/segmentation and metadata notes.

### Dependencies

F06 offline reproduction can start independently. The [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section owns provider null/retry behavior; the [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section supplies measurements. Coordinate script/timing invariants with 02 and cache versions with 09.

### Scope

Bounded overlap reconciliation, chunk lengths/cuts/overlap, source selection/metadata reuse, conversion/upload options, channel mixing, selective preprocessing, language disagreement and cue timing/reading quality. Separate the confirmed merge fix from optional experiments.

Out of scope: Default isolation or higher sampling rate without evidence, live-caption product expansion, immediate distributed STT architecture or treating existing pasted-lyrics matching as forced alignment.

### Relevant files and context

- [app/backend/app/Services/Audio/YouTubeAudioSource.php](../../../../app/backend/app/Services/Audio/YouTubeAudioSource.php)
- [app/backend/app/Services/Audio/ElevenLabsScribeAudioPreparer.php](../../../../app/backend/app/Services/Audio/ElevenLabsScribeAudioPreparer.php)
- [app/backend/app/Services/Audio/ScribeAudioChunker.php](../../../../app/backend/app/Services/Audio/ScribeAudioChunker.php)
- [app/backend/app/Services/Transcription/ScribeChunkPayloadMerger.php](../../../../app/backend/app/Services/Transcription/ScribeChunkPayloadMerger.php)
- [app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php](../../../../app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php)
- [app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php](../../../../app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php)

### Implementation or investigation steps

- [ ] Add the two midpoint disagreement counterexamples and perturbation/repeated-lyric fixtures. Compare bounded overlap text/time alignment and silence-aware cuts; never globally deduplicate repeated phrases.
- [ ] Establish whole-file transcription as a quality control and current chunking as the latency control. Measure ±5 s boundary errors, WER/CER, reordered words and absolute offsets.
- [ ] Compare current FLAC with supported original-file upload, raw PCM, selected channel/native rate and removal of repeated encoding. Test metadata reuse and provider URL ingestion separately while preserving public/non-live/60-minute policy.
- [ ] Evaluate gain, denoise and isolation only on labelled noisy/quiet/music/stereo inputs; include overlapping speakers and phase opposition. Do not remove silence without a time mapping.
- [ ] Test explicit/auto/mixed language and an instrumental/foreign-language introduction. Retain confidence/character/speaker metadata only for a defined decision. Add cue reading-speed, out-of-media timing and segmentation cases.
- [ ] Document winning/rejected choices, processing version changes, recovery semantics and rollout/rollback; retain current preparation if alternatives lose quality.

### Acceptance criteria

- [ ] Synthetic boundary words occur exactly once in order without losing real repeated lyrics.
- [ ] Adopted changes do not worsen reference transcription/boundary/timing quality; intervals remain mapped to original media time.
- [ ] CPU, memory, bytes, uploaded duration, retries and latency are measured separately from recognition quality.
- [ ] Audio choices preserve duration/public-video enforcement and do not assume lossless conversion restores lost source information.
- [ ] Provider capability/account limits and actual runtime binaries are verified before adopting a new ingestion mode.

### Validation and rollout

E03's proposed screen is 96 requests / 96–144 source minutes; E04's larger comparison is 384–720 source minutes plus overlap. These are unapproved planning estimates: use offline fixtures first and authorize/cap each paid stage separately. Include reference transcripts and bilingual listening judgments; run the repository check for fixes.

### Progress and decisions

- 2026-09-09: Narrow boundary reconciliation selected in the combined [05/06/08 delivery](delivery-and-testing.md#delivery-scope-and-decisions), with final decisions in the [consolidated review](delivery-and-testing.md#independent-review). Audio/acquisition/preprocessing/upload and chunk-size alternatives remain deferred. Synthetic counterexamples justify only the scoped correction; real-audio recognition validation remains outstanding.

### Completion notes

Boundary code is committed as `07682be` on the combined delivery branch after three delegated repair rounds and independent review. Forty focused synthetic tests passed (100 assertions), with independent ordering probes. The merger reconciles only mutually unique, ordered, same-text words with overlapping intervals near adjacent boundaries; ambiguous alignments retain midpoint ownership. It preserves chosen native timestamps, untimed attachments and source ordering without global deduplication or sorting. See the [consolidated review](delivery-and-testing.md#independent-review).

Real-audio recognition, larger overlap/chunk-size comparisons and acoustic/acquisition experiments are unrun. No model, source preparation or chunk-size setting changed. Shared processing-version invalidation is committed in 51e75fe (transcript cache v3 and job v10). Final integrated evidence is recorded in the owning chunk and testing handoff. User acceptance and merge approval remain pending; this package's experiments are not complete or authorized.

## Provider compatibility and retries

Former audit section 03.

Status: overload/request-runner prerequisites delivered with former 05/06; Scribe nullable timing and retry work remain open
Owner: unassigned
Type: Confirmed fixes plus targeted no-speech behavior validation

### Goal

Supported provider responses normalize consistently and temporary provider failures retry within explicit bounds without discarding successful work or publishing stale results.

### Source and evidence

[Review F08/F09](../../../whole-project-review-2026-09-09.md:171) shows null timestamps rejected and Scribe jobs failing after one exception. [F13](../../../whole-project-review-2026-09-09.md:219) reproduces HTTP 503 through Laravel AI 0.6.8 becoming non-transient enrichment_failed. Provider outage frequency and empty-silence-chunk frequency remain unmeasured.

Coverage: F08, F09, F13; opportunity 4 (exception classification and retries); E06 (provider failure portion).

### Dependencies

No prerequisite for offline fixes. The [queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling) section owns shared rate limits/deadlines; the [audio preparation and transcription](pipeline-speed-quality-and-reliability.md#audio-preparation-and-transcription) section owns chunk merge and acoustic quality.

### Scope

Nullable timing pairs, typed overload/connection/rate-limit classification, bounded Scribe retries, preservation of completed chunk artifacts during retries, and terminal cleanup. Distinguish valid no-speech chunks from unusable whole-video output after provider fixtures establish expected behavior.

Out of scope: Provider migration, webhook-based STT, global capacity tuning or changing chunk ownership logic.

### Relevant files and context

- [app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php](../../../../app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php)
- [app/backend/app/Jobs/TranscribeSubtitleAudioChunk.php](../../../../app/backend/app/Jobs/TranscribeSubtitleAudioChunk.php)
- [app/backend/app/Jobs/SubtitleCueBatchJob.php](../../../../app/backend/app/Jobs/SubtitleCueBatchJob.php)
- [app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php](../../../../app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
- [app/backend/app/Services/Subtitles/LyricsCorrectionService.php](../../../../app/backend/app/Services/Subtitles/LyricsCorrectionService.php)
- [app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php](../../../../app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php)

### Implementation or investigation steps

- [ ] Add regression cases for absent/null/one-sided/invalid numeric timestamps and HTTP 429/500/503/connection failures through installed adapters, with stray requests prevented.
- [ ] Normalize a documented null timestamp pair into the existing untimed representation; retain strict rejection of malformed numeric timing.
- [ ] Handle the installed SDK overload exception consistently in analysis and lyrics alignment. Share a small classifier only where it removes real duplicated behavior.
- [ ] Introduce bounded Scribe transient retries with jitter/Retry-After handling and run/cancellation guards. Preserve successful siblings until terminal failure; account for ambiguous accepted-but-timed-out requests.
- [ ] Coordinate retry budgets and cleanup ownership with the [queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling) section. Verify cancellation, reset, worker death and exhausted retries; record attempted provider usage through the [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section when available.

### Acceptance criteria

- [ ] Null and omitted timing pairs behave consistently; malformed timing is still rejected.
- [ ] Actual SDK 503 responses reach transient retry behavior in generation and correction paths.
- [ ] Scribe retries only appropriate transient failures and does not replay completed sibling chunks unnecessarily.
- [ ] Cancellation/reset prevent stale writes; terminal failure still releases reservations and reclaims artifacts/audio.
- [ ] No retry policy claims exactly-once provider billing for ambiguous network failures.

### Validation and rollout

Use real SDK/HTTP adapters with faked responses, focused queue/failure tests, and the repository check. Disposable Linux Postgres/Redis worker-kill proof belongs to E06/the [queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling) section. No paid provider call is needed for these regression tests.

## Source text and token fidelity

Former audit section 02.

Status: source coverage/case prerequisites delivered with former 05; Korean spacing and broader quality acceptance remain open
Owner: unassigned
Type: Confirmed fixes

### Goal

Every displayed source cue preserves its lexical content, original surface spelling and intended spacing while allowing useful language-specific token boundaries.

### Source and evidence

[Review F04–F05](../../../whole-project-review-2026-09-09.md:131): the validator accepted only “world” for “Hello world”, “at” for “cat”, and changed casing; Korean source spaces were removed by the shared no-space cleanup rule. The overlay renders token text, so incomplete tokens can erase visible words.

Coverage: F04, F05; opportunity 1 (source fidelity); lexical/script baseline for E02 and E05.

### Dependencies

No prerequisite. The [learning quality and editing](learning-and-editing.md#learning-quality-and-editing) section builds on these source invariants; coordinate cache invalidation with the [caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency) section.

### Scope

Lossless lexical coverage, exact surface preservation, Korean/mixed-script spacing and the source-to-overlay contract. Keep normalized lookup text separate from displayed text. Account for punctuation, repeated words, combining marks and valid CJK/Thai segmentation.

Out of scope: Model selection, audio enhancement, full timing realignment or an unconditional token-span schema migration.

### Relevant files and context

- [app/backend/app/Services/TranslationAnalysis/LearningTokenOutputValidator.php](../../../../app/backend/app/Services/TranslationAnalysis/LearningTokenOutputValidator.php)
- [app/backend/app/Services/Text/SubtitleText.php](../../../../app/backend/app/Services/Text/SubtitleText.php)
- [app/backend/app/Services/Text/NoSpaceArtifactBoundary.php](../../../../app/backend/app/Services/Text/NoSpaceArtifactBoundary.php)
- [app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php](../../../../app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php)
- [app/extension/utils/overlay/overlay-render.ts](../../../../app/extension/utils/overlay/overlay-render.ts)
- [app/backend/tests/Unit/LearningTokenOutputValidatorTest.php](../../../../app/backend/tests/Unit/LearningTokenOutputValidatorTest.php)

### Implementation or investigation steps

- [ ] Reproduce omission, substring and casing acceptance and Korean space removal. Replace tests that incorrectly bless lexical omissions with contract-focused cases.
- [ ] Choose the narrowest reliable coverage check: reject skipped lexical characters and preserve source slices while explicitly permitting punctuation/whitespace differences.
- [ ] Separate language-aware artifact cleanup from generic whitespace normalization. Preserve legitimate Korean spacing without restoring known spurious CJK character spaces.
- [ ] Verify final and partial overlay/transcript rendering after tokenization, enrichment and edits. Evaluate source-span rendering only if it materially strengthens the boundary; document any schema change.
- [ ] Version affected normalization/tokenization caches and generated tracks; coordinate identities with the [caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency) section and update language-quality documentation.

### Acceptance criteria

- [ ] No lexical prefix, infix or suffix can disappear behind a successful tokenization.
- [ ] Original spelling/casing and combining marks survive display; normalized lookup values do not replace surface text.
- [ ] Korean word spaces and mixed Korean/Latin text are preserved while existing valid CJK cleanup fixtures continue to pass.
- [ ] Repeated-word and no-space-script tokenizations cover the source without forcing one universal word-boundary rule.
- [ ] Backend contracts, partial/final display and correction mutations agree; changed processing versions prevent stale results being mistaken for corrected output.

### Validation and rollout

Add meaningful validator/normalizer and rendered-overlay regressions. Include the review counterexamples, Japanese particle coverage, Thai/Lao marks, punctuation, repeated words and RTL text. Run the repository check. Bilingual quality review is an additional gate, not replaced by string tests.

## Queue capacity and scheduling

Former audit section 06.

Status: code reviewed and checked; user acceptance and scheduling experiments outstanding
Owner: Brain / Lead for selected code delivery
Work mode: Brain / Worker
Type: Confirmed limiter scope gap plus scheduling experiments

### Goal

Bound real provider demand and queue-unit work, preserve account fairness, and remove measured avoidable serialization without losing run/cancellation safety.

### Source and evidence

[Review F10](../../../whole-project-review-2026-09-09.md:191): one rate-limited job can issue many recursive requests; a 20-cue split tree can reach 39 calls before extra reprompts. [Scheduling opportunity](../../../whole-project-review-2026-09-09.md:240): fixed analysis→romanization chains can hold later analysis behind a slow call. Actual load impact remains to be measured.

Coverage: F10; opportunities 4 (limits/deadlines) and 6; E06; scheduling portion of E02.

### Dependencies

The [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section supplies retry classification; the [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section supplies trustworthy measurements. Coordinate scheduling/model comparisons with the [ai latency and linguistic quality](pipeline-speed-quality-and-reliability.md#ai-latency-and-linguistic-quality) section.

### Scope

Provider request limits across generation/cards/corrections, per-unit call/wall-clock budgets, recursive split behavior, provider/account concurrency and tier fairness. Compare scheduling alternatives against the existing fixed-chain baseline.

Out of scope: Blind worker-count increases, a general workflow engine, provider webhook migration or distributed file storage.

### Relevant files and context

- [app/backend/app/Jobs/SubtitleCueBatchJob.php](../../../../app/backend/app/Jobs/SubtitleCueBatchJob.php)
- [app/backend/app/Jobs/Middleware/LimitSubtitleBatchConcurrency.php](../../../../app/backend/app/Jobs/Middleware/LimitSubtitleBatchConcurrency.php)
- [app/backend/app/Services/Subtitles/SubtitleBatchDispatcher.php](../../../../app/backend/app/Services/Subtitles/SubtitleBatchDispatcher.php)
- [app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php](../../../../app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
- [app/backend/config/subtitles.php](../../../../app/backend/config/subtitles.php)
- [app/backend/config/queue.php](../../../../app/backend/config/queue.php)

### Implementation or investigation steps

- [ ] Instrument actual calls and worker occupancy under deterministic delayed/failing providers. Obtain real provider quotas before any live concurrency tuning.
- [ ] Apply request accounting at a shared provider boundary, including interactive paths; retain account-wide leases and distinct generation/batch queues.
- [ ] Bound inline split/retry work by remaining deadline; decide when to defer smaller units rather than recursively consume a worker timeout.
- [ ] Compare current chains with balanced batches, analysis-first work and dynamic continuations only if justified. Measure first translated cue, completion and throughput separately.
- [ ] Run disposable Linux Postgres/Redis load/fault tests: worker kill, Redis ambiguity, cancellation/reset, retries, account caps, Base reserves and sustained Pro pressure on Plus.

### Acceptance criteria

- [ ] The configured provider guard limits actual requests, not only queue-job starts; token pressure is measured where possible.
- [ ] External calls and retries fit queue-unit deadlines below retry-after; malformed output cannot create uncontrolled amplification.
- [ ] Per-user limits, run guards, reservations and terminal cleanup survive delayed/failing work.
- [ ] No queued job is stranded after worker failure; fairness evidence includes Plus as well as reserved Base capacity.
- [ ] Scheduling changes are adopted only with measured benefit and unchanged correctness/quality, with the old schedule available as rollback.

### Validation and rollout

E06 can exercise most behavior with fake providers and zero paid calls. Record worker slots, request counts, queue states, lease expiry, settlement and cleanup. Run the repository check. Any real provider test uses known quotas and a capped, authorized workload.

### Progress and decisions

- 2026-09-09: Actual-request limits and bounded-unit code selected in the combined [05/06/08 delivery](delivery-and-testing.md#delivery-scope-and-decisions). The [consolidated review](delivery-and-testing.md#independent-review) records final architecture decisions and evidence. Scheduling alternatives, worker-count changes and sustained-load adoption claims remain deferred pending baseline and runtime evidence. The [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section overload classification is a narrow required prerequisite; the [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section diagnostic fixes are not silently included.

### Completion notes

Runner foundation is committed as bfb0c7a after independent nested-budget repair. Integration routes every current OpenAI prompt through the shared request guard, removes the obsolete job-entry limiter, separates local admission release from provider retry and shares account lease occupancy across tiers. Independent integration reviews led to repairs for correction deadline wiring, cancellation-before-lease ordering and boundary regression evidence; the [consolidated review](delivery-and-testing.md#independent-review) records their resolution. All three findings cleared independent re-review (38 tests / 172 assertions plus ten deadline/callback probes). Integration is committed as c180b2b; final root harness passed 516 backend tests / 3,336 assertions and 249 extension tests plus contracts, compile and build. No scheduling alternative, worker-count change or measured fairness improvement has been adopted. See the owning delivery plan and delivery-and-testing.md#testing-handoff for final evidence and acceptance state.

## AI latency and linguistic quality

Former audit section 07.

Status: planned — not started
Owner: unassigned
Type: Experiment-first optimization

### Goal

Choose stage-specific AI settings that measurably improve time to useful translation/romanization and cost while preserving source fidelity and learning quality.

### Source and evidence

[Review timing traces](../../../whole-project-review-2026-09-09.md:276): one short run had source cues at 19.409 s and completion at 156.539 s, dominated by analysis and romanization. Current configuration broadly requests high reasoning and defaults Fast mode on. These observations identify an investigation target, not a proven winning model.

Coverage: Opportunity 3; E02; OpenAI reasoning/model/Fast/prompt-cache and deterministic romanization research.

### Dependencies

Packages 02/05 establish quality acceptance and 04 establishes measurement. Coordinate the [queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling) section scheduling so model and queue changes are not confounded.

### Scope

Current model versus lower supported effort/stage-specific candidates, batch character/cue budgets, context duplication, combined versus separate romanization, actual Fast tier and prompt caching. Preserve established deterministic transliteration language gates.

Out of scope: Automatic model migration, quality grading solely by another model, removing context merely to reduce tokens or claiming Fast-mode benefit from request configuration alone.

### Relevant files and context

- [app/backend/config/ai.php](../../../../app/backend/config/ai.php)
- [app/backend/app/Ai/Agents](../../../../app/backend/app/Ai/Agents)
- [app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php](../../../../app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
- [app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php](../../../../app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php)
- [app/backend/app/Console/Commands/EvalTokenization.php](../../../../app/backend/app/Console/Commands/EvalTokenization.php)
- [app/backend/tests/Fixtures/tokenization](../../../../app/backend/tests/Fixtures/tokenization)

### Implementation or investigation steps

- [ ] Recheck official model/SDK capabilities and exact requested/returned model/service tier. Establish the current configuration as a reproducible control.
- [ ] Define bilingual quality criteria for token boundaries, translation omissions, romanization and degraded-output rate using the [learning quality and editing](learning-and-editing.md#learning-quality-and-editing) section. Extend beyond Japanese/Mandarin/Thai where the product supports other scripts.
- [ ] Screen one variable at a time: effort, model, batch size, context/prompt prefix, Fast tier, or combined romanization. Record requests/tokens/retries and queue/service time separately.
- [ ] Repeat promising settings under matched inputs and then test combinations; coordinate schedule variants with the [queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling) section. Inspect actual cache tokens rather than assuming caching.
- [ ] Document retain/change decisions and tradeoffs. If a candidate wins, define a small rollout, version invalidation with the [caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency) section, regression gate and rollback configuration.

### Acceptance criteria

- [ ] Every candidate meets lossless source/identity tests and the agreed bilingual quality floor.
- [ ] Comparisons report sample sizes, matched conditions, p50/p95 caveats, fallback/retry rates and total attempted cost.
- [ ] Any adopted speed/cost benefit is measured, not inferred from a newer model or generic benchmark.
- [ ] Suggested 15% p50 improvement / no >5% p95 regression remains a proposed decision threshold until agreed for the workload.
- [ ] Rejected candidates and reasons are retained; retaining the current model is a valid completed decision.

### Validation and rollout

E02's existing evaluator is live, not offline: 36 fixtures × three candidates × three repeats implies at least 324 calls before retries. Start with offline semantic tests and an explicitly bounded authorized screen. Run the repository check for any subsequently authorized code/config change.

## Caching retention and data efficiency

Former audit section 09.

Status: planned — not started
Owner: unassigned
Type: Code-supported risks, focused reproductions and measured cleanup

### Goal

Reuse results safely, keep costs and storage bounded, and remove proven unnecessary reads without weakening ownership or concurrent update protection.

### Source and evidence

[Review opportunities 10–12](../../../whole-project-review-2026-09-09.md:244) identify manual generated-track versioning, duplicate cache misses, missing token occurrence in card keys and unnecessary full-track/context reads. [Retention notes](../../../whole-project-review-2026-09-09.md:257) distinguish terminal cleanup from failed/canceled rows with no expiry. Performance and ambiguity incidence were not measured.

Coverage: Opportunity 10; data/query portion of opportunity 12; retention portion of opportunity 13; report persistence/privacy notes.

### Dependencies

Can investigate independently. Final source/model invalidation follows packages 02/07/08; the [learning quality and editing](learning-and-editing.md#learning-quality-and-editing) section defines useful card semantics; the [operations security and release](operations-and-release.md#operations-security-and-release) section verifies deployed retention.

### Scope

Transcript/track/card cache keys and invalidation, same-word different-occurrence cards, duplicate paid misses, whole-track/context read cost, data retention and orphan-audio recovery. Preserve account-private edits and financial ledger policy.

Out of scope: A universal caching framework, automatic model fingerprints without a clear contract, globally shared edited tracks or moving all tokens into child rows without contention evidence.

### Relevant files and context

- [app/backend/app/Support/SubtitleProcessingVersion.php](../../../../app/backend/app/Support/SubtitleProcessingVersion.php)
- [app/backend/app/Services/Transcription/VideoTranscriptCache.php](../../../../app/backend/app/Services/Transcription/VideoTranscriptCache.php)
- [app/backend/app/Services/TranslationAnalysis/LearningTokenEnrichmentService.php](../../../../app/backend/app/Services/TranslationAnalysis/LearningTokenEnrichmentService.php)
- [app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php](../../../../app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php)
- [app/backend/app/Http/Controllers/DashboardController.php](../../../../app/backend/app/Http/Controllers/DashboardController.php)
- [app/backend/app/Console/Commands/PruneExpiredSubtitleTracks.php](../../../../app/backend/app/Console/Commands/PruneExpiredSubtitleTracks.php)

### Implementation or investigation steps

- [ ] Map each cache's owner, input identity, prompt/model/processing version, TTL and billing semantics. Reproduce ambiguous repeated-token collisions and concurrent cache misses before adding key fields or leases.
- [ ] Define explicit invalidation when source normalization, merge, model or prompt semantics change. Coordinate a single version policy with the owning packages.
- [ ] Add occurrence identity only where meaning differs; add single-flight behavior only for a measured duplicate-work problem, with crash expiry and cancellation semantics.
- [ ] Measure SQL/JSON bytes and memory on long tracks. Remove the dashboard's unused track eager load and unnecessary repeated reads where proven; retain locked fresh token merges.
- [ ] Set intentional retention for failed/canceled jobs/events, expired tokens, failed queue jobs/batches and orphan audio. Separate data deletion from ledger retention and provider-side retention; implement dry-run/count evidence before destructive cleanup.

### Acceptance criteria

- [ ] Changed source/model/quality semantics cannot silently reuse incompatible results.
- [ ] Cards for repeated words do not share incorrect contextual meanings under the agreed semantic tests.
- [ ] Public-source reuse never exposes private account edits; exact ready-track reuse and usage settlement remain correct.
- [ ] Measured unnecessary reads are reduced without lost concurrent updates or a speculative per-token schema rewrite.
- [ ] Retention has explicit owners/limits, safe cleanup boundaries and observable results; provider retention is not inferred from local deletion.

### Validation and rollout

Use cache-key, concurrent-miss, locked-merge, expiry and dry-run cleanup tests plus long-track query/memory comparisons. Run the repository check. Production deletion is outside this planning task and requires the actual execution scope to authorize the chosen retention policy.

## Browser delivery and lifecycle

Former audit section 10.

Status: planned — not started
Owner: unassigned
Type: Measured frontend improvement and runtime acceptance

### Goal

Make subtitles feel responsive and remain correct across browser restarts, navigation, account changes, edits and long viewing sessions, with bounded delivery overhead.

### Source and evidence

[Review opportunity 9](../../../whole-project-review-2026-09-09.md:243) identifies full partial-response reconstruction, repeated persistence and revision rebinding. Existing ownership/recovery guards are substantial and historical browser issues are not assumed to recur. [E07](../../../whole-project-review-2026-09-09.md:323) defines actual loaded-extension validation still missing from the audit.

Coverage: Opportunity 9; browser portion of opportunity 13; E07; extension lifecycle, timing, accessibility and quick-fix recovery notes.

### Dependencies

Baseline browser testing can start independently. Integrate quality states from 05 and measurements from 04; coordinate stable-prefix architecture decisions with 11.

### Scope

Unchanged partial revisions, payload/storage/DOM overhead, service-worker recovery, tab/window isolation, SPA/Shorts, quick-fix late completion, native timing/study controls, keyboard/RTL/responsive acceptance and long-session memory.

Out of scope: A UI framework rewrite, indefinite service-worker keepalive, WebSockets as an assumed provider-speed fix or changes to acoustic timestamps to conceal display defects.

### Relevant files and context

- [app/extension/entrypoints/background.ts](../../../../app/extension/entrypoints/background.ts)
- [app/extension/entrypoints/content.ts](../../../../app/extension/entrypoints/content.ts)
- [app/extension/utils/active-tracks.ts](../../../../app/extension/utils/active-tracks.ts)
- [app/extension/utils/account-session.ts](../../../../app/extension/utils/account-session.ts)
- [app/extension/utils/webvtt-track.ts](../../../../app/extension/utils/webvtt-track.ts)
- [app/extension/utils/overlay.ts](../../../../app/extension/utils/overlay.ts)
- [app/extension/utils/panel/transcript.ts](../../../../app/extension/utils/panel/transcript.ts)
- [app/backend/app/Services/Subtitles/SubtitlePartialTrackAssembler.php](../../../../app/backend/app/Services/Subtitles/SubtitlePartialTrackAssembler.php)

### Implementation or investigation steps

- [ ] Build deterministic browser fixtures and run the actual MV3 entrypoints with a fake backend before using live providers. Record first source/translated paint separately from backend availability.
- [ ] Measure repeated partial payloads, writes and rebinds. Suppress unchanged work while preserving run identity, new-run recovery and final publication; compare conditional GET before transport changes.
- [ ] Force worker termination, navigation, two-tab/two-window activity, logout/account switch and concurrent patches. Verify cancellation, reload and delayed quick-fix results against exact operation/track identity.
- [ ] Exercise native seek, offsets, 0.5×/1×/2× playback, cue hold and hover/focus pause ownership; preserve partial source/search/copy and keyboard focus.
- [ ] Run narrow/full-screen/200% zoom/RTL and screen-reader checks, plus a 60-minute session with heap/node/listener measurements. Capture reproducible evidence for remaining smoke issues.
- [ ] Consider earlier stable-prefix delivery or SSE only after narrower changes and measurements miss an explicit target; the [architecture and scaling](pipeline-speed-quality-and-reliability.md#architecture-and-scaling) section owns the cross-system decision.

### Acceptance criteria

- [ ] No stale result crosses account, tab, video, run or edited-track identity.
- [ ] Unchanged partial revisions do not cause unnecessary persistence/rebinding under measured tests.
- [ ] Worker suspension/restart and late edits have demonstrated recovery; no silent committed edit is permanently reported as lost.
- [ ] Timing/study controls, focus, partial transcript features and RTL/narrow layouts pass actual browser acceptance.
- [ ] Long-session resource use settles after navigation; no leak is alleged merely from a large entrypoint or DOM replacement.

### Validation and rollout

E07 mostly needs fake backend fixtures and no paid calls. Run entrypoint tests, TypeScript/build and the repository check; separately capture real loaded-extension screenshots/video and keyboard/lifecycle evidence. Existing G1/G5/R7 acceptance is coordinated with the [operations security and release](operations-and-release.md#operations-security-and-release) section, not silently marked complete.

## Architecture and scaling

Former audit section 11.

Status: planned — not started
Owner: unassigned
Type: Evidence-led architecture decisions; implementation only for selected slices

### Goal

Keep the execution graph understandable and choose architectural changes only when they solve a measured bottleneck or reliability requirement better than the existing design.

### Source and evidence

[Architecture coverage](../../../whole-project-review-2026-09-09.md:26) identifies working Laravel queues, run-scoped artifacts, guarded publication, WXT recovery and native timing. [Alternative comparison](../../../whole-project-review-2026-09-09.md:328) evaluates retaining those paths, narrower changes and larger replacements. Large files or newer services alone are not defects.

Coverage: Architecture portion of opportunity 12; all six larger alternatives in report section 5: STT occupancy, early source prefix, acquisition, scheduling, multiple hosts and browser transport.

### Dependencies

Small repeated-boundary cleanup can start with owning packages. Larger decisions require measurements from 04 and relevant results from 06–10; this is not a prerequisite blocking their narrow fixes.

### Scope

Concrete repeated responsibility cleanup and documented decisions among current architecture, smaller fixes and larger alternatives. Preserve the visible pipeline, account/run identity, transaction boundaries and native platform primitives.

Out of scope: Replacing Laravel/WXT/Blade, speculative horizontal scaling, implementing all alternatives or splitting classes solely to meet arbitrary line counts.

### Relevant files and context

- [app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php](../../../../app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php)
- [app/backend/app/Services/Subtitles/SubtitleBatchDispatcher.php](../../../../app/backend/app/Services/Subtitles/SubtitleBatchDispatcher.php)
- [app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php](../../../../app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php)
- [app/backend/app/Services/Audio/SubtitleAudioWorkspace.php](../../../../app/backend/app/Services/Audio/SubtitleAudioWorkspace.php)
- [app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php](../../../../app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
- [app/extension/entrypoints/background.ts](../../../../app/extension/entrypoints/background.ts)
- [docs/operations/production-hosting-and-ops.md](../../../operations/production-hosting-and-ops.md)

### Implementation or investigation steps

- [ ] Reconstruct the current graph after earlier fixes and identify duplicated responsibilities that cause inconsistent behavior, not merely repeated syntax.
- [ ] Let the [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section own shared exception classification and the [caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency) section own proven read/caching waste. Avoid duplicating their implementations or extracting a generic workflow/repository layer.
- [ ] For STT webhooks, compare blocked worker occupancy against callback authentication, idempotency, task/run correlation, missing-callback reconciliation, cancellation and cleanup costs.
- [ ] For early stable-prefix delivery/dynamic scheduling, specify overlap sealing, context, cue/token identity and edit safety. Compare with the [queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling) section/10's narrower changes before choosing.
- [ ] For URL ingestion, multiple hosts or new transport, compare the [audio preparation and transcription](pipeline-speed-quality-and-reliability.md#audio-preparation-and-transcription) section/10 results with acquisition policy, shared durable audio, signed access/expiry, reconnect/auth and operational burden.
- [ ] Record one decision per alternative: retain, adopt a bounded slice, or defer with a concrete missing measurement. If adopted, write a focused migration/rollout/rollback slice rather than executing every alternative together.

### Acceptance criteria

- [ ] Every selected refactor names a real boundary/problem and preserves demonstrated invariants.
- [ ] Each larger alternative has current baseline evidence, a smaller comparator, adoption/rejection criteria and resource/operational cost.
- [ ] Multiple-host designs do not assume Redis makes local audio paths shared.
- [ ] Webhook/streaming designs explicitly address duplicate/late/missing events and stable source/learning identity.
- [ ] Retained and deferred decisions are documented without marking unimplemented architecture complete; no speculative general framework is introduced.

### Validation and rollout

Use the owning package's benchmark/fault/browser results and the repository check for any selected code slice. A decision-only completion needs documented evidence and unresolved criteria, not an artificial implementation. Keep architecture and operational docs synchronized only after a decision is implemented or explicitly recorded as future work.

## Runtime investigation record

The following dated records retain actual observations, worker ownership and open reviews. They do not authorize model/effort or scheduling experiments. Update the investigation here when new evidence arrives; application changes belong to the existing Brain/Worker delivery. Historical requests to read a separate note now refer to the corresponding section below.

## Current slowdown investigation

Work mode: Brain / Worker. Continuation of delivery 05/06/08 user acceptance. Clinical acceptance: not applicable. No merge or acceptance granted.

### Status

The user reproduced slower generation after restarting workers. The restart did not resolve the reported slowdown. No causal implementation defect is established yet. Do not claim provider latency, stricter validation, or the restart explains the problem without additional evidence.

Branch: `codex/learning-queue-audio-05-06-08`. Reviewed delivery code: `c180b2b9ccd1337671ad2933f79ea46a43b4263f`. At investigation start HEAD `753d0a9` differs only in documents from this application revision. Accepted base `75dbd046f8ef6b71226db85ee271919421dae60b`. Another task owns preexisting dirty document cleanup; investigation leaves that work untouched.

### Real runtime comparison

Same video `yAbMYLPdyKI`, Arabic to English, Pro, translation and romanization enabled, 160 seconds. Times are UTC. Read-only local structured logs and Boost PostgreSQL queries; no provider calls or raw subtitle copies.

| Evidence | Earlier run | Fresh-worker run |
| --- | --- | --- |
| Public job | 8fb986f2-7196-4b1f-84cb-c2ec0164f61b | 0345e8b2-1028-4b0b-9581-2a027913b072 |
| Run | b1f92313-7bbf-42ad-9191-2589dbc2a6c8 | 3986f487-dbd9-43b0-9cbe-12e3f7360baf |
| Start | Sep 8 03:05:22 | Sep 9 22:39:14 |
| Transcription | 24.886 seconds | 34.576 seconds |
| Cached transcript | ID 21, v2; 57 cues, 1327 source characters | ID 26, v3; 61 cues, 1337 source characters |
| Analysis batch 0 | 33.385 seconds | 108.705 seconds |
| Analysis batch 1 | 41.508 seconds | 69.300 seconds |
| Analysis batch 2 | 26.333 seconds | 42.008 seconds |
| Analysis batch 3 | absent | 4.962 seconds, one cue |
| Romanization 0 | 37.088 seconds | unfinished at cancellation, about 66 seconds elapsed |
| Romanization 1 | 35.281 seconds | 64.435 seconds |
| Romanization 2 | 29.020 seconds | 48.891 seconds |
| Outcome | completed in 109.739 seconds total | user cancelled at 22:42:49, about 215 seconds total |

Fresh-run analysis starts 22:39:55–22:40:06. Three account lock-busy releases of two seconds occur before admission; the long durations above occur after stage start. No analysis/romanization validation retry is recorded for this fresh run. Logs show the same `gpt-6-astra` model label, but do not prove identical historically loaded options or provider execution conditions. The transcript changed slightly between live transcriptions, so this is a same-video comparison, not an identical-input benchmark. A single transcription chunk means adjacent chunk reconciliation is not exercised for this video.

All analysis finished by 22:41:43. The overall job still displays tokenizing while chained romanization finishes; this stage-label behavior predates delivery. It hides the actual substage but does not by itself explain slower processing.

Retry run `25f1ac6b-0990-4f22-869a-1ceccfa55b37` reused the new transcript, started analysis 22:42:51, and was cancelled 22:43:40. Batch 2 completed in 46.883 seconds, batch 3 in 6.395 seconds; larger batches had not completed before cancellation. Cancellation drained both batches safely and no track was published. Do not compare this cancelled 49-second retry as a completed generation time.

### Independent offline audit TR-A

Reviewer: `/root/review_prep`, separate from implementing workers. Read-only completion report received. No files written or external requests made.

- Real installed SDK plus HTTP fake produced byte-identical accepted-base/delivery HTTP bodies for identical fixture input: tokenization 5050 bytes, analysis 5889 bytes, romanization 1987 bytes; one request each.
- Procedure: bootstrap Laravel; read base provider using `git show`, rename its class in memory, instantiate base and delivery providers. Reflection-invoke input builders and private promptAgent using the same mixed-script cue and corresponding tokens. Use `Http::preventStrayRequests` and catch-all fake completed Responses output. Compare complete request bodies, not only top-level agent fakes. No real credentials needed in requests.
- Agents, prompts, schemas, `config/ai.php`, SDK lockfile and input JSON flags are unchanged between exact refs.
- Synthetic validator comparison: 100 iterations of 20 cues and 1100 source characters per batch; base 0.249 ms/batch, delivery 0.395 ms/batch. This small fixture result cannot establish real network speed but does not support validator CPU cost explaining tens of seconds.
- The request runner adds no sleep, blocking admission wait, inline retry or lock around SDK I/O. First-request timeout remains 120 seconds.
- Stricter validation can introduce repair requests on invalid output; it is not evidenced as the cause of the first fresh run, which recorded none.

Root independently ran `php artisan test --compact tests/Unit/AiRequestRunnerTest.php tests/Unit/CueEnrichmentServiceTest.php tests/Unit/LearningTokenOutputValidatorTest.php`: 92 tests, 293 assertions passed in 1.62 seconds. Synthetic evidence only.

### Next bounded work

Historical configuration confounder: retained worker stdout files for the earlier comparison run are `tse-local-batch-priority-{01,04,20}-20260907224628.log`, corresponding to startup Sep 8 02:46:28 UTC. Commit `60be1a2` at 02:58:30 UTC added explicit high reasoning and optional fast service tier to `config/ai.php` and routed agent provider options. These workers' first recorded analysis jobs began at 03:05:54 UTC. Laravel loads configuration when bootstrapping a worker, so the earlier process may have retained configuration without these options even while lazily loading newer agent classes. This is a plausible pre-delivery source of changed runtime behavior, not proved: commit time is not file-write time and no original worker configuration snapshot/request body was retained. Do not claim the earlier requests used a particular effort or change effort without authorization.

Packet TR-B in `pipeline-speed-quality-and-reliability.md#slowdown-worker-and-review-record` assigns `/root/builder_tokenizing_diagnostics`, model `gpt-5.6-luna`, reasoning `xhigh`, minimal safe request timing diagnostics and offline regression tests. This is an observability repair, not a claimed latency fix. Consequential review is required because instrumentation surrounds the provider boundary and must preserve failure/cancellation behavior.

Remaining gap: existing stage events combine ownership/database checks, admission/cache operations, SDK/provider wait, validation and publication. Separate preflight from SDK duration before assigning blame. Diagnostic correlation must preserve worker PID and avoid subtitle text, prompts, response bodies or secrets. User owns the next live/UI generation. No model/effort, scheduling, batch-size, or audio experiments are authorized by this investigation.

### Acceptance and next agent

#### Diagnostic delivery

Reviewed diagnostic commit: `403a9867ad735ce096cf4e6948707f0f9017262c` on `codex/learning-queue-audio-05-06-08`. Worker TR-B and independent reviewer TR-C completed; Brain reviewed and committed only runner/tests/observability changes. No causal speed fix, new model, effort, batching, scheduling, audio change, deployment, push or merge was made in this investigation.

Full `scripts/agent/check.ps1` passed: 520 backend tests / 3346 assertions, 249 extension tests / 31 files, contracts, TypeScript compilation, build and documentation lint. Log: local temporary `tse-tokenizing-diagnostics-check.log`. The earlier 92-test and worker 76-test checks are narrower evidence, not additional unique test counts. These are synthetic non-UI checks, not proof that the slowdown is fixed.

After confirming no active jobs and all reported queues empty, Brain restarted the existing local runtime with `scripts/runtime/start-local-backend-workers.ps1 -SkipDocker -SkipMigrate` to load the committed diagnostics. All 32 recorded processes (one backend, 31 configured workers) are alive, startup stderr files are empty, and `subtitles:runtime-check --json --strict` passed. Existing local PostgreSQL/Redis profile and worker counts remain in use. No generation was started; an extension reload is unnecessary for this backend-only diagnostic. Investigation and packet notes were concurrently consolidated into this document by the user's documentation task; Brain preserved that task's uncommitted documentation changes.

Diagnostic events now expose `preflight_ms`, `sdk_ms`, `duration_ms`, `worker_pid`, `agent`, `request_ordinal`, `outcome`. The preflight includes ownership callback, encoding, admission, timeout checks and started-log overhead; SDK includes prompt/toArray and existing exception mapping. Downstream output validation/publication is outside these timings. Total ends before finished-log emission. No token usage or original request options are retained, and past requests cannot be reconstructed from these new events. The historical high-reasoning hypothesis remains unproved.

#### Next user smoke test

1. Use the same video `yAbMYLPdyKI`, Pro, automatic source language, English target, translation and romanization enabled. Current cached transcript ID 26 provides the same source input as the fresh slow runs; no cache reset is needed.
2. Start one generation in the extension and note the start time and whether subtitles/translation/pronunciation appear. Allow it to finish or produce an error if practical; if cancelling, record elapsed time and cancellation explicitly.
3. Report completion time, the stage shown during the wait, and any error. A screenshot is optional. The next agent can recover exact public job/run identifiers from local logs; the user need not copy private logs or provider keys.
4. Agent reads local-only `backend.ai_request_started`/`backend.ai_request_finished` events and correlates worker PID/time with job/run/batch stage events. Separate preflight delay, SDK wait, post-SDK processing, queue admission and chained romanization. Do not label the entire time tokenization CPU work.

User observations:

- Video/settings:
- Start/completion or cancellation time:
- Stage and visible partial results:
- Error/reproduction details:

#### Deferred controlled comparison

Baseline: the saved immutable cached transcript and one fixed analysis batch, current model/options, accepted-base versus delivery request bodies verified offline. Procedure: only after explicit paid-evaluation authorization, run a small counterbalanced same-input comparison with recorded effective model/effort/service tier, request/token usage, preflight/SDK duration and validation outcome. Start with two requests total to check instrumentation before authorizing repeats; do not infer a distribution from one pair. Resources: provider credentials/quota and isolated local replay that cannot publish tracks or affect billing reservations. Acceptance: identify a repeatable timing difference attributable to one controlled variable with unchanged source fidelity; any model/effort adoption additionally requires user-approved quality criteria. No paid run, model/effort alternative or quality experiment has been started. Historical baseline configuration is unknown, so recreating it requires an explicitly agreed assumption.

Acceptance remains open due to the user's latency report. Before resuming, inspect current checkout, runtime, this note, packet and changes, preserving other task ownership. Reproduce from exact job/run events. Correlate request diagnostics with stage events by PID/time. Distinguish SDK duration from pure network time. If a code defect is demonstrated, delegate a bounded repair to Luna/xhigh, independently review consequential work, run affected tests and harness, and obtain user acceptance. Do not automatically merge or run paid comparison experiments.

## Slowdown worker and review record

Work mode: Brain / Worker. Owning chunk: 05/06/08 delivery, acceptance open.

### Read-only packet TR-A

- Role: independent diagnostic reviewer; no implementation authority.
- Objective: identify a demonstrated change in request payload, prompt/schema, retry behavior or local critical path that explains slower analysis/romanization after delivery.
- Scope: compare accepted base 75dbd046f8ef6b71226db85ee271919421dae60b with reviewed code c180b2b9ccd1337671ad2933f79ea46a43b4263f. Inspect current runner, provider, agents, validators, SDK caller and existing tests. Read governing root/backend instructions and relevant skills.
- Reproduction: same video yAbMYLPdyKI historically completed 2026-09-08 in 109.739 seconds; analysis batches 26.333/33.385/41.508 seconds. Fresh runtime 2026-09-09 analysis batches 4.962/42.008/69.300/108.705 seconds; no recorded validation retries in first fresh run. Root separately owns runtime/log/input comparison.
- Contracts: preserve identity, publication, cancellation, native timing, model/effort, provider budgets and admission. Distinguish causal evidence from hypotheses.
- Checkout: C:\transcribed-subtitle-extension, codex/learning-queue-audio-05-06-08. Allowed writes: none. Other task owns existing plan/document cleanup. Do not restore deleted notes.
- Checks: read-only Git comparisons and existing offline tests if necessary; no live providers, new generation, UI automation, dependencies or configuration changes.
- Exclusions: no implementation, history operations, checkout switches, staging, further delegation, broad optimization or model changes.
- User acceptance: pending; actual UI testing belongs to user. Clinical acceptance not applicable.
- Return: concise evidence with exact relevant files/lines, plausible causes ruled in/out, smallest justified repair or missing measurement. Send completion report to Brain; do not poll.

### Builder packet TR-B: expose the missing request timing

- Role: Builder / Worker. Model gpt-5.6-luna; reasoning xhigh. This is diagnosis support, not a claimed speed fix.
- Objective: distinguish preflight/admission time from SDK call time for each attempted AI request, without changing request behavior. Add regression evidence that normal analysis and romanization payloads are unchanged by the runner.
- Reproduction: stage logs show 42–109 second analysis calls but cannot isolate local work versus SDK/provider wait. TR-A independently found byte-identical old/new SDK HTTP bodies with offline fakes. Existing 92 runner/provider/validator tests passed in 1.62 seconds.
- Context: read root/backend AGENTS, relevant AI/Laravel skills, docs/OBSERVABILITY.md, AiRequestRunner, current HTTP-fake tests. Use Boost search-docs and Context7 for relevant SDK/framework APIs.
- Allowed writes: app/backend/app/Services/TranslationAnalysis/AiRequestRunner.php; app/backend/tests/Unit/AiRequestRunnerTest.php; one new test file under app/backend/tests/Unit only if necessary. Checkout C:\transcribed-subtitle-extension on codex/learning-queue-audio-05-06-08. Brain owns notes; independent reviewer read-only; another task owns preexisting documentation cleanup.
- Implementation: minimal structured started/finished request diagnostics around current preflight and prompt/toArray boundaries. Include PID (correlates existing stage job/run/batch logs), request ordinal within operation, agent, outcome and monotonic timing in milliseconds. Preserve original exception and result behavior. Ensure admission rejection/cancellation before SDK are distinguishable from SDK failure/success. Do not emit raw input/output/text, URLs, secrets, exception messages, headers, model responses or arbitrary context. No new framework, database writes, request option changes, retries, sleeps, model/effort/config changes, performance thresholds or broad abstractions. Prefer simple existing Log facade and monotonic clock.
- Invariants: same one admission per SDK call, callbacks and budgets unchanged, no request after cancellation, no new failure caused by unavailable diagnostics; no changed schema/public contracts. Timings describe SDK duration, not falsely pure network/recognition time.
- Non-UI checks: focused runner test; add deterministic timing/outcome/privacy coverage with fakes, success/provider failure/preflight rejection. Verify normal HTTP payload equals direct agent invocation for analysis/romanization where useful. Run Pint only assigned dirty PHP and exact changed tests. No live calls or infrastructure mutations.
- User acceptance: next user-initiated same-video generation supplies actual runtime timing; UI is user-owned, still outstanding. Clinical N/A.
- Restrictions: no Git mutations/history operations/checkouts, delegation, UI automation, paid generation, unrelated edits or shared docs. Return bounded blocker if scope must expand.
- Return: files/diff purpose, exact test commands/results, self-review, diagnostic event fields and limitations. Stop after completion report. Brain handles integration, independent review and harness.

#### TR-B completion

Worker `/root/builder_tokenizing_diagnostics` completed the two allowed files. Added `backend.ai_request_started` and `backend.ai_request_finished`, worker PID, request ordinal, agent, outcome and preflight/SDK/total durations. No provider payloads or messages logged. Tests include deterministic success/failure/cancellation/privacy and direct-agent HTTP payload parity for analysis/romanization. Worker reports 76 tests / 266 assertions passed for AiRequestRunnerTest and CueEnrichmentServiceTest, Pint and diff check passed; no live requests or Git operations.

### Independent review TR-C

Reviewer `/root/review_prep` owns read-only review of returned TR-B two-file diff. Objective: verify timing boundaries are accurately named, instrumentation cannot change callback/admission/deadline/exception/result behavior, privacy allowlist and SDK request parity hold, and implementation is proportionate. No edits, Git mutations, delegation, UI or live calls. Review current diff and run focused offline test if needed. Return consolidated actionable findings with exact evidence, or approval with limits. Root owns integration/harness/docs. User latency acceptance stays open even if diagnostics pass review.

#### TR-C completion

Approved with no blocking findings. Original callback, budget, encoding, admission, timeout, SDK and post-deadline ordering and exception mapping are preserved. Attempt ordinal is diagnostic only. Fixed log allowlist and swallowed logging failures preserve privacy and behavior. `sdk_ms` includes prompt/toArray and existing exception mapping on failures; it is not pure network time. `preflight_ms` includes started-log overhead; total ends before finished-log emission. SDK exceptions log sdk_failure even when deadline expires; deadline_exceeded identifies late successful SDK return. Reviewer inspected new timing/privacy/cancellation/parity tests and did not duplicate the concurrently running root harness. Root self-review agrees; no speculative speed fix or model change is included.

## Initial runtime observation

Date: 2026-09-09. Work mode: Brain / Worker. Acceptance remains open.

User reported the pipeline stuck or substantially slower at tokenizing. This note records read-only production-like local evidence and a local runtime restart, not a demonstrated code repair or a performance comparison.

### Observed run

- Job: `88939526-471e-47c0-903a-c95f95d959bf`, database ID 40.
- Run: `f9a775ea-b9b9-4c50-afea-0a8fc91eefca`.
- Video: `M8vDwlHigJA`; detected Punjabi to English; Pro.
- Branch: `codex/learning-queue-audio-05-06-08`; HEAD at inspection `753d0a9`. Application/contracts code matches reviewed `c180b2b` (subsequent commits were documentation).
- Evidence: exact-run Postgres subtitle_job_events via Boost read-only queries, local structured logs, runtime inspection and Windows process creation times. No raw cues, provider payloads or secrets were copied.

Times below are UTC:

| Event | Evidence |
| --- | --- |
| Acquisition | 22:29:47–22:29:53, 6.452 seconds. |
| Audio preparation | 0.885 seconds. |
| Transcription | Completed 22:30:12, 18.263 seconds. |
| Analysis batch 2 | 22:30:12–22:30:51, 38.569 seconds. |
| Analysis batch 0 | 22:30:17–22:31:53, 96.206 seconds. |
| Analysis batch 1 | Started 22:30:23; logged missing_cues validation retry at 22:32:15, about 112 seconds later. |
| Initial account admission | Three lock_busy events, each with two-second release delay. All analysis batches started within about 11 seconds. |
| Romanization | Batch 2 completed in 34.517 seconds while the overall job still reported tokenizing. Batch 0 also started before cancellation. |
| Cancellation | User cancelled at 22:32:43. Remaining queue deliveries drained by 22:33:08. Job stayed cancelled; no completed track was published. |

The dominant observed delay was inside analysis work and a malformed-response retry. This was not a completely idle pipeline. These logs do not establish a before/after regression or isolate provider HTTP time from all local processing. No global admission exhaustion was observed in the inspected logs; absence of a log alone is not a complete request audit.

### Runtime issue and action

Existing queue workers were created at 10:45:04 America/Toronto, hours before the final code commit at 16:51:23. Laravel workers retain loaded application code, so the tested workers could contain old or mixed loaded definitions. Process age does not identify every loaded class or prove it caused the slow provider response.

After verifying no active jobs and empty reported queues, Brain ran the existing local launcher with `-SkipDocker -SkipMigrate`. It reselected the normal local Postgres/Redis profile, signalled worker restart and restarted the local server and configured workers. No model, feature budget or worker-count tuning was introduced. No generation or other provider evaluation was started.

Verification after restart: one backend and 31 configured worker processes alive; no startup stderr; `php artisan subtitles:runtime-check --json --strict` passed; no active subtitle jobs and zero reported queue depths. This verifies runtime readiness only. Existing coarse queue metrics and Windows timeout limitations are not resolved by this check.

### Next step and boundaries

The user can retry the same video/settings once on the fresh runtime and report its job ID/result. Compare exact-run batch timings, validation retries and admission waits. A fresh live run can incur normal provider charges; Brain has not started one. Do not claim the restart fixed speed before observing it. Do not change models, scheduling or batch sizes based on this single cancelled sample.

If a code defect is reproduced, keep the existing delivery chunk active, save a bounded repair packet, use the currently authorized Luna/xhigh builder and independent review, and obtain renewed user acceptance. No implementation was changed for this observation. Concurrent documentation cleanup by another task was left untouched.
