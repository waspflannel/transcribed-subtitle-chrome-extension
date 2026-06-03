# Plan: YouTube Shorts Support

Status: completed
Owner: agent
Created: 2026-06-02
Last updated: 2026-06-02

## Goal

Support YouTube Shorts URLs (`https://www.youtube.com/shorts/{videoId}`) in the existing extension and backend flow without changing the subtitle generation pipeline, cache key, billing behavior, queueing, or persistence model.

Keep the popup navigation stable by organizing the existing Jobs panel into Videos and Shorts sections. Use the user-facing label "Shorts".

## Scope

- In scope: extension URL parsing, content-script matching and active video selection, popup job-history grouping, backend create-job URL validation, contract descriptions, product/architecture docs, and focused tests.
- Out of scope: Instagram/Facebook Reels, TikTok, new database fields, API media-type fields, pipeline changes, billing changes, and provider changes.

## Acceptance Criteria

- [x] `youtube.com/watch?v={id}` and `youtube.com/shorts/{id}` both produce supported page state with the same canonical 11-character `videoId` and a `mediaKind`.
- [x] Extension content script runs on Shorts pages and binds generated WebVTT tracks to the active Shorts video element.
- [x] Backend `POST /v1/subtitle-jobs` accepts matching HTTPS Shorts URLs and rejects mismatched, malformed, or non-HTTPS Shorts URLs.
- [x] Popup Jobs panel groups stored job history into Videos and Shorts without adding top-level tabs or API fields.
- [x] Product docs and architecture docs no longer claim first-release support is watch-page-only.
- [x] Targeted extension, contracts, backend, and harness validation passes or records clear preexisting failures.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: user-provided YouTube Shorts support plan in the 2026-06-02 Codex thread.
- Known risks: YouTube Shorts pages may contain multiple `<video>` elements; keep active-video selection direct and testable rather than adding broad DOM polling or platform abstractions.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement extension page detection, content matching, and active-video binding.
- [x] Implement popup Videos/Shorts grouping.
- [x] Implement backend Shorts URL validation.
- [x] Update contract descriptions and durable docs.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\extension; npm test; npm run compile; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
Push-Location .\app\backend; php artisan test --compact --filter=SubtitleJobApiTest; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests:
  - Baseline `.\scripts\agent\check.ps1`: passed before feature code edits.
  - `Push-Location .\app\extension; npm test; Pop-Location`: 16 files / 68 tests passed.
  - `Push-Location .\app\extension; npm run compile; Pop-Location`: passed.
  - `Push-Location .\packages\contracts; npm run check; Pop-Location`: passed.
  - `Push-Location .\app\backend; php artisan test --compact --filter=SubtitleJobApiTest; Pop-Location`: 55 tests / 420 assertions passed.
  - `Push-Location .\app\backend; php artisan test --compact --filter=SaasWebsiteAndSeoTest; Pop-Location`: 7 tests / 103 assertions passed.
  - `.\scripts\agent\doc-gardening.ps1`: no findings.
  - `.\scripts\agent\check.ps1`: passed after implementation; backend 190 tests / 1102 assertions passed, extension 16 files / 68 tests passed, contracts check passed, extension compile/build passed.
  - `.\scripts\agent\verify-pr.ps1`: passed.
  - `git diff --check`: no whitespace errors; Windows line-ending warnings only.
- Screenshots or video:
  - Not captured; Jobs grouping is covered by helper tests rather than a browser smoke test.
- Logs:
  - None required.
- Metrics or traces:
  - None required.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-02 | Treat Shorts as a YouTube URL shape, not a new platform. | The current pipeline, cache key, audio acquisition, and generated tracks already key on the canonical YouTube video ID. |
| 2026-06-02 | Classify jobs in the popup from stored `youtubeUrl`, not a persisted media-type field. | This satisfies current organization needs without adding schema/API surface for a value no backend workflow reads. |
| 2026-06-02 | Keep one Jobs tab and split its contents into Videos and Shorts sections. | The popup already has several top-level tabs and the user accepted segmented lists. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-02 | Plan created and scoped after inspecting extension parser/content/popup, backend validation/service/resource, contract schemas, and docs. | `git status --short`; `php artisan boost:list-skills --no-interaction`; relevant file reads. |
| 2026-06-02 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed. |
| 2026-06-02 | Implemented extension Shorts parsing, content matching, active-video selection, and popup Videos/Shorts grouping. | `npm test -- youtube job-history-media youtube-video` passed; `npm run compile` passed after fixture updates. |
| 2026-06-02 | Implemented backend Shorts URL validation and refreshed contract/product/user-facing copy. | `php artisan test --compact --filter=SubtitleJobApiTest` passed; `php artisan test --compact --filter=SaasWebsiteAndSeoTest` passed; `npm run check` passed in `packages/contracts`. |
| 2026-06-02 | Completed final validation and self-review. | `.\scripts\agent\doc-gardening.ps1`, `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, and `git diff --check` passed. |

## Completion Notes

- What changed: Added Shorts parsing and media-kind state, Shorts content-script injection, active video selection for multi-video Shorts pages, popup Videos/Shorts job grouping, backend Shorts URL validation, contract wording, and current-scope docs/user-facing copy.
- Validation results: Targeted extension, contracts, backend, website copy, doc gardening, full harness, PR verification, and diff whitespace checks passed.
- Simplicity/readability review: Shorts remain a YouTube URL shape using the existing `youtubeVideoId` cache key. No database field, API field, provider path, billing change, queue change, or platform abstraction was added.
- Residual risk: No live browser screenshot was captured against an actual YouTube Shorts page; selection behavior is covered by a focused active-video helper test.
- Follow-up debt: None.

