# Pipeline & Subtitle Display Review — Agent Prompt

Copy everything below the line into your agent of choice. Fill in the bracketed paths if your agent works inside the repo.

---

You are a senior reviewer evaluating the **transcribed-subtitle-extension** repo. Produce a written, evidence-cited review. Do NOT modify code unless explicitly instructed — this is a read-only audit unless asked otherwise.

## Working directory
The repo root is the current working directory. Treat these as the source of truth:
- Product intent: `docs/product-specs/index.md`
- Architecture: `ARCHITECTURE.md`
- Design / quality / review expectations: `docs/DESIGN.md`, `docs/RELIABILITY.md`, `docs/REVIEW.md`, `docs/QUALITY_SCORE.md`, `docs/quality/golden-principles.md`
- Contracts: `packages/contracts/` (OpenAPI + JSON schemas), `packages/contracts/languages.json`
- Backend pipeline: `app/backend/app/Services/Transcription/` and `app/backend/app/Services/TranslationAnalysis/`, plus `app/backend/app/Jobs/`
- Extension display: `app/extension/utils/overlay/`, `app/extension/utils/cue-navigation.ts`, `app/extension/utils/contracts.ts`, `app/extension/utils/api-response-guards.ts`

Cite every claim with `file_path:line_number` (or `file_path:start-end`). Do not generalize without a code reference.

## Context the user has reported
1. Overall: they want the full pipeline audited — audio transcription → transcript/segment normalization → tokenization → romanization → translation → word-card enrichment → track storage → extension display.
2. Specific pain: **For some languages, subtitles get "off track" — words leak onto the next line/cue, which throws the translation out of sync. It self-corrects eventually, but is inconvenient.** Treat this as a confirmed symptom and trace its root cause; do not just confirm it exists.

## What to evaluate

### 1. End-to-end pipeline architecture
- Trace the full path from `POST /v1/subtitle-jobs` through queue dispatch, ElevenLabs Scribe transcription, `ScribeTranscriptNormalizer`, tokenizer agent batches, optional romanization/translation/enrichment, track merge, and storage. Produce a stage-by-stage diagram (text) with the responsible class/job and the artifact written at each stage.
- Judge the architecture against the stated boundary model (`Contracts -> Config -> Persistence -> Services -> HTTP/UI`) and the rules in `ARCHITECTURE.md` lines 99-117. Flag any cross-layer leak, anything the extension does that should be server-side (or vice versa), and any place a provider response reaches storage or the extension without normalization.
- Evaluate redundancy and simplification: anywhere two queued stages could fold into one, any validation that is duplicated across services/jobs/resources, any job/contract drift. Identify the *narrowest* simplifications that preserve the stated guarantees (token boundaries immutable after tokenization, romanization annotates-only, etc.).
- Resilience: stale `run_id` skipping, batch retry/split behavior for invalid tokenization (`LaravelAiTranslationAnalysisProvider` and `LearningTokenOutputValidator`), fail-open Audio Isolation fallback, partial-state recovery when a batch dies mid-run. Note any failure mode that leaves a job stuck or yields a track with inconsistent token/translation boundaries.

### 2. Transcription & segment normalization quality (`ScribeTranscriptNormalizer`)
- Inspect `segmentsFromWords`, `shouldBreakBefore`, `segmentFromWords`, `cueDuration`, `cueCharacterCount`, and the `MAX_CUE_*` constants. Evaluate the break heuristics against: long words, no-space scripts (CJK, Thai, Khmer, Lao, Japanese, etc.), provider-inserted character spacing, silences, and end-of-utterance gaps.
- Identify conditions where a logical word is split across two cues. Quantify with concrete example inputs where possible. This is the most likely upstream cause of the user's "word leaks into the next line" symptom — verify or refute it.
- Check language handling: how provider language codes are normalized to the catalog, what happens for `auto`, and whether script detection (space vs no-space) is data-driven or hard-coded.

### 3. Tokenizer agent boundaries (`LaravelAiTranslationAnalysisProvider` + validator + prompt)
- Read the tokenizer prompt and the structured-output schema. The prompt passes prev/current/next cue text — assess whether that is enough to avoid bleeding a word across cue boundaries, especially for no-space scripts where the cue split itself may be wrong (a tokenizer cannot reunite text that was already segmented into two cues).
- Validate the validator's guarantees: cue identity, sequential token indexes, non-empty lexical text, source-order boundary safety. Are there fall-through paths where invalid output is silently repaired or accepted? What happens to the *text* the validator uses as `source_text` — is it the post-normalizer segment text or the provider raw? Mismatches here are a prime suspect for token drift.
- Romanization "cannot retokenize / annotates boundaries" — verify this is actually enforced (i.e., romanization cannot add or move tokens). Same for translation and word-card enrichment. Any silent mutation of `token.index` or `cueId` ordering is a finding.

### 4. Extension display & line-breaking (`app/extension/utils/overlay/`)
- Read `overlay-render.ts` (`renderSourceLine`, `renderTranslation`, the `token-area` / `token-slot` / `token-card` / `token-text` structure) and `overlay-styles.ts` (flex-wrap, `overflow-wrap: anywhere`, gap, `inline-grid`, `inline-flex`, `white-space: nowrap`, `max-width`, `min-width: 0`, compact/scroll variants).
- Reconstruct what actually happens visually for a long/no-space token:
  - `.token-area` is `display:flex; flex-wrap:wrap` — tokens are flex items.
  - `.token-text` has `overflow-wrap: anywhere` — a single long token can break **mid-token** across lines.
  - `.translation` is a separate block below the source area, not aligned token-by-token.
  Evaluate whether "words leak onto the next line and translation goes out of sync until it fixes itself" is a **layout** issue (token wrapping mid-word, or the cue rail re-rendering on cue change causing a reflow that briefly shows the previous cue's tokens) vs a **data** issue (segmentation/tokenizer producing a split that places trailing characters in the next cue). State which it is with evidence.
- Check the cue-switch path (overlay re-render on `activeCueChanged` / time-based `cueForPlaybackTime`). Is there any window where stale tokens render for the new cue, or where a hovered/pinned token popover stalls a re-render? Anything that could cause the "self-corrects after a moment" behavior the user described.
- Accessibility & i18n: `lang` attribute on `.token-area` (`auto` -> `und`), directionality for RTL (`dir`), and font choices (`IBM Plex Mono` for romanization) for scripts where a monospace latin font is wrong (e.g., Cyrillic, Greek, Arabic, Devanagari romanizations).

### 5. Output quality (end-to-end)
- Identify the strongest levers for improving *perceived* subtitle quality: cue segmentation thresholds, tokenizer prompt/schema, display layout (preventing mid-token breaks), language-specific handling for no-space scripts, and any place where provider raw output reaches the user without a normalization pass.
- Where you propose changes, give: (a) the precise file/function, (b) the minimal change, (c) the guarantee it preserves, (d) the validation/test that would prove it, and (e) any risk to the tier/billing/entitlement invariants described in `ARCHITECTURE.md`.

## Required deliverables (in this order)
1. **Pipeline map** — stage-by-stage, with class/job + artifact + invariant maintained, and one diagram.
2. **Findings table** — `Severity | Stage | File:line | Symptom | Root cause | Recommended minimal fix | Validation`. Severity scale: blocker / major / minor / nit.
3. **Root-cause analysis of the user's reported symptom** (words leak to next line, translation drifts, self-heals) — name the file:line that causes it and the file:line that "fixes" it. Distinguish data-rooted vs layout-rooted; if both, separate the two.
4. **Simplification opportunities** — concrete, minimal, guarantee-preserving.
5. **Output-quality improvements** — ranked by impact per effort, each with the file/function to touch.
6. **Open questions** — anything you could not determine from code alone and would need logs, a sample job, or a repro to confirm.

## Hard rules
- Every claim cites `file:line`. No vague "the code does X".
- Do not propose adding dependencies, frameworks, or new abstractions unless no narrower change exists — justify explicitly if you do.
- Do not propose changes that alter the billing/entitlement/concurrency contract in `ARCHITECTURE.md` lines 26-31 without flagging it as a contract change.
- If you find the reported symptom cannot be reproduced from code alone, say so and specify the exact log/artifact fields (see `trace events` in `ARCHITECTURE.md` line 25/76) you would need to confirm it.
- Leave the repo unchanged; output only the review.