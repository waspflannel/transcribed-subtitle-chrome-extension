# Plan: Track B — CJK / No-Space Tokenization Quality

Status: active (Steps 1–2 implemented; Steps 3–5 gated on harness results)
Owner: agent
Created: 2026-06-18
Last updated: 2026-06-18

> This is a **solution-architecture / investigation** document, not a code listing. It describes
> the problem, the option space, and a recommended path; the implementing engineer ("the worker")
> owns writing the code. Snippets here are illustrative (a fixture shape, prompt-example text, a
> CLI sketch), not prescriptions to copy. Every claim about current behavior is grounded in code
> with file paths and line numbers verified on 2026-06-18 on branch
> `codex/architecture-review-cleanup`.

## Goal

Improve the *quality* of learner-clickable word boundaries for CJK and other no-space
languages (Chinese/Mandarin, Japanese, Thai) in the YouTube subtitle pipeline. "Quality" here
means pedagogically correct segmentation — boundaries a learner can tap to get a useful word
card — as distinct from "fragility" (jobs failing or producing tokenless cues, which is Track A).

The output of acting on this doc should be: (1) a way to *measure* boundary quality before
spending money tuning it, and (2) a concrete, sequenced set of changes that measurably raise it.

## Scope

- In scope:
  - The tokenization boundary decision: `CueTokenizationAgent` (model + prompt) and
    `LearningTokenOutputValidator`.
  - Options to raise quality: model selection, prompt engineering, deterministic morphological
    segmentation (MeCab/Sudachi/jieba/Thai dictionaries), and how a non-PHP segmenter would be
    invoked from this Laravel/Redis backend.
  - An evaluation harness to quantify quality and attribute failures to model vs prompt vs
    upstream transcription.
- Out of scope (flagged, not solved here):
  - Upstream transcription accuracy (the Scribe stage). Some observed "bad tokenization" is
    actually faithful segmentation of a wrong transcript; tokenization cannot repair it. Scoped
    lightly in Option (d).
  - Public API / contract shape changes, extension overlay redesign, queue infrastructure.

## TL;DR Recommendation

1. **Build the eval harness first** (Option 3). It is cheap, has no provider dependency for the
   metric itself, and tells you whether the model or the prompt is the bottleneck *before* you
   spend on a bigger model or a sidecar. Without it, every other lever is a guess.
2. **Then do the prompt + model A/B** (Options 2a/2b) against the harness — fastest signal, near-zero
   integration risk. Add Chinese and Thai few-shot examples and negative examples for the three
   observed failure modes; A/B the current `gpt-5.4-mini` against a stronger tier.
3. **If the harness shows the LLM still misses a quality bar, adopt deterministic segmentation as a
   guardrail/validator** (Option 2c, architecture **ii**) — re-introduce a linguistic gate using a
   dictionary segmenter via a small sidecar, not in-process PHP. This is the robust long-term fix
   and directly re-addresses the gap left when the previous morphology gate was deleted (see
   "Important history" below).
4. Separately, fix the **dead `OPENAI_TOKENIZATION_RETRY_MODEL` config** and the **leaked
   `OPENAI_API_KEY` in `app/backend/.env`** (see Open Questions / Decision Log).

---

## Current-State Findings

### Pipeline shape (where boundaries are decided)

The runtime path (per `ARCHITECTURE.md` lines 24, 28 and the completed cleanup plan
`docs/exec-plans/completed/2026-05-13-tokenization-pipeline-cleanup-refactor.md` lines 97–109):

```
ElevenLabs Scribe word timings
  -> ScribeTranscriptNormalizer builds timed segments + WebVTT (sourceText is produced here)
  -> draft cues
  -> CueTokenizationAgent (OpenAI structured output) chooses token boundaries
  -> LearningTokenOutputValidator validates STRUCTURE only
  -> overlay renders clickable tokens (or plain text if tokens == [])
```

Boundary quality is therefore a function of exactly four things: the **tokenization model**, the
**tokenization prompt**, the **(purely structural) validator**, and the **upstream transcript**.

### 1. The model

- `CueTokenizationAgent::model()` (`CueTokenizationAgent.php:47-50`) resolves the model from
  config key `ai.providers.openai.models.tokenization.default`.
- `app/backend/config/ai.php:24-27` maps that key to the `OPENAI_TOKENIZATION_MODEL` env var.
- `app/backend/.env:69` currently sets `OPENAI_TOKENIZATION_MODEL=gpt-5.4-mini` (the `*-mini`
  cheap tier). `.env.example:102` ships `gpt-4o-mini`. **The production tokenizer is a small/cheap
  model.**
- Agent attributes: `#[Temperature(0.1)]`, `#[MaxTokens(5000)]` (`CueTokenizationAgent.php:16-17`).
- **Dead config:** `app/backend/.env:70` sets `OPENAI_TOKENIZATION_RETRY_MODEL=gpt-5.4-mini`, but
  `grep` for `TOKENIZATION_RETRY_MODEL` / `retry_model` across `app/` and `config/` returns **zero
  hits**. The retry-model cascade was removed by the 2026-05-13 cleanup (see "Important history").
  This env key is now a no-op and is misleading.

### 2. The prompt

Full instructions live in `CueTokenizationAgent.php:24-44` (`instructions()`). Key facts:

- It gives **Japanese-only** few-shot examples (`CueTokenizationAgent.php:35-37`):
  ```
  Japanese examples:
  - Split か聞いてみた as か / 聞いて / みた. Never return か聞いてみた as one token.
  - Split みたいと as みたい / と. Never return いと.
  ```
- There are **zero Chinese examples, zero Thai examples**, and **no negative examples** for any of
  the three observed failure modes (orphan leading particle, truncated word, lost small っ).
- It already contains the right *general* rules: "For no-space scripts, choose meaningful words or
  short phrases rather than individual characters" (line 31); "Keep particles ... separate ... Do
  not attach a leading or trailing function word to a neighboring content word" (line 33); and an
  instruction to treat artifact spaces in no-space scripts as transcription artifacts (line 31).
- The per-cue input includes neighbor context: `tokenizationCueInput()` in
  `LaravelAiTranslationAnalysisProvider.php:291-302` adds `previousCueText` and `nextCueText`, plus
  `sourceLanguageName` (`tokenizationInput()` lines 271-284). So the model *does* know the language
  and surrounding cues.

### 3. The validator (structural only — confirmed)

`app/backend/app/Services/TranslationAnalysis/LearningTokenOutputValidator.php`. The full set of
checks in `validatedGeneratedTokens()` (lines 14-87):

- tokens is a non-empty array (lines 16-22);
- each token `index` equals its zero-based position (lines 36-41);
- token text cleans to non-empty (lines 43-50);
- non-lexical (punctuation-only) tokens are silently dropped and re-indexed
  (`isLexicalTokenText()` line 52-54, 103-106 — regex `/[\p{L}\p{N}\p{M}]/u`);
- each token's normalized text is a **contiguous in-order substring** of the source, enforced via
  `mb_strpos($sourceComparable, $tokenComparable, $searchOffset)` with an advancing offset
  (lines 65-74).

That advancing-offset substring check is the **only** semantic constraint, and it is purely about
*coverage/order*, not linguistic correctness. There is **no** check for: orphan particles,
truncated words, lost small kana, word/morpheme legality, dictionary membership, or even that
tokens cover the whole source (gaps between tokens are allowed). **Valid-but-bad segmentations
pass.** This is by design — see history.

**Critical constraint the validator enforces:** because every token must be a contiguous substring
of `sourceText` (via the `comparableText`/`normalizeTextForComparison` path, lines 98-124, which
only collapses whitespace and strips no-space artifact spaces), the tokenizer is **forbidden from
altering source characters**. It cannot insert, delete, or correct a character. Therefore any token
boundary the model returns is a *cut* of the exact source string.

### 4. Upstream transcript (`sourceText`)

`app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php` builds `sourceText`:

- Scribe word tokens are joined and whitespace-collapsed (`normalizeText()` lines 277-280).
- For no-space scripts, Scribe often emits character-level timed tokens, producing text like
  `日 本 語 を 勉 強`. `normalizeTranscriptText()` (lines 282-287) strips those artifact spaces with
  `NO_SPACE_ARTIFACT_BOUNDARY_PATTERN` (line 10) so display/source text becomes `日本語を勉強`.
  (The same pattern is duplicated in the validator at `LearningTokenOutputValidator.php:9` so the
  artifact-space collapse is symmetric between source and token comparison.)
- The normalizer does **not** and **cannot** correct transcription content — it only re-joins and
  re-spaces what Scribe heard.

### Attributing the two observed examples

| Observed overlay output | Diagnosis | Why |
| --- | --- | --- |
| `騒いでる / って / 寝返り / うて / ばっか` — `うて` looks like a broken split of 寝返り**打って** with the small っ lost | **Transcription fault** (not tokenization) | The validator forbids the tokenizer from inventing the っ or the 打 character. If the overlay shows `うて`, then `sourceText` literally contained `うて` (or `打って` was mis-heard as `うて`). Tokenization faithfully cut a wrong source. A bigger tokenization model or better prompt **cannot** fix this; only the Scribe stage can. Note the small-っ class of errors is a known artifact of character-level STT + the no-space artifact-space collapse, which can join/strip around small kana. |
| `り / 大事 / な / 友達 / へ / 素直 / に / ならなく` — orphan leading `り`, truncated `ならなく` | **Mostly tokenization fault** (with a possible transcription contribution) | If the source is e.g. `…なり大事な友達へ素直にならなくちゃ`, then: (a) the orphan `り` is a *boundary* error — the model split a content word and stranded a trailing/leading mora as its own token; (b) `ならなく` truncated from `ならなくちゃ`/`ならなくて` is a boundary error **iff** the missing characters are present in source (then the model under-covered), or a transcription error **iff** source was truncated. The orphan-`り` case in particular is a pure segmentation defect the LLM/prompt/segmenter can fix. |

**Rule of thumb for the team:** before blaming tokenization, check whether the offending
characters exist in the cue's `sourceText`. If the "correct" word's characters are not in the
source, it is a Scribe problem (Option d). If they are present but mis-cut, it is a tokenization
problem (Options a/b/c). The eval harness (Section 3) is designed to make this attribution
mechanical instead of manual.

### Important history (why there is no quality gate today)

This is **not** a greenfield problem — a quality gate existed and was deliberately removed:

- `docs/exec-plans/completed/2026-05-12-tokenization-quality-gate-upgrade.md` added a morphology
  quality gate that explicitly rejected `か聞いてみた` and `いと`, plus a **retry with a stronger
  model** via `OPENAI_TOKENIZATION_RETRY_MODEL` (that plan, lines 23-24).
- `docs/exec-plans/completed/2026-05-13-tokenization-pipeline-cleanup-refactor.md` then **removed**
  the local morphology tokenizer/gate and the retry-model cascade, reducing validation to
  "structural and boundary-safety checks only" (that plan, decision-log lines 151-152, completion
  notes line 168). Its own residual-risk note (line 171) states: *"The tokenizer agent can still
  choose low-quality but source-present boundaries because backend morphology gates are
  intentionally gone; product correctness now depends on prompt/schema quality plus retry for
  malformed or hallucinated output."*

So the *current* `tokenizeBatch()` "retry" (`LaravelAiTranslationAnalysisProvider.php:47-81`) is a
**batch-splitting** retry for malformed/structural failures — it re-sends smaller batches to the
**same** model with the **same** prompt. It does nothing for valid-but-bad boundaries. The 2026-06-18
problem statement is essentially the predicted consequence of that 2026-05-13 removal.

### Cost / batch context (for Option a sizing)

- Cues are tokenized in batches of `SUBTITLE_ENRICHMENT_CUE_BATCH_SIZE` (default **10**),
  `config/subtitles.php:147`; batching is applied in
  `SubtitleJobArtifactStore.php:120, 306` and dispatched via `TokenizeSubtitleCueBatch` ->
  `SubtitleCueBatchProcessor::tokenizeCueBatch()`.
- Per-cue cost knob exists but is **unset/zero** today: `OPENAI_TOKENIZATION_MICROUSD_PER_CUE`
  (`config/subtitles.php:115`, `.env.example:107=0`). So there is no committed cost model to anchor
  against — the eval harness should capture real token usage to fill this in.

---

## Option Analysis

### (a) Model upgrade / selection for the tokenization agent

- **Now:** `gpt-5.4-mini` (cheap tier) at temperature 0.1.
- **Lever:** point `OPENAI_TOKENIZATION_MODEL` at a stronger model (full `gpt-5.4` or whatever the
  current strongest structured-output model is), either globally or — better — re-introduce a
  *retry-only* stronger model so the cheap model handles the easy 90% and only failures escalate.
- **Pros:** one-line config change to A/B; no code; structured-output schema is unchanged so the
  agent contract holds; larger models are materially better at CJK morphology and at honoring
  "don't strand particles" instructions.
- **Cons / risk:** cost scales with batch volume (every cue, every job); the existing batch-split
  retry already multiplies calls on failure; a stronger model still has *no* hard guarantee against
  valid-but-bad boundaries (it lowers the rate, it does not bound it). Quality is unverifiable
  without the harness.
- **Effort:** **XS** (config) to A/B; **S** to wire a stronger *retry* model back in (the plumbing
  existed pre-2026-05-13 and can be reinstated as a per-prompt model override — Laravel AI supports
  prompt-level model overrides, per the 2026-05-12 plan decision-log line 79).

### (b) Prompt improvements

Concrete additions to `CueTokenizationAgent::instructions()` (currently
`CueTokenizationAgent.php:35-39`). Add per-script sections with **positive and negative** examples:

- **Chinese (Mandarin) — currently absent. Add e.g.:**
  - Split `我喜欢学习中文` as `我 / 喜欢 / 学习 / 中文` (pronoun / verb / verb / noun). Never return
    `我喜欢` or single characters `学 / 习` as separate tokens when they form one word.
  - Split `这是一个很好的例子` as `这 / 是 / 一个 / 很 / 好 / 的 / 例子`. Keep `一个` together; keep
    `例子` together; keep `的` separate as a particle.
  - Negative: never split a two-character word like `朋友`, `时候`, `因为` into single characters.
- **Japanese — extend the existing block with the observed failure modes:**
  - Lost small っ / sokuon: when source contains `打って`, keep `打って` whole (or `打っ / て` only if
    that is the learner unit) — **never** emit `うて` by dropping a leading character. Frame as:
    "Do not drop a leading character of a content word to form a token; every token is a contiguous
    cut and must start at a word boundary."
  - Orphan leading particle/mora: never emit a single trailing mora such as `り` as its own token
    when it is the tail of a verb/noun (`なり`, `終わり`). Frame: "Do not strand a single kana that
    is part of a neighboring content word."
  - Truncated word: when conjugated forms like `ならなくちゃ` / `ならなくて` appear, keep the
    conjugation attached to its stem; do not cut to `ならなく` and drop the ending.
- **Thai — currently absent. Add e.g.:**
  - `ผมชอบกินข้าว` -> `ผม / ชอบ / กิน / ข้าว` (I / like / eat / rice). Do not split a syllable across
    tokens; group characters into dictionary words, never per-character.
- **General reinforcement:** add an explicit rule "Every token must begin and end on a word
  boundary of the source language; never start a token in the middle of a content word, and never
  leave a single function-word fragment of a content word as its own token."
- **Test coupling:** `tests/Unit/AiAgentInstructionTest.php:14-22` asserts on instruction substrings;
  update/extend those assertions when the prompt changes so the additions are locked in.
- **Pros:** cheap, no infra, directly targets the documented failures, language-agnostic to deploy.
- **Cons / risk:** still probabilistic — prompt nudges reduce but do not eliminate bad boundaries;
  over-long prompts can dilute attention; few-shot examples can bias the model toward the example
  shapes. Must be measured (harness), not eyeballed.
- **Effort:** **S** (prompt + test edits). Highest signal-per-effort lever after the harness.

### (c) Deterministic morphological segmentation

Dictionary/lattice segmenters are the only way to get a *bounded* quality guarantee. Candidates:

- **Japanese:** MeCab (C++, IPAdic/UniDic), Sudachi (Java, multi-granularity — best dictionary
  alignment), Kuromoji (Java).
- **Chinese:** jieba (HMM+dict; Python original, many ports), pkuseg (Python, model-based, higher
  accuracy on some domains).
- **Thai:** PyThaiNLP (`newmm`/dictionary-based) or ICU `BreakIterator` (dictionary-based Thai/Lao
  word breaking, available as a library and via PHP's `intl`/`IntlBreakIterator`).

**Integration constraint — this is a PHP/Laravel backend on Redis queues.** PHP options:

- *Japanese:* `php-mecab` PHP extension + the `nihongodera/limelight` Composer wrapper exist and
  PHP 8 is supported (added Mar 2025). Requires installing MeCab + a dictionary + a native PHP
  extension on every worker host. No mature Sudachi PHP binding exists.
- *Chinese:* `fukuball/jieba-php` (pure PHP, Composer, no native deps — but loads large dictionaries
  into PHP memory and is slower) and `binaryoung/jieba-php` (PHP **FFI** over `jieba-rs`, fast,
  needs the Rust lib + FFI extension). Both Composer-installable.
- *Thai:* `intl`/`IntlBreakIterator` (already bundled with most PHP builds) gives dictionary-based
  Thai word breaking with **zero new dependencies** — the cheapest deterministic win.

**Three architectures (pick per the harness):**

- **(i) Deterministic PRIMARY, LLM fallback.** Segmenter produces boundaries; LLM only handles
  scripts/edge cases the segmenter doesn't cover.
  - Pros: cheapest at runtime, deterministic, bounded quality, no per-cue LLM cost for CJK.
  - Cons: segmenter granularity may not match "learner-clickable unit" (e.g. Sudachi A/B/C modes,
    jieba over-splitting compounds); needs per-language dictionaries; mixed-language cues need
    routing; biggest behavior change.
- **(ii) Deterministic as a VALIDATOR / guardrail on LLM output.** LLM tokenizes as today; the
  segmenter's boundaries are used to *reject* LLM cuts that fall inside a dictionary word (orphan
  `り`, lost-character `うて`, truncated `ならなく` would be caught), triggering retry/fallback.
  - Pros: keeps LLM's learner-unit judgment, adds a *hard* lower bound on legality, smallest
    conceptual change, directly re-creates the deleted morphology gate but backed by a real
    dictionary instead of hand-coded patterns. **Recommended deterministic architecture.**
  - Cons: needs a "do these boundaries conflict with dictionary words" comparison that tolerates
    learner-phrase merges; still pays LLM cost; needs the segmenter available at validate time.
- **(iii) Deterministic PRE-SEGMENT, LLM MERGES into learner units.** Segmenter over-segments into
  morphemes; LLM only *merges* adjacent morphemes into clickable units (never splits), so it can
  never strand a sub-word fragment.
  - Pros: eliminates the orphan/truncation/lost-character class structurally (LLM can't cut below a
    morpheme); keeps learner-friendly phrase grouping.
  - Cons: two-stage latency; prompt/schema change to "merge indices" instead of "emit text";
    granularity mismatch if the segmenter splits a unit the learner wants whole.

**Invoking a non-PHP segmenter.** If PHP-native bindings are insufficient (Sudachi/pkuseg/PyThaiNLP
have no good PHP binding), run a **sidecar microservice** (Python/FastAPI or Rust) on the worker
hosts exposing `POST /segment {lang, texts[]}` over localhost HTTP, called from the existing
`SubtitleCueBatchProcessor` path. This fits the Redis-queue model (workers already make outbound
provider calls), keeps PHP free of heavy NLP deps, and lets each language use its best engine. The
sidecar must be deployed to every batch-queue worker host (`subtitle-batch-{tier}` queues).

- **Effort:** **M** for PHP-native Japanese/Chinese guardrail (architecture ii) using
  `php-mecab` + `jieba-php`; **M–L** for a sidecar serving Sudachi/PyThaiNLP/pkuseg. Thai via
  `IntlBreakIterator` is **S**.
- **Risk:** dictionary granularity vs learner-unit mismatch is the central tuning problem; native
  deps complicate deployment (Dockerfile + every worker host); per-language coverage gaps.

### (d) Upstream transcription quality (flagged, lightly scoped)

Tokenization cannot repair a wrong source (the `うて`/lost-っ example). This is the Scribe stage's
domain (`ScribeTranscriptNormalizer`, audio prep, optional voice isolation). Notes:

- Voice isolation is **off by default** (`config/subtitles.php:131-138`, `ELEVENLABS_AUDIO_ISOLATION_ENABLED=false`,
  TD-014) — a known untested lever for WER and therefore for small-kana fidelity.
- The no-space artifact-space collapse (`ScribeTranscriptNormalizer.php:282-287`) is a candidate
  contributor to small-っ loss when Scribe emits character-level tokens; worth a targeted look but
  **out of scope for Track B** beyond flagging it. Recommend a separate Track for Scribe WER on CJK.

---

## 3. Evaluation Harness Design (build this first)

There is currently **no** Chinese fixture and **no** boundary-quality eval anywhere in
`app/backend/tests` (confirmed: fixtures search returns none; only structural unit tests like
`LearningTokenOutputValidatorTest.php` and instruction-substring tests exist). Without a metric,
none of Options a–c can be chosen on evidence.

### Gold dataset

- Build `app/backend/tests/Fixtures/tokenization/` (or a runnable eval dir outside the unit suite
  if it needs network) with **20–40 cues each** for Japanese, Mandarin, and Thai, drawn from real
  rendered overlay cues (including the two documented failures). Each fixture entry:
  ```json
  {
    "lang": "jpn",
    "sourceText": "なり大事な友達へ素直にならなくちゃ",
    "goldTokens": ["なり", "大事", "な", "友達", "へ", "素直", "に", "ならなくちゃ"],
    "note": "orphan-ri + truncation case"
  }
  ```
- Gold boundaries set by a native/fluent reviewer to the *learner-clickable unit* standard the
  prompt targets (not raw morphemes). Store `sourceText` **exactly** as the pipeline would (post
  `ScribeTranscriptNormalizer` artifact-space collapse), so eval mirrors production input.
- Tag each fixture as `tokenization-testable` vs `transcription-corrupted` so the harness can report
  the two classes separately (this operationalizes the attribution rule above).

### Metric

- Convert each segmentation to a set of **boundary positions** (character offsets where a cut
  occurs) over the source string. Compute **boundary precision / recall / F1** against gold:
  - precision = correct cuts / predicted cuts; recall = correct cuts / gold cuts.
- Also report **word-level F1** (a token is correct iff both its start and end boundaries match
  gold) — this is the standard CJK word-segmentation metric and penalizes orphan/truncation
  errors directly.
- Report a **failure-mode breakdown**: orphan-fragment count, truncated-word count,
  lost-character count (the latter detected as gold characters absent from `sourceText` ⇒ flagged
  transcription fault, excluded from tokenization F1).

### Runner

- A `php artisan` console command (e.g. `subtitles:eval-tokenization --lang=jpn --model=... 
  --prompt=current|candidate`) that:
  1. loads fixtures, 2. calls `LaravelAiTranslationAnalysisProvider::tokenizeCueBatch()` (or a
     candidate segmenter) per cue, 3. computes the metrics above, 4. prints a per-language table
     and writes JSON for diffing runs.
- Make model and prompt **swappable via flags/env** so the same harness scores: current model vs
  stronger model (Option a), current prompt vs augmented prompt (Option b), and deterministic
  segmenter output (Option c) — all against the same gold. Capture real token usage to populate the
  empty `OPENAI_TOKENIZATION_MICROUSD_PER_CUE` cost knob.
- This is the decision instrument: it answers "is it the model or the prompt?" quantitatively
  **before** investing in a sidecar.

---

## 4. Recommendation + Sequencing

**Recommended path (opinionated):**

1. **Step 1 — Harness (do first).** Build the eval command + J9–40-cue gold set for jpn/cmn/tha
   with failure-mode tagging and boundary/word F1. ~**M** effort, no provider lock-in for the
   metric. Everything downstream is gated on this. *First concrete task for the worker: scaffold
   `subtitles:eval-tokenization`, the fixtures directory, and the boundary-F1 metric, scoring the
   current `gpt-5.4-mini` + current prompt as the baseline.*
2. **Step 2 — Prompt A/B (fastest quality signal).** Add Chinese + Thai few-shot and the three
   Japanese negative examples (Option b); score augmented vs current prompt on the same model.
   Lock wins into `AiAgentInstructionTest`. ~**S**.
3. **Step 3 — Model A/B.** Score `gpt-5.4-mini` vs a stronger model with the winning prompt (Option
   a). If the stronger model clears the quality bar at acceptable cost, prefer the **retry-only
   escalation** (cheap model first, stronger on structural failure) reinstated as a per-prompt model
   override. ~**XS–S**.
4. **Step 4 — Deterministic guardrail (robust long-term fix) *if* Steps 2–3 don't clear the bar.**
   Adopt Option c architecture **(ii)**: dictionary segmenter as a validator that rejects LLM cuts
   inside dictionary words and triggers retry/fallback. Start PHP-native (`php-mecab`+`jieba-php`,
   `IntlBreakIterator` for Thai); move to a Python/Rust sidecar only if PHP bindings prove
   insufficient (e.g. you want Sudachi/PyThaiNLP). ~**M**.
5. **Step 5 (parallel track, flag only).** Open a separate Scribe-WER investigation for the
   transcription-fault class (lost-っ etc.), including the artifact-space-collapse review and
   voice-isolation A/B. Out of Track B scope beyond the flag.

**Why this order:** Steps 1–3 are cheap, reversible, and give fast measured signal; Step 4 is the
only option with a *bounded* quality guarantee but carries real deployment cost, so it is justified
only once the cheap levers are shown insufficient — which the harness will prove one way or the
other.

---

## Open Questions

- What is the strongest structured-output-capable model available for Step 3, and its per-1K-token
  cost vs `gpt-5.4-mini`? (Confirm against the live model catalog; the harness should capture
  actual usage so cost is measured, not assumed.)
- For Option c(ii), what is the tolerance rule for "LLM merged two dictionary words into one
  learner phrase" vs "LLM cut inside a word"? The validator must allow the former and reject the
  latter. Needs a small spec + fixtures.
- Are batch-queue worker hosts containerized such that a sidecar (or `php-mecab`/MeCab dictionary)
  can be added cleanly? (Deployment surface = all `subtitle-batch-{tier}` workers.)
- Should `OPENAI_TOKENIZATION_RETRY_MODEL` be removed outright, or revived to back the Step-3
  retry-only escalation? (It is currently dead config.)
- Confirm whether the small-っ class is reproducibly caused by `NO_SPACE_ARTIFACT_BOUNDARY_PATTERN`
  in `ScribeTranscriptNormalizer.php:282-287` vs raw Scribe output (decides if any of it is
  fixable inside our code at all).

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-18 | Recommend building the eval harness before any model/prompt/segmenter change. | Without boundary-F1 + failure-mode attribution, model-vs-prompt-vs-transcription cannot be told apart and spend would be guesswork. |
| 2026-06-18 | Classify `うて` (lost っ) as a transcription fault, `り`-orphan as a tokenization fault. | Validator forbids altering source characters (`LearningTokenOutputValidator.php:65-74`); if a "correct" character is absent from `sourceText`, only Scribe can fix it. |
| 2026-06-18 | Prefer deterministic segmentation as a **validator/guardrail** (arch ii) over primary tokenizer. | Keeps the LLM's learner-unit judgment while adding a bounded legality gate; directly restores the morphology gate removed on 2026-05-13, backed by a real dictionary. |
| 2026-06-18 | Sidecar microservice over in-process PHP for non-PHP segmenters. | Keeps heavy NLP deps out of PHP; fits the existing outbound-call worker model; lets each language use its best engine. |
| 2026-06-18 | Flag `OPENAI_TOKENIZATION_RETRY_MODEL` as dead config and the committed `OPENAI_API_KEY` in `.env` as a leaked secret. | `grep` shows the retry-model env is unreferenced; a live `sk-proj-...` key is committed in `app/backend/.env`. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-18 | Investigation doc created. | Read `CueTokenizationAgent.php`, `LearningTokenOutputValidator.php`, `LaravelAiTranslationAnalysisProvider.php`, `ScribeTranscriptNormalizer.php`, `config/ai.php`, `config/subtitles.php`, `.env`, completed plans 2026-05-12-tokenization-quality-gate-upgrade and 2026-05-13-tokenization-pipeline-cleanup-refactor, `tests/Unit/*`, `packages/contracts/languages.json`; confirmed PHP segmenter availability (php-mecab/limelight, fukuball/jieba-php, binaryoung/jieba-php FFI, IntlBreakIterator). |
| 2026-06-18 | Step 1 (harness) + Step 2 (prompt) implemented on branch `track-b/cjk-tokenization-eval`. | Added `TokenizationBoundaryMetric` + `SegmentationEvaluation` + `TokenizationQualitySummary` (`app/Services/TranslationAnalysis/`), `subtitles:eval-tokenization` command (`app/Console/Commands/EvalTokenization.php`), gold fixtures `tests/Fixtures/tokenization/{jpn,cmn,tha}.json`, unit tests `TokenizationBoundaryMetricTest` + `TokenizationFixturesTest`; augmented `CueTokenizationAgent::instructions()` with Mandarin + Thai examples and the three Japanese negative failure-mode examples (orphan fragment, truncated word, sokuon) and locked them into `AiAgentInstructionTest`. Full backend suite green: 215 passed / 2089 assertions. |

## Completion Notes

- What changed:
  - **Step 1 harness:** pure `TokenizationBoundaryMetric` reuses `LearningTokenOutputValidator::normalizeTokenText()` as the single normalization source of truth (whitespace collapse + no-space artifact-space strip + lowercase) and resolves token spans with the same advancing-offset `mb_strpos` search the validator enforces, so eval boundaries are scored over the identical comparable source string production sees. Computes micro-averaged boundary precision/recall/F1, word-level F1, and dictionary-free failure-mode counts: `goldWordSplits` (gold content word fully covered but split into >1 predicted token), `orphanFragments` (predicted single-character token that is a proper subset of a gold content word — the stranded-り signal), `truncatedWords` (gold content word whose characters are not fully covered — the ならなく-from-ならなくちゃ signal), and `lostCharacters` (gold token absent from source → transcription fault, excluded from tokenization F1 pools).
  - **`subtitles:eval-tokenization`** loads the gold fixtures, calls `LaravelAiTranslationAnalysisProvider::tokenizeCueBatch()` per cue (mirroring production: provider output runs through the structural validator), scores each cue, aggregates per language, prints per-language + per-cue tables, and writes JSON (`--json` / `--out=`) for diffing runs. `--model=` overrides `ai.providers.openai.models.tokenization.default` at runtime for model A/B (Step 3). Prompt A/B (Step 2) uses run-to-run JSON diffs (e.g. checkout baseline vs candidate) — the Laravel AI SDK reads instructions from the agent method with no per-call override, so keeping a parallel "candidate prompt" path in the agent would violate the one-current-product-path rule; run-diff is the clean A/B workflow. Fails loudly if `OPENAI_API_KEY` is unset.
  - **Step 2 prompt:** `CueTokenizationAgent::instructions()` now contains Mandarin examples (`我喜欢学习中文`, `这是一个很好的例子`), Thai examples (`ผมชอบกินข้าว`), the three Japanese negative failure-mode examples (orphan `り`, truncated `ならなく`, sokuon `うて`), and an explicit "every token must begin and end on a word boundary" reinforcement. Locked into `AiAgentInstructionTest`.
  - **Dead config:** removed the unreferenced `OPENAI_TOKENIZATION_RETRY_MODEL` line from the local gitignored `app/backend/.env`. It was never present in `.env.example` or `config/ai.php`, so there was no committable code to remove; the line was local-only. The committed `OPENAI_API_KEY` in `.env` is also local-only (`.env` is gitignored and untracked — verified `git check-ignore` and `git ls-files`), so the "leaked secret" is not a repo leak; it still requires rotation as a hygiene matter.
- Validation results: `TokenizationBoundaryMetricTest` (6 tests / 31 assertions) covers perfect segmentation, orphan-fragment detection, truncated-word detection, transcription-fault detection, no-space artifact-space collapse alignment, and micro-averaged aggregation excluding transcription faults. `TokenizationFixturesTest` (3 data-driven tests / 764 assertions) validates every fixture's shape and that gold tokens concatenate exactly to `sourceText` for tokenization-testable fixtures (and intentionally do NOT for the `transcription-corrupted` fixture). `AiAgentInstructionTest` locks the augmented prompt substrings. Full backend suite: 215 passed / 2089 assertions.
- Residual risk / open follow-ups:
  - **Gold fixtures are a starter set (12 cues per language), not the plan's 20–40 target.** Correct unambiguous segmentations plus both documented Japanese failure cases are included, but CJK/Thai gold segmentation ultimately needs a fluent reviewer; Thai in particular should get native review before anchoring model A/B decisions. Expanding to 20–40 per language with reviewed gold is the highest-leverage next harness task.
  - **Token-usage capture is not wired.** The harness does not yet capture real prompt/completion token usage to populate the empty `OPENAI_TOKENIZATION_MICROUSD_PER_CUE` knob; this requires exposing usage from the provider/agent response and is deferred to keep the harness change narrow. Add before Step 3 cost A/B.
  - **`goldWordSplits` is a superset signal.** A split that is valid learner re-segmentation vs over-splitting cannot be distinguished without a dictionary (Track B Option c, architecture ii). The harness reports the raw split count; the dictionary guardrail is what judges it.
  - Steps 3–5 remain as the plan recommends: run the harness for baseline + prompt A/B, then model A/B, then adopt the deterministic guardrail only if the cheap levers are shown insufficient.
- Follow-up debt: dead `OPENAI_TOKENIZATION_RETRY_MODEL` (local `.env`, removed); rotate the local `OPENAI_API_KEY` in `.env` as hygiene (not a repo leak); duplicated `NO_SPACE_ARTIFACT_BOUNDARY_PATTERN` across normalizer and validator (pre-existing, untouched); separate Scribe CJK-WER track (Step 5).
