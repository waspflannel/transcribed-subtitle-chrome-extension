# Subtitle Generation Pipeline Review

Created: 2026-06-09
Branch: `main` (clean at `496a3a3`)
Scope: the full generation pipeline — audio acquisition, audio preparation/optimization, transcription, Redis queue/worker orchestration, AI cue stages, and track finalization.
Companion report: `docs/architecture-review-report-2026-06-09.md` (whole-repo review; this document goes deeper on the pipeline only).

Files inspected end to end: `YouTubeAudioSource.php`, `ElevenLabsScribeAudioPreparer.php`, `ElevenLabsScribeTranscriptionService.php`, `ScribeTranscriptNormalizer.php`, `TimestampedSubtitleTrackGenerator.php`, `SubtitleGenerationPipeline.php`, `SubtitleCueBatchProcessor.php`, `SubtitleBatchDispatcher.php`, `SubtitleJobArtifactStore.php`, `SubtitleJobFailureHandler.php`, `SubtitleQueue.php`, `SubtitleTier.php`, `LimitSubtitleBatchConcurrency.php`, the queue job classes, `LaravelAiTranslationAnalysisProvider.php`, the `app/Ai/Agents/*` agents, `config/subtitles.php`, `config/ai.php`, and `routes/console.php`.

---

# How the pipeline actually executes

**Phase A — serial, one generation worker per job** (`ProcessSubtitleJob` on `subtitle-generation-{tier}`):
yt-dlp metadata probe → yt-dlp bestaudio download → billing reservation sync → ffmpeg to 16 kHz mono PCM → (optional) ElevenLabs Audio Isolation round-trip → ffmpeg to WAV → upload WAV to Scribe v2, one synchronous call, word timestamps → normalize words into cues (≤6 s, ≤84 chars, ≤14 words, 0.9 s pause break, sentence-final punctuation) → store transcript + draft-cue artifacts.

**Phase B — parallel fan-out**: one Laravel `Bus::batch` containing N tokenize jobs interleaved with N translate jobs (N = ceil(cues/10)) on `subtitle-batch-{tier}`, gated per user by tier (base 3, plus 8, pro 14, ultimate 20 concurrent AI calls) via a Redis counter with 10 s release-requeue (`LimitSubtitleBatchConcurrency`).

**Phase C/D — barriers**: when the *whole* analysis batch finishes → `PrepareSubtitleCuesAfterAnalysisBatches` → optional romanization fan-out → merge → optional full-card enrichment fan-out → `FinalizeSubtitleJob` writes the track, debits the ledger, deletes artifacts.

Models: ElevenLabs `scribe_v2` for transcription; `gpt-4o-mini` for all four AI cue stages (tokenization temp 0.1 / 5000 max tokens, translation 0.2 / 6000, romanization 0.1 / 5000, enrichment 0.2 / 8000, word card 0.2 / 1200).

---

# Verdict on the architecture

**The shape is right and should not be replaced.** Artifact-checkpointed stages + Laravel batch callbacks + run-id staleness guards is a simple, debuggable orchestration model, and the discipline around it is genuinely above average: atomic job claiming, loud validation of every provider response, reservation-then-debit billing that syncs to measured duration, batch-cancellation skips, per-stage tracing. A DAG engine (Temporal-style) or streaming per-cue pipeline would buy wall-clock time at a complexity cost that isn't justified at beta scale.

But there is one structural flaw and a set of unproven assumptions, detailed below.

---

# Does everything work as expected? Mostly — with one serious gap

## 1. A single transient provider error destroys the entire job, and nothing is salvaged

**This is the biggest problem in the pipeline.** Every job has `$tries = 0` / `$maxExceptions = 1`, and `SubtitleCueBatchProcessor.php:138-144` catches any Throwable and calls `failJob` — which marks the job failed **and deletes all artifacts** (`SubtitleJobFailureHandler.php:87-91`). An OpenAI 429 is explicitly mapped to a failure (`LaravelAiTranslationAnalysisProvider.php:187-197`), not a retry.

Concretely: you pay for a 60-minute Scribe transcription, 89 of 90 tokenization batches succeed, batch 90 hits one rate-limit blip → job fails → user retries → the *entire* pipeline re-runs from yt-dlp, paying for transcription again. The split-retry exists for *invalid model output*, but there is no retry for *transient transport errors* (429, 5xx, timeout) anywhere — not on batch jobs, not on the Scribe call.

**Fix (small and surgical):** `tries = 3` with backoff on batch jobs, retrying only the transient exception classes (429/5xx/timeout) while keeping validation failures loud. This converts the worst cost/reliability failure mode into a non-event without compromising the "loud failure" philosophy — a 429 is not a programming error, it is weather.

## 2. Global provider rate is unmanaged

Per-user caps exist, but 22 batch workers × several users can exceed org-level OpenAI TPM/RPM — and per finding #1 each rejection kills a paying job. A `RateLimited` job middleware backed by one global Redis limiter (alongside the existing per-user one) is cheap pre-launch insurance.

## 3. Progress is theater during the longest phase

`progress_percent` jumps 5→20→35→50→65→78→90→95 hardcoded. Real batch progress is already computed — `recordBatchProgress` fires on every batch callback (`SubtitleBatchDispatcher.php:103-105`) — it is just never written to the job row. A base-tier 60-minute video sits at "65%" for the better part of ten minutes. Mapping `$batch->progress()` into the 65–90 band (throttled writes) is nearly free and materially improves perceived speed.

## 4. A leaked concurrency slot can wedge a user for 30 minutes

The per-user counter is released in a `finally` (`LimitSubtitleBatchConcurrency.php:40-44`), which is correct — until a worker is SIGKILLed (OOM, deploy) mid-AI-call. Then the slot leaks until the counter TTL (`subtitles.tiers.counter_seconds` = 1800) expires, and a base user with 3 leaked slots has every batch job release-looping for up to 30 minutes. Consider a TTL closer to ~2× the batch job timeout (300 s → ~600 s).

## 5. Dead diarization branch

The normalizer splits cues on speaker change (`ScribeTranscriptNormalizer.php:195-199`), but the request hardcodes `diarize => 'false'` (`ElevenLabsScribeTranscriptionService.php:121-127`) — `speaker_id` never arrives. Either delete the branch or run the diarization experiment it was built for (interviews/podcasts would benefit from speaker-change cue boundaries).

## 6. The honest caveat the repo itself records

TD-010: no real provider-backed timing evidence exists for medium or near-limit videos. So "does it work as expected" is *proven only for short videos*. The tracing is fully built (`subtitles:metrics --json`, queue-wait events, budget checks); the highest-value next action is simply running 5/25/55-minute real videos per tier and reading the system's own metrics. Several recommendations below should be gated on that data.

---

# Is the parallelization working?

**Yes — the design is correct, and the cleverest part (interleaving tokenize+translate jobs in one batch so translation runs concurrently with tokenization) genuinely works.** Queue-priority ordering (`ultimate,pro,plus,base`) with base-guarantee worker groups is a sound anti-starvation design. But know where the ceilings are:

- **The per-user tier cap, not worker count, is the binding constraint.** Base = 3 concurrent AI calls. A 60-minute base-tier video produces ~90 tokenize + ~90 translate batches = 180 jobs drained 3-wide ≈ 60 waves ≈ 8–12 minutes for analysis alone, then romanization re-fans 3-wide again. That is a deliberate pricing lever — fine — but verify it against the base `near_limit` budget (1800 s) with real runs.
- **Generation workers are the system-wide throughput ceiling, and they spend their lives parked on I/O.** Phase A holds one of only ~5 generation workers (4 priority + 1 base-guarantee) for its entire wall-clock — including up to 10 minutes waiting on a synchronous Scribe HTTP response. Five long videos in flight = zero generation capacity left for anyone, while the workers do nothing but hold sockets. This is the scaling lever (see additions below).
- **Stage barriers serialize more than the data requires.** Romanization waits for the *whole* analysis batch — including translation, which it does not consume; enrichment waits for the merge. With the barrier model this is the right simplicity trade today; just be aware it is where wall-clock hides if speed is chased later.
- One small false-parallelism note: each batch job re-reads the **entire** draft-cues JSON artifact and chunks it in PHP (`SubtitleJobArtifactStore.php:110-127`) — ~180 full-transcript decodes per long job. Harmless now; store per-batch rows up front if traces ever show it mattering.

---

# Is the audio optimization valid?

**The ffmpeg normalization: yes, textbook.** 16 kHz mono `pcm_s16le` is exactly Scribe's native resolution; `-vn -ac 1 -ar 16000` is correct, and feeding the isolation API raw PCM with `file_format=pcm_s16le_16` avoids a server-side decode. No notes.

**The voice isolation step: unproven, expensive, and on by default — flip the default.** The repo's own tracker (TD-014) says no successful real isolation request has ever been captured, and the code visibly does not know what the API returns (it probes three JSON keys, data-URIs, then tries container decode and falls back to raw-PCM decode). Meanwhile it adds a full upload+download round-trip plus two extra ffmpeg passes to *every* job, and ElevenLabs bills isolation separately — for clean studio speech this may double provider cost and latency for zero WER gain. The fail-open design is well-built; the feature wearing it is unvalidated. Default it off until the A/B comparison (clean / noisy / music-heavy vs. normalized-only) shows it earns its cost — then consider enabling it *conditionally* (e.g., music-heavy content) rather than globally.

**One free speed win: upload size.** 16 kHz mono WAV is ~1.9 MB/min → ~115 MB for a 60-minute video, uploaded synchronously to Scribe (twice that traffic when isolation is on). Encoding to **FLAC** instead (`-c:a flac`) is bit-exact lossless, Scribe-supported, and roughly halves the bytes — minutes saved on long videos for a one-flag change.

---

# Would a different architecture provide better results?

No rewrite. Keep the artifact-checkpointed stage model. What should change is *within* it, in order of leverage:

1. **Transient retry policy** (finding #1) — small change, eliminates the worst failure mode.
2. **Real progress from batch callbacks** (finding #3) — nearly free UX.
3. **FLAC upload + isolation default-off pending evidence** — latency and cost.
4. **Global provider rate limiter** — pre-launch insurance.
5. **Stop parking generation workers on provider I/O.** Either use ElevenLabs' async/webhook mode if available for Scribe v2 (verify their current API), or split Phase A so the transcription wait does not occupy a worker. This multiplies long-video throughput without adding hardware. Do it when real traffic approaches ~5 concurrent generations, not before.
6. **Combined tokenize+romanize agent for romanization-enabled jobs** — removes an entire serial stage (fan-out + barrier + merge). The stages were split deliberately for validation strictness, so treat this as an experiment: it only wins if the combined agent's invalid-output rate stays low.
7. **The big product-level one: progressive track delivery.** Tokenized source-language subtitles are ready minutes before translation/romanization/cards. Serving a "transcript-ready" track immediately and patching layers in as they land would transform perceived speed ("subtitles in 90 seconds, translations follow") — and the extension *already* patches tracks in place for word cards, so the client pattern exists. It is a contract + polling change, so it deserves its own plan doc, but it is the single biggest experience win available.

**Deliberately not recommended: chunked parallel transcription** (splitting audio, parallel Scribe calls, stitching word timelines). It sounds obvious, but boundary stitching risks the exact timing/accuracy quality being sold, and there is no evidence yet that Scribe latency on 60-minute files is actually the dominant cost. Revisit only if TD-010 metrics prove it.

---

# What to add for quality/accuracy

- **A WER/segmentation benchmark harness.** Manual findings exist in `docs/experiments/transcription-quality/`; formalize a small per-language reference set re-run on any prep/model change. Every audio decision above (isolation, loudness, diarization) should be settled by this, not by intuition.
- **Loudness normalization experiment** (`ffmpeg loudnorm`) for quiet/clipped sources — cheap, sometimes meaningful WER gains, benchmark-gated.
- **Per-script cue segmentation tuning.** The `MAX_CUE_WORDS = 14` cap operates on Scribe "words", which for CJK arrive near-morpheme-level *before* the de-spacing step — so no-space-script cues may be systematically short/choppy. Pull cue-length distributions per language from stored artifacts and check; if confirmed, switch CJK to character-budget-driven boundaries.
- **Model tiering for translation.** `gpt-4o-mini` everywhere is the right call for tokenization/romanization (mechanical tasks, strict validators), but translation is the most user-visible quality surface and is cheap per-cue — running it on a stronger model is the highest quality-per-dollar upgrade available. Also note independent 10-cue translation batches can drift on terminology across a video (prev/next context helps locally, not globally); a per-job glossary of recurring terms passed into each batch is the standard mitigation if users report inconsistency.
- **Scribe options worth verifying against the current API:** diarization (see finding #5) and any keyterm/context-biasing parameters Scribe v2 exposes.
- **Operational, not architectural, but it will bite first:** yt-dlp from datacenter IPs is the #1 real-world acquisition failure (YouTube bot-check / "sign in to confirm" errors). Before paid launch, have a tested answer — PO-token provider, residential proxy, or cookies strategy — and a runbook entry, because no amount of pipeline elegance survives acquisition failing at the front door.

---

# Bottom line

The architecture is sound and the engineering hygiene around it is genuinely strong — validation, billing, tracing, and staleness handling are better than most production pipelines. The parallelization works as designed; its limits are deliberate pricing levers plus one real ceiling (generation workers parked on provider I/O). The two things to act on first:

1. **The transient-retry policy** — the current worst failure mode, trivially fixable.
2. **Run the medium/near-limit timing matrix** the tracer was built to answer (TD-010) — most other decisions in this document should hang off that data.
