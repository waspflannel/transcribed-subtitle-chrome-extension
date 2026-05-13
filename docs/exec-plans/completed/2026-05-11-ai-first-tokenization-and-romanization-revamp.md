# Plan: AI First Tokenization And Romanization Revamp

Status: completed
Owner: agent
Created: 2026-05-11
Last updated: 2026-05-12

## Goal

Revamp transcript-first token generation so non-space-delimited languages, especially Japanese, render learner-friendly word or short-phrase token cards instead of per-character cards.

Use the existing Laravel AI/OpenAI enrichment path for context-aware tokenization and learner-standard romanization, while preserving the public track contract and keeping deterministic fallback behavior for provider failures.

## Scope

- In scope:
- Backend transcript-first tokenization fallback behavior.
- OpenAI/Laravel AI romanization prompt, schema, and output validation.
- Full-card enrichment tokenization guidance and validation alignment.
- Processing/cache version bumps to avoid reusing tracks with poor token boundaries.
- Backend tests and durable docs for the new behavior.
- Out of scope:
- Public API or JSON schema changes.
- Database migrations.
- New PHP system-extension requirements such as `ext-intl`.
- Extension overlay UI changes beyond existing rendering of improved token data.

## Acceptance Criteria

- [x] Transcript-first Japanese and similar no-space non-Latin cues produce grouped learner tokens, not one card per character or one broad clause chunk.
- [x] Romanization output can rebuild token boundaries while preserving cue identity and source characters in order.
- [x] Full-card enrichment uses the same learner-token guidance and rejects invalid token sequences.
- [x] Provider romanization failure still completes default generation with deterministic fallback tokens.
- [x] Processing versions and clicked-token cache version are bumped.
- [x] Contracts remain unchanged and pass validation.
- [x] Backend, extension, and harness checks pass.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/product-specs/release-readiness.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`, `docs/FRONTEND.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-11-clean-elevenlabs-scribe-rewrite.md`, `docs/exec-plans/completed/2026-05-11-transcription-overlay-ui-revamp.md`
- Known risks:
  - AI output may invent or reorder token text unless strictly validated.
  - Full-card and transcript-first paths can drift if token guidance is duplicated inconsistently.
  - Fallback behavior must avoid the exact Japanese character-splitting failure without pretending to be a full morphological analyzer.
  - Logs must keep prompt/transcript/token payloads out of structured events.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Slice 1: add deterministic tokenization helpers and no-space fallback grouping.
- [x] Slice 2: update romanization agent/schema/prompt so AI can return rebuilt token boundaries.
- [x] Slice 3: centralize token-sequence validation and apply it to romanization and full-card enrichment.
- [x] Slice 4: bump processing/cache versions and update docs.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: focused backend unit/feature tests, extension test/compile, contracts check, harness check.
- Screenshots or video: not required because overlay UI behavior is unchanged.
- Logs: confirm existing log events remain payload-free.
- Metrics or traces: not required for the synchronous generation path.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-11 | Use AI-first tokenization/romanization without requiring PHP `ext-intl`. | Local runtime lacks `intl`, and the existing product already depends on OpenAI/Laravel AI for non-Latin romanization. |
| 2026-05-11 | Use learner-standard pronunciation romanization by default. | The overlay is for language learners, so Hepburn/pinyin-style readability is more useful than strict reversible transliteration. |
| 2026-05-11 | Keep the public cue/token contract unchanged. | Better backend token data fixes the visible issue without API churn or extension UI work. |
| 2026-05-11 | Validate grouped CJK token text by preserving source character order while ignoring artificial spaces between CJK characters. | ElevenLabs can produce per-character word timestamps that insert spaces into the normalized transcript; accepting grouped tokens without those artificial spaces fixes the visible card issue while still rejecting invented or reordered text. |
| 2026-05-12 | Reject recognizable overbroad Japanese chunks such as `ねえ今思っていて`. | Preventing character cards is not enough; the overlay needs compact learner units like `ねえ`, `今`, and `思っていて` so the cards remain usable. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-11 | Plan created and refined from user-approved implementation plan; current code inspection shows regex token stubs split Japanese because `tokenStubs()` matches Unicode letter runs without morphology. | `TimestampedSubtitleTrackGenerator`, `LaravelAiTranslationAnalysisProvider`, `CueRomanizationAgent`, Context7 `/laravel/ai` structured-output/testing docs, Boost skill list. |
| 2026-05-11 | Implemented tokenizer fallback grouping, shared AI token validation, romanization boundary rebuilding, full-card token guidance, processing/cache version bumps, and focused Japanese/default/full-mode tests. | `Push-Location .\app\backend; php artisan test --compact tests/Unit/LearningTokenTokenizerTest.php tests/Unit/TimestampedSubtitleTrackGeneratorTest.php tests/Unit/CueEnrichmentServiceTest.php tests/Feature/SubtitleJobApiTest.php; Pop-Location` passed with 44 tests after the final validation tightening. |
| 2026-05-11 | Updated durable docs and completed validation. | `php artisan test --compact` passed with 72 tests; `npm run check` in contracts passed; extension `npm test` and `npm run compile` passed; `.\scripts\agent\check.ps1`, `.\scripts\agent\doc-gardening.ps1`, and `.\scripts\agent\verify-pr.ps1` passed. |
| 2026-05-12 | Tightened Japanese-specific prompts, deterministic fallback splitting, validation, cache versions, and tests after a live screenshot showed tokens still grouped as `ねえ今思っていて` and `あそうじゃな`. | `Push-Location .\app\backend; .\vendor\bin\pint --dirty --format agent; php artisan test --compact tests/Unit/LearningTokenTokenizerTest.php tests/Unit/CueEnrichmentServiceTest.php tests/Feature/SubtitleJobApiTest.php; Pop-Location` passed with 49 tests. |

## Completion Notes

- What changed: Added `LearningTokenTokenizer` for deterministic token stubs, CJK fallback grouping, common Japanese no-space fallback splitting, and shared generated-token validation; updated transcript-first romanization so AI can rebuild token boundaries; aligned full-card prompts with the same learner-token guidance; bumped track and clicked-token cache versions; added focused Japanese and validation tests; updated architecture, reliability, observability, product, design, and quality docs.
- Validation results: Focused backend tokenizer/enrichment/job tests passed with 49 tests after the Japanese overbroad-token tightening; full backend `php artisan test --compact` passed with 77 tests; contracts `npm run check` passed; extension `npm test` and `npm run compile` passed; `.\scripts\agent\check.ps1`, `.\scripts\agent\doc-gardening.ps1`, `.\scripts\agent\verify-pr.ps1`, and `git diff --check` passed.
- Simplicity/readability review: Kept the public contract and overlay unchanged, used one shared backend service for token fallback/validation, avoided new dependencies, and kept provider prompts purpose-specific.
- Residual risk: Live tokenization and romanization quality still depends on real OpenAI output and should be checked with public Japanese/Chinese/Korean videos during the release video matrix, especially to tune Japanese boundaries beyond the explicitly recognized fallback patterns.
- Follow-up debt: None added.
