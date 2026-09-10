# Plan: Refresh AI prompts and validate learning output

Status: complete
Owner: agent
Work mode: standard
Created: 2026-09-10
Last updated: 2026-09-10

## Goal

Refresh all seven Laravel AI agents for reliable source preservation, useful learning metadata, consistent readings, and smaller requests/responses. Commit only this work on codex/flexible-ai-providers.

## Scope

- Shared task, text-as-data, segmentation, contextual translation, card-field, and romanization rules.
- Complete case-sensitive token coverage and grapheme/spaced-word boundary validation.
- Compact card annotations, meaningful-card validation/cache completion, versioned reuse.
- Deduplicated adjacent context and bounded corrective feedback on existing retries.
- Mode-specific lyrics alignment and removal of unused full-replacement input.
- Held-out agent evaluation with raw responses, pipeline recovery, token usage and latency; human semantic review remains explicit.
- Preserve unrelated account/UI work and the preexisting analysis MaxTokens 18000 change outside these commits.

## Acceptance Criteria

- [x] Missing negation, repeated words, changed casing, and split graphemes/space-delimited words are rejected.
- [x] Source and token text stay server-owned in compact card output; IDs/indexes still validate.
- [x] A successful card contains translation or gloss; duplicate gloss can be null, including edited cues.
- [x] Existing incomplete cards remain eligible for enrichment and new cache/reuse versions apply.
- [x] All agents distinguish source data from instructions and use consistent task-specific language rules.
- [x] Adjacent cues appear once and retry feedback names the validation failure.
- [x] Full and partial alignment instructions match their schemas and payloads.
- [x] Evaluation fixtures cover all agents and distinguish structural checks from semantic judgments.
- [x] Repository checks, formatting, and self-review pass; grouped staging excludes all preexisting edits.

## Relevant Context

- ARCHITECTURE.md; docs/RELIABILITY.md; docs/SECURITY.md; docs/quality/golden-principles.md.
- Skills: code-review, Laravel staff review, Ponytail, git-group-commits, local ai-sdk-development, laravel-best-practices, subtitle-pipeline.
- Laravel best-practices requires a subagent to inspect its rule files; bounded agents handled prompts, lyrics, and evaluations alongside provider/validator implementation.
- Context7 /laravel/ai documentation fetched for structured schemas, constructor prompting, fakes, and events; installed SDK source checked where needed. Boost search-docs unavailable; boost:list-skills confirmed routing.

## Implementation Steps

1. Refresh shared prompt rules and compact schemas together with provider contracts.
2. Enforce token coverage, meaning requirements, cache identity/versioning, and recovery feedback.
3. Simplify lyrics mode instructions and input; retain authoritative reconstruction.
4. Extend evaluation tooling and fixtures; run regression checks and review.
5. Update repository memory, archive this plan, and commit logical groups.

## Validation Plan

Run targeted validator/provider/instruction/lyrics/evaluation tests, Pint, and scripts/agent/check.ps1. Record measured provider evidence separately from mocked tests; never claim a speed improvement from prompt length alone.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-10 | Keep existing bounded retries and deterministic fallback, with corrective feedback. | Improves recovery without new retry layers or extra normal-path calls. |
| 2026-09-10 | Canonicalize existing artifact spaces before prompting; compare source case-sensitively. | Gives model and validator one explicit copy source. |
| 2026-09-10 | Require translation OR gloss, including a truthful uncertainty gloss. | Avoids empty cards and duplicate explanations without inventing meaning. |
| 2026-09-10 | Remove echoed card text but retain cue/token identities. | Server already owns immutable text; reduces generated output. |
| 2026-09-10 | Keep analysis output limit at its existing working-tree value, excluding that hunk from commit. | Respects preexisting user work; no unmeasured budget tuning. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-10 | Core prompt/validation/lyrics integration ready. | Validator 10 tests; provider 61 tests; combined focused suite 148 tests / 828 assertions passed. |

## Completion Notes

- `scripts/agent/check.ps1` passed: 511 backend tests / 3,814 assertions; 227 extension tests in 31 files; contract validation, TypeScript compilation and WXT build passed.
- `php vendor/bin/pint --dirty --format agent` and `git diff --check` passed.
- Live synthetic evaluation passed all nine first-response contract checks on Cerebras, with no recovery calls. No claimed speedup or bilingual semantic-quality certification.
- Commit groups: `fix: clarify AI prompts and enforce complete learning output`; `refactor: tailor lyrics alignment prompts to replacement mode`; `test: evaluate all subtitle agents with held-out cases`.
- Preexisting account/UI edits and analysis MaxTokens increase remain uncommitted; generated public contracts are unchanged by this task.

Independent review fixed lexical-internal punctuation, supplementary Han boundaries, and preservation of existing readings. A live Cerebras smoke exposed target-language romanization on Latin source; prompts now name source-only pronunciation, normalization filters the annotation, and raw evaluation flags the violation. Duplicate root/lemma and gloss/translation values are removed deterministically. Semantic segmentation in no-space scripts still requires model evaluation; deterministic checks enforce source coverage, grapheme integrity, and boundaries inside space-delimited words.


## Live Synthetic Evaluation

Provider: cerebras; model: gpt-oss-120b. Nine requests completed with first-response contract checks passing, one request per case, no retries. Each case ran once; timings are smoke evidence, not a speedup claim or a stable latency percentile. SDK completion usage is provider-reported and can include reasoning even when its separate counter is zero. Lyrics cases call the agent directly; their full database publication workflow is covered by regression tests.

| Case | Elapsed ms | Prompt tokens | Completion tokens |
| --- | --- | --- | --- |
| heldout-tokenization-mandarin-batch | 785 | 808 | 652 |
| heldout-analysis-japanese-french | 1000 | 1215 | 1296 |
| heldout-romanization-mixed-script | 834 | 825 | 691 |
| heldout-enrichment-spanish-german | 1137 | 1310 | 1562 |
| heldout-card-nonzero-phrasal | 618 | 983 | 614 |
| heldout-edited-multiword | 1130 | 1360 | 926 |
| heldout-lyrics-repeated-line | 495 | 1058 | 307 |
| heldout-lyrics-excerpt | 604 | 1052 | 282 |
| heldout-lyrics-partial-enabled | 814 | 1373 | 640 |

Semantic spot-check: source text/order, negation in whole-cue translation, mixed-script readings, nonzero token identity, instruction-like dialogue as data, and partial/full lyric structures behaved as expected in these cases. Card idiom components can still mix literal translations with contextual notes; bilingual review is still required before claiming teaching-quality gains. No prompt examples copy the held-out cases. Raw synthetic reports were written only to the local temporary directory.
