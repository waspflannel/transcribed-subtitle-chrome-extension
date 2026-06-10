# Plan: Learning Upgrade 02 - Vocabulary Sentence Mining Anki Export

Status: planned
Owner: agent
Created: 2026-06-03
Last updated: 2026-06-03

## Goal

Add the durable learning loop missing from the current product: learners can save words and sentences from generated YouTube subtitle tracks, revisit them by review state, export them to CSV, and send them directly to Anki through local AnkiConnect.

This phase converts one-off subtitle understanding into a reusable personal study library.

## Scope

- In scope:
  - Account-scoped saved study-item backend data for word and sentence items.
  - Extension API contracts for save, list/filter, update review state, delete, and CSV export.
  - Save actions from overlay token detail, active cue controls, and the side-panel Transcript view.
  - Study Library side-panel view or account dashboard surface for saved items, filters, review state, and export controls.
  - Direct extension-local AnkiConnect adapter for localhost deck/model discovery, duplicate checks, and note creation.
  - Clear Anki unavailable/misconfigured failure copy.
  - Contract, backend, extension, and UI tests.
- Out of scope:
  - Backend-generated APKG packages.
  - Full spaced-repetition scheduling engine beyond simple review states.
  - Audio clip or screenshot export unless separately accepted in a later phase.
  - Freeform note template editing beyond selecting supported Anki deck/model and field mapping defaults.
  - Content discovery and non-YouTube platforms.

## Acceptance Criteria

- [ ] Authenticated learners can save a word token with video ID, track ID, cue ID, token index, source text, normalized text, source/target languages, optional romanization, translation/gloss, and safe cue context.
- [ ] Authenticated learners can save a sentence/cue with source text, optional romanization, optional translation, timestamps, source/target languages, YouTube URL, and safe metadata.
- [ ] Duplicate saves are idempotent per account and item identity.
- [ ] Saved items can be listed, filtered by type/language/review state/video, updated to known/learning/ignored, and deleted.
- [ ] CSV export includes only safe study fields and never includes provider prompts, raw provider payloads, raw audio paths, or full hidden transcripts beyond saved item content.
- [ ] AnkiConnect export discovers deck/model options, validates default fields, creates notes, marks exported items, and handles unavailable AnkiConnect with stable user-facing copy.
- [ ] Extension UI exposes save/export state without blocking existing subtitle generation or token enrichment.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`, `docs/SECURITY.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
  - `docs/exec-plans/active/00-learning-upgrade/01-keyboard-transcript-accessibility-foundation.md`
  - `docs/exec-plans/active/00-learning-upgrade/00-roadmap-index.md`
- External references:
  - `https://github.com/FooSoft/anki-connect`
- Known risks:
  - Saved-item schemas can grow quickly; v1 should persist only fields needed for review and export.
  - Local AnkiConnect calls may require host permissions and clear user setup guidance.
  - Anki field mapping can become a large customization feature; start with one default model mapping and safe validation.

## Planned Interfaces

- Backend API:
  - `GET /v1/study-items`
  - `POST /v1/study-items`
  - `PATCH /v1/study-items/{studyItemId}`
  - `DELETE /v1/study-items/{studyItemId}`
  - `GET /v1/study-items/export.csv`
- Contract shapes:
  - `StudyItem`, `StudyItemListResponse`, `CreateStudyItemRequest`, `UpdateStudyItemRequest`, and CSV export expectations.
- Review states:
  - `new`, `learning`, `known`, `ignored`
- Anki export:
  - Extension-local `AnkiConnect` requests to localhost, not backend-proxied.
  - Default note fields: source text, target text, romanization, word, context sentence, video URL, timestamp, language route, and tags.

## Implementation Steps

- [ ] Inspect current auth, contracts, migrations, API controller, extension API client, side panel, overlay, and Transcript view state.
- [ ] Create account-scoped study-item migrations, model, factories, policies or ownership checks, and cleanup posture.
- [ ] Add canonical JSON schemas, fixtures, OpenAPI entries, generated contract types, and backend contract validation tests.
- [ ] Add Laravel requests/controllers/services for save, list/filter, update state, delete, and CSV export.
- [ ] Add extension API methods and runtime messages for saving items and syncing saved/export state.
- [ ] Add save controls to token detail, active cue controls, and the side-panel Transcript view.
- [ ] Add Study Library UI for saved items, filters, review states, delete, CSV export, and Anki export.
- [ ] Add extension-local AnkiConnect adapter for deck/model discovery, duplicate checks, note creation, and failure copy.
- [ ] Add backend, contracts, extension, and UI tests.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Update product/frontend/security/architecture docs and quality score if needed.
- [ ] Run validation and record evidence.
- [ ] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; php artisan test --compact --filter=StudyItem; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: contracts validation, backend study-item feature/unit tests, extension save/export tests, full harness.
- Screenshots or video: save word, save sentence, Study Library filters, CSV export, AnkiConnect success, AnkiConnect unavailable error.
- Logs: no generated content payloads in logs; only safe event names and IDs.
- Metrics or traces: optional save/export counts without raw study content.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-03 | Persist saved study items in the backend under authenticated accounts. | Learners expect saved study data to survive local extension state clearing and work across devices later. |
| 2026-06-03 | Use direct local AnkiConnect for v1 export. | This matches power-user expectations and avoids backend APKG generation complexity. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-03 | Plan created. | `docs/exec-plans/active/00-learning-upgrade/02-vocabulary-sentence-mining-anki-export.md` |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:

