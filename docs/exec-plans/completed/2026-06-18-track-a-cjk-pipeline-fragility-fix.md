# Plan: Track A — CJK / no-space-script pipeline fragility fix

Status: completed
Owner: agent
Created: 2026-06-18
Last updated: 2026-06-19

> This is a **solution-architecture** document, not a code listing. It describes the problem,
> the intended behavior of the fix, the constraints the implementation must respect, and the
> outcomes that define "done." The implementing engineer ("the worker") owns writing the code.
> File paths and line numbers are pointers verified on 2026-06-18 against branch
> `codex/architecture-review-cleanup`; treat them as "look here," not "type this."

## Goal

Stop the subtitle-generation pipeline from hard-failing entire jobs for no-space scripts
(Mandarin/Chinese, Japanese, Thai, Khmer, Lao, Burmese). After this work, a translation or
romanization batch whose model output drifts on CJK characters produces a usable subtitle track
with a sensible fallback instead of killing the generation. Only transcription and tokenization
remain truly load-bearing.

This is a **fragility** fix only. It does not change tokenization boundary **quality** — that is
Track B. See "Risks / non-goals."

## The problem in depth

### The root cause: the model is used as a verbatim "echo channel"

Three stages — translation, romanization, and word-card enrichment — ask the AI model to return
the source text (or each token's source `text`) **unchanged**, and then assert strict string
equality between what came back and what we already have. The model is effectively being used as
a courier that must hand back our own parcel byte-for-byte. Any drift in that echoed copy is
treated as a fatal error.

The drift is not hypothetical. For CJK input the model routinely:

- swaps traditional and simplified Han (后→後, 里→裡) while "preserving" the text,
- folds full-width punctuation/letters to half-width (or vice versa),
- silently "corrects" a character it judges mis-transcribed.

For space-delimited languages this almost never fires (few characters to reproduce, and word
spacing anchors the comparison). For no-space scripts the surface area is huge — a 12-character
Mandarin cue can be 6–10 tokens, a batch is 10 cues, so a single batch asks the model to echo
60–100 Han tokens character-perfect. The probability that *all* of them survive the round trip is
low, and one failure condemns the whole batch.

### Why one drifted character kills the whole job

A validation mismatch raises a non-transient `SubtitleProcessingException`. Non-transient
exceptions route through `SubtitleJobFailureHandler::failJob`
([SubtitleJobFailureHandler.php:23](app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php#L23)),
which marks the **entire** subtitle job `failed`, releases the reserved generation minutes, and
discards artifacts. There is no per-cue or per-batch isolation: the blast radius of one bad
character is the whole generation. Batch size is 10
([config/subtitles.php:147](app/backend/config/subtitles.php#L147)), so up to ten cues' worth of
successful upstream work is thrown away alongside it.

### The three echo gates, by fragility

1. **Romanization (worst).** `tokensPreservingSource()` in
   [LaravelAiTranslationAnalysisProvider.php](app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
   (~`:672`) requires the model to echo back the **exact token count** (`token_count_mismatch`)
   and **each token's exact source `text`** (`token_identity_mismatch`), and demands a
   romanization for every token. For CJK the echoed `text` is a string of Han characters — the
   single biggest drift source. Romanization also has **no split-retry fallback** (unlike
   tokenization), so a botched batch fails immediately and permanently.
2. **Translation (also fragile).** `translatedResult()` (~`:531`) calls `validateCueIdentity`
   with the default `validateSourceText = true` (~`:628`), forcing the model to echo the entire
   Han `sourceText` verbatim; any drift → `cue_identity_mismatch`. Also no split-retry. The
   `CueTranslationAgent` schema even *requires* `sourceText` in the output. We already hold the
   authoritative source locally, so this check buys nothing but failures.
3. **Tokenization (least fragile, in scope only for a shared-helper extraction).** Its substring
   alignment can also fault on variant characters, but it already has a split-retry and is
   load-bearing; we are **not** changing its validation. It is named here only because it shares
   one duplicated regex with the normalizer (see Solution 1).

### A latent hazard: a duplicated normalization rule

The regex that strips Scribe's artifact spaces between no-space characters is copy-pasted
verbatim in two files — the **producer** of `sourceText`
([ScribeTranscriptNormalizer.php:10](app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php#L10))
and the **comparer** that re-derives canonical text from it
([LearningTokenOutputValidator.php:9](app/backend/app/Services/TranslationAnalysis/LearningTokenOutputValidator.php#L9)).
These must stay byte-identical or alignment silently breaks. Today they agree; nothing enforces
that they keep agreeing.

## The solution in one sentence

Stop using the model as an identity channel: match cues and tokens by the keys we already own
(`cueId`, `index`), attach the model's *new* information (translation, romanization) onto our
locally-held authoritative source, and let the optional enrichment stages **degrade** to a
sensible fallback instead of failing the job.

## Scope

- In scope:
  - Translation: drop the `sourceText` echo requirement; match by `cueId` + `index`; degrade a
    missing/empty translation to the source text.
  - Romanization: stop echo-matching token `text`; attach romanization onto the local tokens
    **by `index`**; degrade per-token (and per-cue) when the model's romanization is missing or
    unusable; add a split-retry backstop mirroring tokenization.
  - Agent schemas (`CueTranslationAgent`, `CueRomanizationAgent`): remove the now-unread echoed
    fields so the model is not asked to reproduce CJK text it no longer needs to return.
  - Extract the duplicated no-space artifact-space regex into one shared helper consumed by both
    the normalizer and the validator.
  - Reproduction tests proving CJK character drift no longer fails a job.
- Out of scope:
  - Tokenization stage behavior — keep its strict validation and existing split-retry. The only
    code it shares with this work is the extracted regex helper.
  - Tokenization boundary **quality** (Track B).
  - The on-demand word-card path (`enrichToken()`) and full word-card enrichment
    (`tokensPreservingSource` / `validatedEnrichedCueResult`) — left strict. These are
    user-initiated and retryable on click, not part of the generation blast radius. If CJK
    fragility is later reported there, the same index-zip principle applies, but not in Track A.
  - Changing `cue_batch_size` — blast radius is solved by degradation, not by smaller batches.
  - Any extension / frontend change.

## Acceptance Criteria

- [x] A translation batch whose model output drifts on `sourceText` (e.g. traditional→simplified),
      or omits `sourceText` entirely, but returns a valid `translatedText`, no longer raises
      `cue_identity_mismatch`; the cue is translated and the job continues.
- [x] `CueTranslationAgent`'s schema no longer requires `sourceText`; cues are matched by `cueId`
      + `index`.
- [x] A romanization batch whose model returns a variant Han character (or different count) for a
      token no longer raises `token_identity_mismatch` / `token_count_mismatch`; romanization is
      attached to the local tokens by `index`.
- [x] A romanization batch the model botches badly (wrong count, missing indexes, unparseable)
      **degrades**: the cue ships with its locally-held tokens and no romanization on the
      unmatched tokens, the job completes, and no `failJob` is triggered for that batch.
- [x] A translation batch the model botches (missing/empty `translatedText` for a cue)
      **degrades**: that cue's `translatedText` falls back to its `sourceText`; the job completes.
- [x] Romanization has a split-retry backstop equivalent to tokenization's, attempted before any
      degradation, for recoverable *structural* failures.
- [x] The no-space artifact-space regex exists in exactly one place; both the normalizer and the
      validator consume it from there, with behavior unchanged.
- [x] New CJK reproduction tests fail on `main` and pass after the change.
- [x] `php artisan test --compact` (from `app/backend`) and `.\scripts\agent\check.ps1` are green.

## Architectural constraints the implementation must respect

These are verified facts about the surrounding pipeline. The fix lives **inside the provider's
translation/romanization methods and the two agent schemas**; it must not disturb the job/queue
failure plumbing, and it must keep producing cues the downstream stages accept.

1. **Translation results only contribute `translatedText`, keyed by `cueId`.**
   `SubtitleGenerationPipeline::mergeTranslatedText()`
   ([SubtitleGenerationPipeline.php:307](app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php#L307))
   overlays translation onto the base cues by `cueId` and only needs a string `cueId` and a
   non-empty `translatedText`; it never reads `sourceText` or `index` off the translation result.
   ⇒ Dropping the `sourceText` echo is safe; a degraded translation cue needs only `cueId` + a
   non-empty `translatedText`.

2. **Romanization results become the merged-cue base → the final track.**
   `mergeCuesAfterCompletedRomanizationBatches()` passes the romanized cues as the `base` whose
   `tokens` are written to the track
   ([SubtitleGenerationPipeline.php:135](app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php#L135)
   onward). ⇒ A degraded romanization cue must keep its **full, non-empty token list** (the
   locally-held source tokens) — just without romanization annotations on the unmatched tokens.

3. **The final track generator requires non-empty tokens and core fields.**
   `TimestampedSubtitleTrackGenerator::validatedEnrichedCues()`
   ([TimestampedSubtitleTrackGenerator.php:83](app/backend/app/Services/Subtitles/TimestampedSubtitleTrackGenerator.php#L83))
   rejects a cue unless `cueId`/`sourceText`/`translatedText` are non-empty strings,
   `index`/`startMs`/`endMs` are ints, and `tokens` is non-empty. Romanization values themselves
   are not required by the track generator, so omitting them is safe.

4. **Degrading a romanization batch must not re-trigger romanization.**
   `shouldRomanizeTranscript()` reads the **draft** cues, not romanization output
   ([SubtitleGenerationPipeline.php:399](app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php#L399)),
   so a degraded batch will not loop.

5. **Only rate-limit / provider-unavailable codes are transient.** Validation failures are
   non-transient and currently reach `failJob` through both `SubtitleCueBatchJob::handle()` and
   `SubtitleCueBatchProcessor::runCueBatch()`. We do not change that plumbing; instead the
   provider methods stop *throwing* for recoverable CJK drift and return degraded cues.
   Transcription and tokenization keep their current fatal behavior.

## The solution, stage by stage

Each subsection states the problem at that spot, the intended behavior after the fix, the
decisions and why, and what the worker must verify. Implementation is the worker's; only small
illustrative fragments appear where they remove ambiguity.

### Solution 1 — One shared no-space normalization helper (hardening)

**Problem.** The artifact-space regex is duplicated in the producer
([ScribeTranscriptNormalizer.php:10](app/backend/app/Services/Transcription/ScribeTranscriptNormalizer.php#L10),
used in `normalizeTranscriptText()`) and the comparer
([LearningTokenOutputValidator.php:9](app/backend/app/Services/TranslationAnalysis/LearningTokenOutputValidator.php#L9),
used in `normalizeTextForComparison()`). They must remain identical; nothing guarantees it.

**Intended outcome.** A single owner of that rule — a small `App\Services\Text` helper exposing
the pattern and a `stripArtifactBoundaries(string): string` operation — consumed by both classes.
Pure refactor: byte-for-byte identical normalization output, no behavior change. After the change,
searching the codebase for the old constant name returns one definition (in the helper) and zero
copies in the two services.

**Decisions / notes.**
- Do this first; it is isolated and de-risks the rest.
- **NFKC is optional and complementary, not the fix.** Unicode NFKC folds full/half-width and
  compatibility variants but does **not** fold traditional↔simplified (distinct codepoints) — which
  is exactly why the real fix is "don't exact-match," below. If the worker chooses to add NFKC
  inside the shared helper, it must be guarded on `intl` availability (`class_exists(\Normalizer::class)`)
  and confirmed present in CI before anything relies on it; otherwise leave it out of Track A.

**Verify.** Existing `ScribeTranscriptNormalizerTest` and `LearningTokenOutputValidatorTest` stay
green unchanged.

### Solution 2 — Translation: match by identity, drop the source echo, degrade

**Problem.** `translatedResult()` echo-compares the full Han `sourceText`
([LaravelAiTranslationAnalysisProvider.php:531](app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php#L531),
via `validateCueIdentity` with `validateSourceText = true` at ~`:628`), and treats a missing
translation as fatal — even though translation is optional.

**Intended outcome.**
- Cues are matched by `cueId` + `index` only. A `cueId`/`index` mismatch is a genuine ordering
  bug and stays fatal; the `sourceText` comparison is dropped entirely.
- `CueTranslationAgent`'s output schema no longer requests `sourceText`, and its instructions no
  longer ask the model to echo it. (`withoutAdditionalProperties` then forbids the field, keeping
  output minimal — consistent with the provider no longer reading it.)
- A cue whose translation comes back missing or blank **degrades** to its own `sourceText` as the
  translation, rather than failing the batch. Every matched cue therefore always has a non-empty
  `translatedText`, satisfying constraint #1.

**Decisions / notes.**
- `validateCueIdentity` already supports `validateSourceText: false` (that is how the tokenizer
  path calls it) — no signature change is needed; the call site just opts out of the source check.
- The same-language guidance in the agent prompt is harmless to leave as-is; same-language
  requests don't reach this agent in practice. The minimum required schema change is removing
  `sourceText`.

**Verify.** `mergeTranslatedText` still satisfied; the two existing "rejects" tests
(`test_translation_rejects_changed_cue_identity`, `test_translation_rejects_empty_translated_text`
in `CueEnrichmentServiceTest`) now describe *old* behavior and must be rewritten — keep a case
proving a **cueId** mismatch is still fatal, and convert the empty-translation case to assert the
source-text fallback. Passing translation fakes that still include `sourceText` keep working (the
provider ignores it); trimming them is optional cleanup.

### Solution 3 — Romanization: zip by index, degrade, add a split-retry backstop

**Problem.** `romanizedResult()`
([LaravelAiTranslationAnalysisProvider.php:559](app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php#L559))
routes tokens through `tokensPreservingSource()` (~`:672`), which fatally rejects any drift in
token **count** or token **text**, and requires romanization on every token — then the whole
stage has no split-retry. This is the brittlest path in the pipeline and the one most likely to
kill Mandarin jobs.

**Intended outcome.**
- The model is asked only for romanization, keyed by token `index` (plus a cue-level
  romanization). `CueRomanizationAgent`'s schema drops the echoed `sourceText`, `translatedText`,
  and per-token `text`; its instructions tell the model it receives pre-tokenized tokens
  (`index` + `text`) and returns, per token, the same `index` and a Latin `romanization` — no need
  to echo the characters. The romanization **input** still sends token `text` so the model can
  read the characters (see `romanizationInput()` ~`:394`); only the echo-back is removed.
- The result cue is rebuilt from the **locally-held source tokens** — their `index`, `text`, and
  `normalizedText` are authoritative and never come from the model. Romanization is attached to a
  token only when the model supplied a matching `index`. Tokens without a match simply carry no
  romanization (graceful per-token degradation). Cue-level romanization is attached when present
  and omitted otherwise.
- Net effect: token **count, order, text, and boundaries are immune to model drift**; romanization
  is best-effort enrichment layered on top. A cue can never lose its tokens, so constraints #2 and
  #3 always hold.

**Decisions / notes.**
- Do **not** reuse `tokensPreservingSource()` for romanization — leave it for the word-card path
  that legitimately echoes Latin token text. Romanization gets its own small index-keyed merge
  (conceptually: build an `index → romanization` map from the model output, tolerant of missing
  or malformed entries; then walk the source tokens and attach by index).
- `sourceTokens()` already fails fatally if a cue has no tokens (`missing_source_tokens`). That
  stays fatal and is correct — it means tokenization output is broken upstream, which is
  load-bearing, not optional.
- **Split-retry backstop.** Give romanization the same batch-halving retry tokenization has
  (model it on `tokenizeBatch` / `shouldRetryTokenizationBatch`, ~`:47`/`:241`). After the
  index-zip change, per-token CJK drift never throws, so the retry only guards *structural*
  cue-array problems (e.g. the model returned the wrong number of cues). Retry the recoverable
  structural reasons; keep `missing_source_tokens` fatal. The acceptance criteria call for this
  backstop; include it even though degradation already covers the per-token case.

**Verify.** `romanizeCueBatch` / `romanizedResult` signatures unchanged; only callers are the
processor and the test helper. Confirm by search that the translation/romanization paths no longer
read `$outputCue['sourceText']` or `$outputToken['text']`, while the word-card path still does and
is untouched. The two romanization "rejects" tests
(`test_romanization_rejects_changed_token_boundaries`, `...changed_token_count`) assert old fatal
behavior and must be rewritten to assert the new degradation — they *are* the bug reproduction.
`test_romanization_rejects_tokenless_cues` stays valid (`missing_source_tokens` still fatal).

### Solution 4 — Prove it with CJK reproduction tests

**Intended outcome.** A handful of tests in the existing `CueEnrichmentServiceTest` (reuse its
`sourceCue()` / `romanizeBatch()` / `translateBatch()` helpers) that **fail on `main` and pass
after** Solutions 2–3, using simplified-vs-traditional Han and full/half-width variants to drive
the exact failure. Cover at least:

- Translation tolerates a drifted/omitted `sourceText` (same `cueId`/`index`, valid
  `translatedText`) — no exception, cue translated.
- Translation degrades an empty `translatedText` to the source text.
- Romanization attaches pinyin to local tokens **by index** even when the model's echoed token
  text would have differed — assert the tokens equal the source tokens and the romanization landed
  on the right indexes.
- Romanization degrades when the model returns the wrong token count — both source tokens survive,
  the matched token has romanization, the unmatched one does not, no exception.
- Romanization degrades when the model's token romanization is unparseable — cue keeps its source
  tokens with no romanization keys, and still satisfies the track generator (tokens non-empty).

Optionally add a tiny test for the shared helper (Solution 1) asserting an artifact space between
two Han characters is stripped while an ASCII space between Latin words is preserved.

All faked agent outputs should conform to the **new** schemas (translation fakes omit
`sourceText`; romanization token fakes omit `text`) so the fixtures don't drift from reality.

## Sequencing / PR boundaries

Ship as small, independently reviewable changes, each carrying its own proof:

1. **PR 1 — Shared regex helper (Solution 1).** Pure refactor, no behavior change.
2. **PR 2 — Translation (Solution 2 + its tests).** Schema + identity-match + degradation.
3. **PR 3 — Romanization (Solution 3 + its tests).** Schema + index-zip + degradation +
   split-retry backstop.

PRs 2 and 3 are independent and can land in either order. The test rewrites for each stage belong
in that stage's PR so no commit leaves the suite red.

## Validation Plan

Commands:

```powershell
# Full repo harness (contracts + backend + extension):
.\scripts\agent\check.ps1

# Backend only (from app/backend):
php artisan test --compact

# Focused while iterating (from app/backend):
php artisan test --compact --filter=CueEnrichmentServiceTest
```

Evidence to capture:

- The new CJK degradation/tolerance tests pass; the rewritten `*_rejects_*` tests reflect new
  behavior; the whole backend suite is green.
- Proof of reproduction: before applying Solutions 2–3, the new tests fail with
  `cue_identity_mismatch` / `token_count_mismatch` / `token_identity_mismatch`; after, they pass.
- No new logs/metrics required (an optional romanization-retry log mirroring the existing
  tokenization-retry log is the worker's discretion).

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-18 | Match translation/romanization cues by `cueId` + `index` only; never echo-compare `sourceText`. | The pipeline already holds the authoritative source locally; echo-comparing it only manufactures CJK false-failures. |
| 2026-06-18 | Drop the echoed fields from both agent schemas. | Removes the model's obligation to reproduce CJK characters byte-perfect; `withoutAdditionalProperties` then keeps output minimal. |
| 2026-06-18 | Romanization attaches by token `index`; unmatched tokens degrade to no romanization. | Token text/boundaries are load-bearing and held locally; romanization is an optional annotation. |
| 2026-06-18 | Translation/romanization degrade rather than `failJob`; only transcription + tokenization stay fatal. | These stages are optional enrichments; one bad batch should not destroy a whole generation. |
| 2026-06-18 | Add a romanization split-retry mirroring tokenization, for recoverable structural reasons only. | Backstop for wrong-cue-count outputs and consistency with the established pattern. |
| 2026-06-18 | Extract the no-space artifact-space regex into one shared helper. | One producer and one comparer must normalize identically; duplication risks silent drift. |
| 2026-06-18 | NFKC is complementary/optional, not the fix. | It cannot fold traditional↔simplified; only "don't exact-match" is robust, and `intl` is not guaranteed. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-18 | Plan created and verified against the provider, agents, validator, normalizer, jobs, processor, pipeline, track generator, config, and existing tests on `codex/architecture-review-cleanup`. | |
| 2026-06-18 | Reframed from a code listing into a solution-architecture spec; implementation left to the worker. | |
| 2026-06-19 | Implemented on branch `track-a/cjk-fragility-fix` as three sequential commits (regex helper / translation / romanization). All acceptance criteria met; backend suite green (222 passed / 2107 assertions); contracts + extension checks green. Reproduction verified by reverting provider/agent to HEAD and confirming the new CJK/degradation tests fail there, then pass after. | git log: `0151987`, `de89981`, `a99e8f6` |

## Completion Notes

- What changed:
  - **Solution 1 (commit `0151987`):** New `App\Services\Text\NoSpaceArtifactBoundary` owns the artifact-space regex and a `strip(string): string` operation. `ScribeTranscriptNormalizer::normalizeTranscriptText` and `LearningTokenOutputValidator::normalizeTextForComparison` both consume it and drop their private copies. Byte-for-byte identical normalization; `ScribeTranscriptNormalizerTest` and `LearningTokenOutputValidatorTest` unchanged and green. Added `NoSpaceArtifactBoundaryTest` (3 tests) locking the direct contract.
  - **Solution 2 (commit `de89981`):** `CueTranslationAgent` schema/instructions no longer request `sourceText` (`withoutAdditionalProperties` now forbids it); `translatedResult` calls `validateCueIdentity(..., validateSourceText: false)` so cues match by `cueId` + `index` only, and degrades a missing/blank `translatedText` to the local `sourceText`. A `cueId`/`index` mismatch stays fatal. Rewrote `test_translation_rejects_changed_cue_identity` (cueId mismatch still fatal) and `test_translation_rejects_empty_translated_text` → `test_translation_degrades_empty_translated_text_to_source_text`; added `test_translation_tolerates_cjk_source_without_echoed_source_text`. Trimmed echoed `sourceText` from all translation fakes.
  - **Solution 3 (commit `a99e8f6`):** `CueRomanizationAgent` schema/instructions drop `sourceText`, `translatedText`, and per-token `text` (input still sends token `text`). `romanizedResult` rebuilds each cue from the locally-held source tokens and attaches romanization by `index` via a tolerant `romanizationByIndex` map; unmatched tokens carry no romanization; cue-level romanization is attached when present and omitted otherwise. `missing_source_tokens` stays fatal. Added a `romanizeBatch` split-retry mirroring `tokenizeBatch` with `shouldRetryRomanizationBatch` (structural reasons only: `missing_cues` / `cue_count_mismatch` / `invalid_cue` / `cue_identity_mismatch`) and a `backend.romanization_batch_retried` log. Rewrote the two romanization `*_rejects_*` tests into index-attachment + count-degradation assertions; added CJK pinyin, unparseable-romanization, and split-retry reproduction tests. Trimmed echoed fields from all romanization fakes. Updated `AiAgentInstructionTest` to the new romanization instructions.
  - **Out of scope, untouched:** tokenization stage validation + its split-retry; the on-demand / full word-card enrichment path (`enrichToken`, `validatedEnrichedCueResult`, `tokensPreservingSource` — still used by the enrichment path at `LaravelAiTranslationAnalysisProvider.php:552`); `cue_batch_size`; extension; contracts (cue/token `romanization` was already optional in the published schema, so no contract change or regeneration was needed).
- Validation results:
  - Backend: `php artisan test --compact` → **222 passed / 2107 assertions**. Focused: `CueEnrichmentServiceTest` (41) + `AiAgentInstructionTest` + `ScribeTranscriptNormalizerTest` + `LearningTokenOutputValidatorTest` + `NoSpaceArtifactBoundaryTest` all green.
  - Reproduction: with the provider + agent reverted to HEAD, the new translation tests fail with `cue_identity_mismatch` and the new romanization tests fail with `token_identity_mismatch` / `token_count_mismatch` / `cue_count_mismatch`; all pass after the fix.
  - Repo harness: docs lint passed; `packages/contracts` `npm run check` exit 0 (schemas compile, fixtures validate, types regenerate); `app/extension` `npm test` (91 passed) / `npm run compile` / `npm run build` all exit 0. Note: `scripts/agent/check.ps1` aborts early on npm's stderr progress line under PowerShell 5.1 `$ErrorActionPreference="Stop"` (a pre-existing harness quirk, not a real failure); each component was run directly and is green.
- Simplicity/readability review: one current product path retained — no parallel old/new code. The romanization index-zip is a small focused path; `tokensPreservingSource` is left for the word-card path that legitimately echoes Latin token text. No new provider indirection, caches, or scoring. The shared regex helper is the single owner of the rule with two call sites. `missing_romanization` reason removed entirely (no dead code).
- Residual risk:
  - Degradation is silent to the user (a botched romanization batch ships cues without romanization; a botched translation cue ships its source text as the "translation"). This is the intended trade documented in Risks/non-goals; counting degraded batches in telemetry is follow-up debt, not required here.
  - Token-level `romanization` stays required in the `CueRomanizationAgent` schema (the model returns one romanization per token it romanizes); per-token degradation is via missing index entries and `cleanString` nulling whitespace/null values, not via schema optionality. In production with strict structured output, cue-level romanization is always present; the provider degrades only if `cleanString` yields null.
  - The pre-existing uncommitted edit to `app/extension/entrypoints/sidepanel/main.ts` is unrelated to Track A and was left untouched; it builds and tests cleanly.
- Follow-up debt:
  - Optional telemetry for degraded translation/romanization batches (mentioned in Risks/non-goals).
  - `scripts/agent/check.ps1` PowerShell 5.1 stderr handling (separate harness debt).
  - Apply the same index-zip principle to the word-card path only if CJK fragility is later reported there (out of scope per plan).
  - Track B Steps 3–5 remain gated on harness results, per the Track B plan.

## Risks / non-goals

- **Fragility only, not quality.** This stops CJK jobs from hard-failing; it does not improve
  tokenization boundary quality, romanization accuracy, or translation quality. Better no-space
  boundaries are **Track B**.
- **Degradation is silent to the user.** A botched romanization batch ships cues without
  romanization; a botched translation cue ships its source text as the "translation." This is the
  intended trade — a usable track beats a dead job — but it means absent romanization is no longer
  an error signal. Counting degraded batches in telemetry is reasonable follow-up debt, not
  required here.
- **Word-card paths unchanged.** `enrichToken()` and full word-card enrichment keep their strict
  echo checks; they are user-initiated and retryable. Apply the same index-zip principle there
  only if CJK fragility is later reported.
