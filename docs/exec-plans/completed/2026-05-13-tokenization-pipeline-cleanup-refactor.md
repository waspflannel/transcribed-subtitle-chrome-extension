# Plan: Tokenization Pipeline Cleanup Refactor

Status: completed
Owner: agent
Created: 2026-05-13
Last updated: 2026-05-13

## Goal

Clean up the subtitle transcription and tokenization pipeline before making another tokenization bug fix. The current implementation has multiple overlapping approaches: an OpenAI tokenizer agent, a large local tokenizer/validator, source span validation, fallback token generation, WebVTT parse-back indirection, and single-implementation provider interfaces. This plan narrows the runtime path so future tokenization fixes happen in the right place.

The intended end state is simple: ElevenLabs Scribe produces timed transcript segments, backend normalization removes provider artifacts, the OpenAI tokenization agent chooses learner token boundaries, backend code validates only the shape and basic safety of that output, and the extension renders either valid clickable tokens or plain subtitles.

## Scope

- In scope: backend transcription normalization, WebVTT generation/parsing cleanup, tokenization agent schema simplification, local tokenizer removal/reduction, provider abstraction cleanup, processing/cache version bumps, tests, docs, and observability updates.
- Out of scope: extension overlay redesign, new Japanese tokenizer dependencies, switching away from ElevenLabs Scribe, switching away from Laravel AI structured agents, queue infrastructure, and public API contract changes unless cleanup proves a contract field is obsolete.

## Current Findings

- Tokenization is not being skipped. `SubtitleJobService` calls `TranslationAnalysisProvider::tokenize()` after draft cues are created, and local logs show tokenization completing with many tokenless cues.
- The latest symptom is mostly token rejection, not a missing tokenization step. Example local evidence: a Japanese run completed with 50 cues, 4 tokens, and 48 tokenless cues.
- `LearningTokenTokenizer` has grown into an accidental local tokenizer and morphology gate. It still contains dead fallback token generation through `tokenStubs()`.
- `CueTokenizationAgent` is the correct owner of token boundary decisions, but its current schema asks for `sourceStart` and `sourceEnd` offsets. Generated offset precision is brittle, especially after transcript text normalization.
- `ScribeTranscriptNormalizer` currently joins Scribe word tokens with spaces. For Japanese, Scribe can return character-level timed tokens, producing cue text like `み ん な と の 大 切 な 場 所`.
- WebVTT is still required because the extension consumes `track.webVtt` through browser-native text tracks. The likely obsolete piece is `WebVttTranscriptParser`, because Scribe normalization builds segments, serializes them to WebVTT, then parses that WebVTT back into segments.
- `TranslationAnalysisProvider` has one implementation. It currently adds indirection without a real second provider. Tests can bind or fake the concrete Laravel AI provider if needed.
- `TranscriptionService` also has one implementation, but it represents a cleaner external provider boundary around audio transcription. Keep it unless the cleanup shows the test seam or abstraction cost is not justified.

## Saved Refactors From Repo-Wide Scan

- Backend Laravel scaffold residue is still present even though the product is API-only: `resources/views/welcome.blade.php`, `resources/css/app.css`, `resources/js/app.js`, backend `vite.config.js`, backend `package.json` Vite scripts, the `routes/console.php` `inspire` command, stock `User` model/factory, auth/password/session migrations, and queue/job migrations/config. Audit and remove the pieces that are not needed for the synchronous extension-backed API, while keeping database cache tables if they remain the selected cache store.
- Backend exposes both the explicit `/health` route and Laravel's configured `/up` health endpoint. Keep one documented health endpoint unless both have a real operational use.
- The subtitle processing/cache version story is scattered across `SubtitleJobService` processing-version constants and `LearningTokenEnrichmentService::cacheKey()`'s hard-coded token cache version. Centralize or at least document the cache-busting surface so transcript normalization, tokenization, romanization, and clicked-token enrichment cannot drift.
- `YouTubeAudioSource::throwProcessFailure()` stores the full process command plus stdout/stderr excerpts in exception context, and `SubtitleWorkflowLogger` spreads exception context directly into logs. This may conflict with the repo rule against raw audio paths and overly detailed provider/process diagnostics in logs. Replace it with sanitized stage, exit code, binary, and bounded reason fields.
- Contract and generated TypeScript descriptions still say `on_demand` returns "clickable token stubs". That wording is stale now that token boundaries come from the tokenizer agent and fallback should be transcript-only `tokens: []`. Update schema descriptions, generated types, and docs when the tokenization cleanup lands.
- `LaravelAiTranslationAnalysisProvider` has become a large mixed-responsibility class: prompt construction, Laravel AI invocation, structured-response adaptation, retry handling, tokenization validation, romanization preservation, enrichment preservation, and clicked-token validation all live together. After the tokenizer cleanup removes span-heavy validation, collapse duplicated instructions and keep only the smallest split that makes the remaining AI boundary readable.
- Tokenization rules are duplicated between `CueTokenizationAgent::instructions()` and `LaravelAiTranslationAnalysisProvider::tokenizationPrompt()`. Keep stable agent-role instructions and per-request JSON data in one coherent place so future prompt changes cannot silently diverge.
- Extension progress is modeled in two places: backend jobs expose `stage`/`progressPercent`, while `background.ts` also runs a local estimated-progress timer with hard-coded stage timings. Revisit whether the extension can use backend progress plus a simple local loading state, or at least share one stage/progress mapping to avoid misleading UI drift.
- `app/extension/utils/messages.ts` has a type guard named `isRuntimeMessage()`, but it only validates the `type` string and not message payloads such as settings patches or clicked-token IDs. Tighten message-boundary validation or rename/scope the guard so callers do not assume payloads are already trusted.
- `app/extension/entrypoints/popup/main.ts` defines a local `PopupRequest` union that duplicates the shared runtime message contract. Replace it with `Extract<RuntimeMessage, ...>` or a shared helper when touching popup messaging so request shapes do not diverge.
- `app/extension/utils/overlay.ts` is over 700 lines and mixes Shadow DOM lifecycle, inline CSS, string-rendered markup, token interaction state, and formatting helpers. Keep behavior stable, but split only the pieces with clear ownership if future overlay work has to touch this file.

## Acceptance Criteria

- [x] The subtitle generation path is documented as one current product path, not several overlapping historical paths.
- [x] `LearningTokenTokenizer` no longer creates fallback tokens or implements language-specific tokenization logic.
- [x] Tokenization boundary decisions are owned by `CueTokenizationAgent`.
- [x] Backend token validation is reduced to structural and boundary-safety checks only.
- [x] Tokenization structured output no longer requires source character spans unless a concrete runtime need remains.
- [x] Japanese and other no-space-script transcript display text no longer preserves provider-created character spacing.
- [x] WebVTT output remains available for the extension, but Scribe normalization does not parse back WebVTT that the backend just generated.
- [x] `TranslationAnalysisProvider` is removed unless a concrete second implementation is identified during implementation.
- [x] Tests prove default subtitle generation still completes when tokenization fails or returns invalid output.
- [x] Tests prove romanization and enrichment preserve valid tokenizer boundaries and skip tokenless cues safely.
- [x] Processing/cache versions are bumped so stale cached bad tracks are not reused.
- [x] Docs and logs reflect the simplified workflow without logging raw transcript text, prompts, or provider secrets.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Review policy: `docs/REVIEW.md`
- Backend routing: `docs/references/boost-skill-routing.md`
- Related plans:
  - `docs/exec-plans/completed/2026-05-11-clean-elevenlabs-scribe-rewrite.md`
  - `docs/exec-plans/completed/2026-05-11-ai-first-tokenization-and-romanization-revamp.md`
  - `docs/exec-plans/completed/2026-05-12-cheap-tokenizer-agent-pipeline.md`
  - `docs/exec-plans/completed/2026-05-12-simple-ai-tokenization-pipeline-refactor.md`
  - `docs/exec-plans/completed/2026-05-12-tokenization-quality-gate-upgrade.md`
- Known risks:
  - Removing too much validation could allow bad clickable tokens through again.
  - Keeping too much validation will keep causing tokenless cues.
  - Normalizing no-space scripts too aggressively could remove intentional spacing in mixed-language cues.
  - Deleting abstractions will require careful test updates so behavior stays covered.

## Implementation Steps

- [x] Inspect the current dirty worktree and preserve unrelated user or generated changes.
- [x] Load backend harness guidance and relevant Laravel Boost skill files before editing backend code.
- [x] Add or update an execution baseline with focused tests that capture the current tokenless-cue failure mode.
- [x] Refactor `ScribeTranscriptNormalizer` to produce segments directly, generate WebVTT from those segments, and normalize no-space-script character spacing.
- [x] Remove `WebVttTranscriptParser` if it is no longer needed after direct Scribe segment generation.
- [x] Simplify `CueTokenizationAgent` structured output to token index and text only, unless a non-negotiable validation need for spans remains.
- [x] Replace `LearningTokenTokenizer` with a smaller generated-output validator, or inline the small validation into `LaravelAiTranslationAnalysisProvider` if that reads better.
- [x] Delete `tokenStubs()` and any fallback token-generation behavior.
- [x] Remove `TranslationAnalysisProvider` and inject `LaravelAiTranslationAnalysisProvider` directly, unless a concrete second provider is identified.
- [x] Update feature and unit tests to bind concrete services or use focused fakes without restoring unnecessary interfaces.
- [x] Bump processing/cache versions for changed transcript normalization and tokenization behavior.
- [x] Update architecture, reliability, observability, and quality docs where the workflow changed.
- [x] Run focused backend tests, full backend tests, extension tests, contracts check, and repository harness check.
- [x] Self-review the diff for accidental complexity before moving this plan to completed.

## Proposed Runtime Flow

```text
YouTube URL
  -> backend acquires temporary audio
  -> ElevenLabs Scribe returns word-level timestamp payload
  -> ScribeTranscriptNormalizer builds TimestampedTranscriptSegment objects directly
  -> backend generates WebVTT from those segments for browser TextTrack sync
  -> TimestampedSubtitleTrackGenerator creates draft cues
  -> CueTokenizationAgent returns learner token boundaries
  -> backend validates shape and basic boundary safety
  -> optional CueRomanizationAgent preserves accepted token boundaries
  -> optional CueEnrichmentAgent adds learning metadata without retokenizing
  -> extension renders valid tokens, or plain source text when tokens are empty
```

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend
vendor\bin\pint --dirty --format agent
php artisan test --compact tests\Unit\ScribeTranscriptNormalizerTest.php tests\Unit\CueEnrichmentServiceTest.php tests\Unit\TimestampedSubtitleTrackGeneratorTest.php tests\Feature\SubtitleJobApiTest.php
php artisan test --compact
Pop-Location

Push-Location .\packages\contracts
npm run check
Pop-Location

Push-Location .\app\extension
npm test
npm run compile
Pop-Location

.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: focused backend transcription/tokenization/job tests, full backend suite, contracts check, extension tests/compile, harness check.
- Logs: tokenization started/completed counts, tokenless cue counts, validation failure reason counts if added.
- Screenshots or video: useful after cleanup if a Japanese YouTube sample is regenerated.
- Metrics or traces: not required for this synchronous path.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-13 | Clean up the pipeline before another tokenization bug fix. | The current behavior is hard to reason about because agent tokenization, local fallback tokenization, local morphology checks, and span validation overlap. |
| 2026-05-13 | Keep WebVTT as an output format. | The extension uses browser-native `TextTrack` behavior from `track.webVtt`. |
| 2026-05-13 | Treat `WebVttTranscriptParser` as removable unless implementation proves otherwise. | Scribe normalization already creates timed segments, then serializes and reparses them. |
| 2026-05-13 | Prefer removing `TranslationAnalysisProvider`. | There is only one implementation, and the interface currently obscures the actual AI provider flow. |
| 2026-05-13 | Keep `TranscriptionService` under review rather than deleting it immediately. | It is a small external-provider seam around audio transcription and may still be useful for tests and future provider replacement. |
| 2026-05-13 | Move token boundary intelligence back to the OpenAI tokenizer agent. | A 400-line local tokenizer contradicts the AI-first design and is now causing tokenless cues. |
| 2026-05-13 | Replace span/morphology token validation with source-order structural validation. | Backend validation now prevents malformed or hallucinated clickable tokens without rejecting valid language-specific boundaries that the tokenizer agent should own. |
| 2026-05-13 | Defer unrelated saved refactors from the repo-wide scan. | The scaffold, health-route, log-sanitization, and extension messaging/overlay cleanup items remain saved debt; widening this implementation would have mixed independent refactors into the tokenization change. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-13 | Plan created from codebase audit and user cleanup request. | Runtime references in `SubtitleJobService`, `ScribeTranscriptNormalizer`, `CueTokenizationAgent`, `LearningTokenTokenizer`, `WebVttTranscriptParser`, and local Laravel logs. |
| 2026-05-13 | Added repo-wide backend and extension refactor findings before implementation. | Full non-library scan of `app/backend`, `app/extension`, `packages/contracts`, and relevant docs; evidence in scaffold files, route list, `SubtitleJobService`, `LearningTokenEnrichmentService`, `YouTubeAudioSource`, `SubtitleWorkflowLogger`, `LaravelAiTranslationAnalysisProvider`, `CueTokenizationAgent`, extension background/popup/message/overlay modules, and contract schemas. |
| 2026-05-13 | Loaded phased-implementation-v2, project docs, backend AGENTS guidance, Laravel Boost skill routing, local backend AI skills, and Context7 Laravel AI/WXT docs. | `AGENTS.md`, `docs/references/boost-skill-routing.md`, `app/backend/AGENTS.md`, `php artisan boost:list-skills`, local skill files, Context7 `/laravel/ai` and `/websites/wxt_dev`. |
| 2026-05-13 | Baseline repository harness passed before edits. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` passed with contracts check, backend tests, extension tests/compile/build. |
| 2026-05-13 | Implemented tokenizer cleanup and focused tests. | Removed `LearningTokenTokenizer`, `TranslationAnalysisProvider`, and `WebVttTranscriptParser`; added `LearningTokenOutputValidator`; simplified `CueTokenizationAgent` schema; updated Scribe normalization, provider injection, versions, docs, contracts, and focused tests. |
| 2026-05-13 | Final validation passed. | `vendor\bin\pint --dirty --format agent`; focused backend tests: 66 passed; `php artisan test --compact`: 82 passed; `packages/contracts npm run check`; `app/extension npm test`: 38 passed; `app/extension npm run compile`; `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`. |

## Completion Notes

- What changed: Scribe normalization now builds timed segments directly and generates WebVTT from them, no-space-script artifact spacing is collapsed before display, tokenization structured output is cue identity plus token index/text only, local fallback token generation and language-specific morphology checks were removed, token output validation is structural/source-order only, the single-implementation `TranslationAnalysisProvider` interface was removed, processing/cache versions were bumped, and docs/contracts were updated.
- Validation results: `vendor\bin\pint --dirty --format agent` passed; focused backend tests passed with 66 tests/376 assertions; full backend tests passed with 82 tests/437 assertions; `packages/contracts npm run check` passed; extension `npm test` passed with 9 files/38 tests; extension `npm run compile` passed; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed.
- Simplicity/readability review: The backend now has one tokenization boundary owner and one small validator. The remaining provider class is still large, but this slice removed the span/morphology/fallback-token branches without splitting unrelated responsibilities mid-refactor.
- Residual risk: The tokenizer agent can still choose low-quality but source-present boundaries because backend morphology gates are intentionally gone; product correctness now depends on prompt/schema quality plus retry for malformed or hallucinated output.
- Follow-up debt: Keep the saved repo-wide cleanup list in this plan as follow-up debt: Laravel scaffold residue, health-route duplication, centralized version/cache-busting surface, log sanitization in audio process failures, provider class responsibility split, extension progress mapping, message validation, popup message type reuse, and overlay module splitting.
