# Subtitle Generation Timing Analysis

**Window:** last 90 days
**Sample:** 17 completed `pro`-tier jobs, ~62.5 generated minutes
**Source:** `subtitle_job_events` rows, scoped to each job's completing `run_id`

## Headline numbers

| metric | value |
|---|---|
| completed jobs | 17 |
| total job.completed wall time | 1,102.2s |
| **avg per-job wall time** | **64.8s** |
| p50 per-job wall time | 56.0s |
| p95 per-job wall time | 178.9s |
| p95 queue-wait spike (within any job) | 101.1s |

## Per-stage wall-clock (per job)

`stage.started` -> `stage.completed` for the completing run. Queue-wait column sums every `queue.wait_observed` event tagged with that stage for the same job.

| stage | jobs | avg | p50 | p95 | avg queue wait | % of total job time |
|---|---|---:|---:|---:|---:|---:|
| transcribing (ElevenLabs Scribe, 1 call) | 17 | **17.8s** | 16.0s | 65.0s | 0.0s | **27.5%** |
| tokenizing (OpenAI batched) | 17 | **15.2s** | 16.0s | 26.0s | **30.6s** | **23.4%** |
| translating (OpenAI batched) | 16 | **12.8s** | 14.0s | 27.0s | **34.9s** | **18.5%** |
| romanizing (OpenAI batched) | 14 | 9.2s | 8.0s | 18.0s | 10.5s | 11.7% |
| acquiring-audio (YouTube) | 17 | 7.2s | 7.0s | 9.0s | 8.1s | 11.2% |
| optimizing-audio (FFmpeg prep) | 17 | 0.8s | 0.0s | 5.0s | 0.0s | 1.2% |
| assembling-analysis-results | 17 | <0.1s | - | - | 2.3s | <0.1% |
| merging-romanization-results | 14 | <0.1s | - | - | 2.0s | <0.1% |
| finalizing | 17 | 0.1s | - | - | <0.1s | 0.2% |

The four LLM-bound stages (transcribing + tokenizing + translating + romanizing) account for **~81% of total wall time**.

## Per-batch worker time (queue.processed)

What each batch actually spends inside the LLM call, independent of queueing.

| job class | n | avg | p50 | p95 | max |
|---|---:|---:|---:|---:|---:|
| `TokenizeSubtitleCueBatch` | 169 | 3.58s | 3.58s | 7.74s | 20.79s |
| `ProcessSubtitleJob` (orchestrator) | 23 | 26.21s | 24.13s | 33.90s | 74.66s |
| `RomanizeSubtitleCueBatch` | 102 | 5.63s | 5.82s | 9.09s | 11.29s |
| `TranslateSubtitleCueBatch` | 173 | 2.36s | 2.59s | 4.43s | 6.75s |
| `PrepareSubtitleCuesAfterAnalysisBatches` | 20 | 0.08s | 0.06s | 0.13s | 0.29s |
| `FinalizeSubtitleJob` | 17 | 0.09s | 0.09s | 0.11s | 0.12s |
| `MergeSubtitleCuesAfterRomanizationBatches` | 14 | 0.06s | 0.05s | 0.10s | 0.13s |

The translation batches spend **2.4s in the LLM but wait 34.9s** in the queue. Tokenizing batches spend **3.6s in the LLM but wait 30.6s**. The bottleneck is queue throttling, not model latency.

## Key findings

1. **Transcribing is the single biggest LLM call (~28% of total job time).** ElevenLabs Scribe processes the entire audio in one non-batched request. p95 spikes to 65s. It is the unparallelizable ceiling of the current pipeline.
2. **The three batched LLM stages (tokenizing + translating + romanizing) consume ~54% of total wall time** even though their actual LLM work is small. They compete for the same throttled concurrency slot, so they wait far longer than they execute. The trace for `eef2b2ff-94df-4f25-821a-91bc14a1866c` shows `queue.concurrency_delayed` events firing on the tokenizing batch (e.g. 12s waits before workers pick them up).
3. **The throttling is by design, but the wait is quantized by a fixed 10s re-release delay.** `app/backend/app/Jobs/Middleware/LimitSubtitleBatchConcurrency.php:30` caps concurrent AI batch jobs per user via `SubtitleTier::batchConcurrency()`. When a batch is rejected at the cap, `releaseQueuedJob()` puts it back with `SubtitleTier::concurrencyReleaseDelaySeconds()` — a **fixed 10s sleep** (`release_delay_seconds`, `app/backend/config/subtitles.php:55`) — even though slots turn over every 2–4s (translate batches hold one for ~2.4s). The observed 30–35s avg queue waits are ~3 release cycles, not raw cap starvation. The delay quantum, not the cap, is the dominant cost for a single user's job.
4. **Acquiring-audio is the only meaningful non-LLM I/O cost** (~7s avg, 11% of total). Everything else is sub-second.
5. **The p95 outliers (178s jobs) are not LLM stages** — they are jobs where audio-acquire waited 101s before download even started, i.e. queue depth on the generation queue, not the work itself. Note `ProcessSubtitleJob` holds a generation worker for the full acquire→optimize→transcribe span (26s avg, 75s max) and the pool is only 4 priority + 1 base-guarantee workers (`app/backend/config/subtitles.php:32`).
6. **Stats caveat:** n=17 jobs, so p95 here is effectively "the max observed." Good for direction-finding, not for guardrail thresholds.

## Recommended wins (ranked by impact / effort)

Each win includes an implementation guide for the worker agent picking it up: where the change lives, the path to follow, and the traps to avoid. Keep code changes minimal and let the existing telemetry verify the result (`php artisan subtitles:metrics`, `queue.wait_observed`, `queue.concurrency_delayed`).

### 1. Cut the concurrency release delay — env-var change, do first

**Why:** finding #3 — rejected batches sleep a fixed 10s per rejection while slots free every 2–4s. Shrinking the delay collapses the 30–35s queue waits without touching the concurrency cap, so there is **zero added 429 risk** (the number of concurrent OpenAI calls is unchanged; only the re-check frequency rises).

**Where:** `release_delay_seconds` at `app/backend/config/subtitles.php:55`, consumed by `SubtitleTier::concurrencyReleaseDelaySeconds()` and used in `LimitSubtitleBatchConcurrency::releaseQueuedJob()`.

**Guide:**
- Set `SUBTITLE_CONCURRENCY_RELEASE_DELAY_SECONDS=2`. Optionally add ±1s jitter at the `release()` call site to avoid re-check thundering herds on the cache lock.
- Safe with retry semantics: `SubtitleCueBatchJob` has `tries = 0` (unlimited attempts) and `maxExceptions = 3` — releases increment attempts but never count as exceptions, so more release cycles cannot fail a job.
- Each extra cycle costs one queue pop + one `SubtitleJob::find()` in the middleware. At current volumes that is noise; don't optimize it preemptively.

**Verify / watch:** `queue.concurrency_delayed` event *counts* will rise (more re-checks — expected, not a regression). The metric that matters is `queue.wait_observed` p50/p95 per stage — expect tokenize/translate waits to drop from ~30s toward single digits. Also confirm no growth in `lock_timeout` delay reasons (the 1s `block()` on the cache lock).

### 2. Raise the per-tier batch concurrency cap — pairs with #1

**Why:** a pro job dispatches ~20 batches (10 tokenize + 10 translate, interleaved) against a cap of 14 (`SUBTITLE_PRO_BATCH_CONCURRENCY`, `app/backend/config/subtitles.php:98`). Wave 1 runs immediately; wave 2 pays the release-delay quantum. Raising the cap shrinks wave 2; #1 makes wave 2 cheap. Do both — #3 alone underdelivers because of the quantization.

**Where:** env vars per tier in `app/backend/config/subtitles.php:64-98`; enforced by `LimitSubtitleBatchConcurrency` via `SubtitleTier::batchConcurrency()`.

**Guide:**
- Bump pro 14 → 20+ and plus 8 → 12 as a guarded experiment. Leave base alone (it shares the worker pool).
- Mind the two other governors before assuming the cap is the binding constraint: the **global** `RateLimited('subtitle-ai-batch')` middleware (300/min, `subtitles.enrichment.global_rate_limit_per_minute`) and the **worker pool** (`SUBTITLE_BATCH_PRIORITY_WORKERS`, default 20, `app/backend/config/subtitles.php:37`). A per-user cap above the worker count does nothing — raise workers in step if needed.

**Verify / watch:** OpenAI 429s (transient `SubtitleProcessingException` releases with `backoff [15, 60]` — watch for those backoff releases in traces), `provider.cost_estimated` unchanged (same total calls), and cross-user fairness on the shared batch workers.

### 3. Chain romanization per batch index — removes a whole stage barrier

**Why:** romanization only needs *its own batch's* tokenized output (`SubtitleCueBatchProcessor::romanizeCueBatch` reads `cueBatchResult(TOKENIZED_CUES, $batchIndex)`), yet today the pipeline waits for the **entire** analysis batch (all tokenize + all translate) before dispatching romanization as a second `Bus::batch`. Chaining `Romanize(N)` directly after `Tokenize(N)` hides the romanizing stage (~9.2s + 10.5s queue wait) behind translating and deletes one full queue hop (`assembling-analysis-results` → `dispatchRomanizationBatches`).

**Where:** `SubtitleGenerationPipeline::dispatchTokenizationAndTranslationBatches()` (`app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php:227`), `prepareCuesAfterCompletedAnalysisBatches()` (line 112), `SubtitleBatchDispatcher::dispatchAnalysis()`.

**Guide:**
- Decide romanization **at dispatch time**: `shouldRomanizeTranscript()` only inspects `sourceText`, which is identical between draft and tokenized cues — run it on `DRAFT_CUES` right after transcription instead of on tokenized output.
- Build the analysis batch with chains as members when romanizing: `Bus::batch([[new TokenizeSubtitleCueBatch(...), new RomanizeSubtitleCueBatch(...)], new TranslateSubtitleCueBatch(...), ...])`. Laravel runs each chain sequentially and the batch completes when all members (chains included) finish. Each chain link passes through `LimitSubtitleBatchConcurrency` independently — the cap still holds.
- Collapse the two completion paths: the single batch-completion callback now merges (romanized-or-tokenized) + translated and continues. `PrepareSubtitleCuesAfterAnalysisBatches`'s romanization-dispatch branch and `MergeSubtitleCuesAfterRomanizationBatches` become one job. `storeMergedCuesAndContinue()` already handles both shapes.
- **Traps:** stage/progress reporting — `markJobRunning($job, 'romanizing', 78)` no longer has a clean boundary since tokenizing/romanizing/translating overlap. Pick a display strategy (e.g. keep reporting `tokenizing` until analysis batch completes, or advance on first romanize start). Telemetry consumers of `stage.started`/`stage.completed` must tolerate overlapping stages — future timing analyses (this doc's method!) need to know stages overlap. Chain links must keep the queue/connection set in the `SubtitleCueBatchJob` constructor.

**Verify / watch:** feature tests around stage transitions and the batch-completion path; a trace (`php artisan subtitles:trace <job>`) should show romanize batches starting while translate batches are still running, and no `merging-romanization-results` hop.

### 4. Combine tokenize + translate into one prompt per batch — good win, real rework

**Why:** halves round trips and slot contention for the two biggest batched stages. Realistic 30–40% off their combined wall time — though note #1 + #2 already remove most of the *queue-wait* component; this then attacks the remaining LLM time and per-call overhead.

**Where:** `LaravelAiTranslationAnalysisProvider` (`app/backend/app/Services/TranslationAnalysis/`), `SubtitleCueBatchProcessor::tokenizeCueBatch()`/`translateCueBatch()`, dispatch loop in `SubtitleGenerationPipeline::dispatchTokenizationAndTranslationBatches()`.

**Guide:**
- Keep the downstream contract stable: have the merged job **write both artifacts** (`TOKENIZED_CUES` and `TRANSLATED_CUES` batch results) from one LLM call, so romanization's per-batch dependency on `TOKENIZED_CUES` and the merge step are untouched.
- Preserve the same-language path: when `translationRequested()` is false, dispatch the tokenize-only prompt as today.
- **The main cost is the fallback logic, not the prompt.** `tokenizeBatch()` has reprompt + split-and-retry recovery (`LaravelAiTranslationAnalysisProvider.php:48-84`); a merged prompt roughly doubles output tokens per call, which raises truncation/invalid-output rates, and the split-retry must recover *both* halves of the merged output. Budget most of the effort here and in its tests.
- If #3 (chaining) lands first, chain `Romanize(N)` onto the merged job instead of onto tokenize.

**Verify / watch:** invalid-output/reprompt/split event rates before vs after (they exist in traces); per-batch `queue.processed` duration will rise (~2.4s + 3.6s → one longer call) — that's fine, total wall drops. Diff output quality on a few known jobs.

### 5. Size batches by characters, not cue count — small, cheap

**Why:** `cue_batch_size = 10` fixed (`app/backend/config/subtitles.php:147`) means per-call overhead (queue hop, slot claim, TTFT) is paid ~10× per stage while each call only does 2–4s of work. Fewer, larger, *uniform* batches cut contention proportionally at identical token cost. The 20.8s tokenize max suggests content-length outliers already exist; character budgeting fixes those too.

**Where:** `SubtitleJobArtifactStore` — `putCueCollection()` accepts `$batchSize`, and `cueBatch()`/`batchCount()` chunk with `array_chunk` (`app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php:110-160`).

**Guide:**
- Compute a batch plan at `putCueCollection()` time: pack cues greedily to a target of cumulative `sourceText` characters (start ~2× the current average batch, i.e. aim to halve batch count). Store explicit boundaries in the payload (e.g. `batchPlan: [[start,end], ...]`) rather than a uniform size; keep the existing `batchSize` fallback so previously-written artifacts still parse.
- All batch loops already derive counts from `batchCount()` (`SubtitleGenerationPipeline.php:240,263,279`), so the change propagates automatically.
- **Trap:** larger batches → more output per call → more split-retries. Tune the character target against the reprompt/split rate; the split-retry path is the safety net, not the steady state.

**Verify / watch:** batch counts per job drop ~half; `queue.processed` p95 for batch jobs stays under ~10s; split/reprompt rates flat.

### 6. Chunked parallel transcription — attacks the ceiling, most invasive

**Why:** transcribing is the single biggest stage (~28%, p95 65s) and one non-batched Scribe call. Chunking drops the ceiling from `full_audio` to `max(chunk)` and lets tokenization start per chunk. Realistic 30–50% off average job time, but the highest-risk change in this list. Do it only if #1–#5 don't meet the latency budget.

**Where:** `ElevenLabsScribeTranscriptionService` (`prepareAudio()` / `transcribePreparedAudio()`), invoked from `SubtitleGenerationPipeline::transcribeSourceAudioAndDispatchAnalysis()`.

**Guide:**
- Chunk in `prepareAudio()` with FFmpeg: split on silence near fixed intervals (e.g. target 90–120s chunks, snap to nearest silence, small overlap ~1s) so no word straddles a hard cut.
- Fan chunks out (parallel HTTP or per-chunk jobs on the batch queue), then merge: offset each chunk's word timestamps by chunk start; de-duplicate words in the overlap window; concatenate. Keep the single-call path for audio under ~3–4 minutes — no benefit, all risk.
- Language detection (`recordDetectedSourceLanguage`) must come from one canonical chunk (the first) or a majority vote; per-chunk detection can disagree.
- `SubtitleProviderCostRecorder::recordTranscription()` is duration-based — record once with total duration, not per chunk.
- **Trap:** cue drafting (`TimestampedSubtitleTrackGenerator::draftCues`) consumes the merged transcript; boundary artifacts (split sentences, duplicated words) surface directly as bad cues. The merge logic needs dedicated unit tests with synthetic overlapping transcripts before touching the pipeline.

**Verify / watch:** transcribing stage p50/p95; diff generated cues on a few reference videos against the single-call path (goldens); Scribe cost per job unchanged.

### 7. Deterministic romanization for scriptable languages — deletes a stage for some languages

**Why:** Korean, Cyrillic, Greek, Devanagari and similar scripts have algorithmic transliterations — no LLM needed. For those languages the romanizing stage (~9.2s + queue waits) becomes a synchronous ~0s transform. Japanese/Chinese/unvocalized Arabic still need the LLM (readings are ambiguous).

**Where:** decision point in `SubtitleGenerationPipeline::prepareCuesAfterCompletedAnalysisBatches()` (or at dispatch if #3 landed); transform beside `SubtitleCueBatchProcessor::romanizeCueBatch()`.

**Guide:**
- Route by `effectiveSourceLanguage()` against a config-driven allowlist of deterministically-scriptable languages. On match, fill per-token `romanization` via PHP `intl` `Transliterator` (`Any-Latin; Latin-ASCII` or per-script rules) synchronously and write the `ROMANIZED_CUES` artifact in the same shape the LLM path produces — downstream merge stays identical.
- **Quality gate per language before enabling:** ICU output is not always the product-preferred scheme (e.g. ICU Hangul→Latin does not apply Revised-Romanization sound-change rules — 신라 → "sinla" not "silla"). This is a language-learning product; validate ICU output against existing LLM romanizations on sample jobs per language, and only allowlist languages that pass. Start with the safe ones (Cyrillic, Greek).
- Check whether the tokenizer prompt already returns per-token readings for Japanese — if so, even Japanese romanization becomes a deterministic post-process on the tokenized artifact.

**Verify / watch:** romanizing stage vanishes from traces for allowlisted languages; zero OpenAI calls for that stage; spot-check romanization quality per enabled language.

### 8. Cache transcripts by video — makes repeat jobs near-free

**Why:** `persistGeneratedSubtitleTrack()` deletes all artifacts on completion (`SubtitleGenerationPipeline.php:211`), so re-generating the same YouTube video redoes acquire + optimize + transcribe (~45% of wall time). Transcripts are user-independent and keyed naturally by video. For popular videos this is the only idea here that can cut ~100% of a job's heavy stages.

**Where:** new lookup before `audioSource->acquire()` in `transcribeSourceAudioAndDispatchAnalysis()`; new storage keyed separately from per-job artifacts.

**Guide:**
- Start with **transcript-only** caching: key `(youtube_video_id, requested_source_language, scribe_model_version)`, store transcript payload + detected language + audio duration. On hit: skip acquire/optimize/transcribe, still run `syncJobReservationToActualDuration` (cached duration) and `recordDetectedSourceLanguage`, then dispatch analysis as normal. On miss: populate after a successful transcription.
- Billing stays untouched — the user is debited for the completed job either way; only the provider cost disappears. Skip `recordTranscription()` on cache hits so cost telemetry stays honest.
- Needs a TTL/size policy (transcripts are small JSON — generous TTL is fine) and version-bump invalidation (key includes model version; add prompt version if tokenized cues get cached later).
- Tokenized/romanized cues are also user-independent (`+ tokenizer prompt version` in the key) and translations are `(+ target_language)` — but layer those only after transcript caching proves out.
- **Decide the product/privacy stance explicitly** (transcripts shared across users; content is public YouTube audio, but make it a deliberate call, not an accident of implementation).

**Verify / watch:** cache-hit jobs should complete in roughly `analysis + finalize` time (~25–30s at current numbers, less after #1–#4); hit-rate telemetry to judge whether layering tokenization caching is worth it.

### 9. Progressive delivery — optimize time-to-first-cue instead of time-to-completion

**Why:** users watch from t=0 and don't need minute 8's subtitles when the video starts. Rendering source-text cues as soon as transcription lands (progress ~50–65%) and filling translations per batch makes a 65s job *feel* like ~25s with no LLM or infra changes. Product-level scope: API shape + extension changes.

**Guide (outline — scope this as its own project):**
- Per-batch artifacts already exist (`SubtitleJobArtifactStore` batch results) and `recordBatchProgress` already fires per batch — the backend has the data; it needs an endpoint exposing partial cues for a running job, and the extension needs to render draft cues immediately and patch in translations/romanizations as batches land.
- Dispatch order already follows `batchIndex` (cue order); if worker scrambling makes late cues land first, prioritize batch 0 rather than engineering strict ordering.
- Define "time-to-first-cue" as a first-class metric in telemetry before building, so the win is measurable.

### 10. Pipelined audio acquire — small win, low risk

Stream the file to Scribe as `yt-dlp` downloads, instead of waiting for the full file. Realistic 3–5s off the audio stage. Diminishing returns vs the LLM work — only worth it opportunistically.

### 11. Investigate the audio-acquire queue spike (p95 outlier)

One job waited 101s before download started. That's queue depth, not the download. Check `subtitles:runtime` for backlog on the generation queue during that window. Note the structural cause candidate: `ProcessSubtitleJob` holds a generation worker for the whole acquire→optimize→transcribe span with only 4+1 workers (`app/backend/config/subtitles.php:32`) — a burst of 5 jobs necessarily queues. Cheapest fix is likely `SUBTITLE_GENERATION_PRIORITY_WORKERS` tuning; #6 (chunking) also shortens the span each job holds a worker.

## Checked and rejected

- **Shrink the per-batch prompt context / prompt-cache the transcript prefix.** Checked: the prompts already send only ±1 neighbor cue per cue (`tokenizationCueInput` / `translationCueInput` in `LaravelAiTranslationAnalysisProvider.php:469`), not the full transcript. The full `allCues` collection is loaded PHP-side purely for neighbor lookup (plus an O(n) `cuePosition` scan per cue) — negligible at current sizes. No token-cost or latency win here; don't chase it.

## Suggested sequencing

1. **#1 (release delay) today** — env change, reversible, no risk. Re-run this analysis after a week of data before committing to anything heavier.
2. **#2 (cap bump)** as a guarded experiment alongside #1, with `provider.cost_estimated` + 429/backoff-release counters as guardrails.
3. **#3 (romanize chaining)** — contained code change, removes a stage barrier and a queue hop.
4. **#5 (character batch sizing)**, then **#4 (merged prompt)** — #4 only with budget for the fallback/retry rework.
5. **#6 (chunked transcription)** only if the above don't meet the latency budget.
6. **#7 (deterministic romanization)** and **#8 (transcript caching)** are independent of the queue work — schedule opportunistically; #8 has the highest ceiling for repeat traffic.
7. **#9 (progressive delivery)** as a product decision — biggest perceived-latency win, biggest scope.
8. **#10/#11** are nice-to-haves once the LLM work is right.

## How this analysis was produced

```bash
# High-level p50/p95 + budget roll-up
php artisan subtitles:metrics --days=90

# Slow events, useful for outlier triage
php artisan subtitles:slow --limit=200

# Per-job trace for one completed run
php artisan subtitles:trace eef2b2ff-94df-4f25-821a-91bc14a1866c

# Per-stage wall-clock breakdown (custom one-off in temp)
# Reads subtitle_job_events scoped to each job's completing run_id.
```

The per-stage table above was produced by joining `stage.started` and `stage.completed` timestamps per `(subtitle_job_id, stage)` for the run that produced the `job.completed` event, then summing all `queue.wait_observed` events tagged with the same stage. Run files: `analyze_subtitle_stages_v3.php` (analysis is one-off, no need to keep).
