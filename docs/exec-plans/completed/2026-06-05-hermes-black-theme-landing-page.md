# Plan: Hermes Black Theme Landing Page

Status: completed
Owner: agent
Created: 2026-06-05
Last updated: 2026-06-05

## Goal

Implement the supplied `hermes_design_doc.docx` as the Laravel public landing page, following the Hermes Desktop black-theme specification as closely as the existing Blade/CSS application shape allows.

The result should render a fixed black navigation bar, hero art/video, platform downloads, six numbered feature rows, Nous Portal section, and footer with the exact copy and asset direction from the document. The root homepage should use this landing page, and `/desktop` should be available as the in-page nav target described by the design plan.

## Scope

- In scope:
  - Extract and follow the design document at `C:/Users/jaden/Desktop/hermes_design_doc.docx`.
  - Add or update the active execution plan before implementation.
  - Create a branch from `main` for the work.
  - Replace the Laravel homepage content with the Hermes Desktop black-theme landing page.
  - Add a `/desktop` route alias for the same landing page.
  - Add scoped CSS and JavaScript for the black-theme landing page.
  - Use the document's image/video/download URLs or local copies of the referenced image assets.
  - Update feature tests and durable frontend/design docs for the changed landing-page behavior.
  - Run harness validation, browser/visual checks, self-review, and PR verification.
- Out of scope:
  - Backend API, billing, queue, transcription, extension runtime, auth, or account workflow changes.
  - Downloading large installer/video binaries into the repository.
  - Reworking pricing, legal, dashboard, or auth pages into the Hermes visual system.
  - Final Lighthouse optimization beyond direct lazy loading and avoiding avoidable broken assets.

## Acceptance Criteria

- [x] `GET /` and `GET /desktop` render the Hermes Desktop landing page.
- [x] The page contains the specified section order: nav, hero, downloads, features, Nous Portal, footer.
- [x] Exact copy strings from the design document are present, including the three-line H1, portal copy, footer version, and copyright.
- [x] macOS and Windows download CTAs point at the Hermes asset download URLs, and Linux points at the docs/install route described by the plan.
- [x] The six feature rows use the document's labels, headings, body copy, and image assets.
- [x] Landing-page CSS is scoped so existing non-home marketing, auth, dashboard, and job-detail pages keep rendering.
- [x] Navbar scroll state, CTA scramble, and feature reveal behavior are implemented without a frontend build step.
- [x] Relevant Laravel feature tests cover the new homepage and `/desktop` alias.
- [x] Desktop and mobile browser checks show the page renders without obvious overflow, blank hero media, or console errors.
- [x] `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1` pass before handoff.

## Relevant Context

- Source design document: `C:/Users/jaden/Desktop/hermes_design_doc.docx`.
- Product docs: `docs/product-specs/index.md`, `docs/DESIGN.md`, `docs/FRONTEND.md`.
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`.
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-06-05-dark-academia-stitch-website-refactor.md`.
- Current implementation surface: `app/backend/resources/views/marketing/home.blade.php`, `app/backend/resources/views/layouts/site.blade.php`, `app/backend/public/css/site.css`, `app/backend/routes/web.php`, `app/backend/tests/Feature/SaasWebsiteAndSeoTest.php`.
- Known risks:
  - The supplied plan intentionally conflicts with the current dark-academia homepage direction; this branch treats the user's design document as the explicit override for the landing page only.
  - The document references Hermes/Nous assets and brand copy that do not match the repository product name. Implementing the plan verbatim means the homepage becomes a Hermes Desktop page.
  - External image/video/installer assets can change or disappear. Small referenced image assets should be copied locally when feasible; large video/installers should remain remote links.
  - CSS changes must not degrade existing auth/account/public content pages.

## Implementation Steps

- [x] Inspect current state.
- [x] Extract the Word design document and identify required sections, assets, copy, and interactions.
- [x] Create branch `codex/hermes-design-plan` from `main`.
- [x] Confirm or refine acceptance criteria.
- [x] Run baseline harness validation.
- [x] Download/copy small referenced image assets or choose stable external URLs when local copy is not feasible.
- [x] Implement the landing page Blade structure.
- [x] Add scoped CSS for the black-theme landing page.
- [x] Add landing-page JavaScript for scroll state, CTA scramble, and feature reveals.
- [x] Add `/desktop` route alias and update SEO/test expectations.
- [x] Update design/frontend docs for the landing-page override.
- [x] Run targeted Laravel tests.
- [x] Run browser visual checks on desktop and mobile.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Run full harness validation and record evidence.
- [x] Complete review notes and archive this plan.

## Validation Plan

Commands:

```powershell
cd app\backend
php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php
cd ..\..
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: targeted website feature tests, full harness checks.
- Screenshots or video: desktop and mobile landing page screenshots after implementation.
- Logs: browser console should not report landing-page errors.
- Metrics or traces: not required unless implementation changes runtime behavior.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-05 | Use `codex/hermes-design-plan` for the branch. | User requested a branch off `main`; the repo convention uses the `codex/` prefix. |
| 2026-06-05 | Treat `hermes_design_doc.docx` as the source of truth for the landing page. | User explicitly asked to follow the design plan as closely as possible. |
| 2026-06-05 | Keep the Hermes implementation scoped to the homepage plus `/desktop`. | The document is a single landing page, while the repository has existing pricing, legal, auth, dashboard, and account surfaces that are outside the requested design plan. |
| 2026-06-05 | Use Context7 Laravel 13 docs because Boost `search-docs` is not callable in this session. | `app/backend/AGENTS.md` requires Laravel doc lookup before code changes; Context7 confirmed Blade, `asset()`, `route()`, and feature-test patterns. |
| 2026-06-05 | Keep large video and installer downloads remote. | The document references large distributable assets; copying them into this repo would add unnecessary binary weight. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-05 | Branch created from `main`; existing untracked `docs/experiments/` left untouched. | `git switch -c codex/hermes-design-plan`; `git status --short --branch`. |
| 2026-06-05 | Extracted the Word document and found a complete Hermes Desktop black-theme landing-page specification with exact copy, section order, assets, and interactions. | UTF-8 DOCX extraction from `C:/Users/jaden/Desktop/hermes_design_doc.docx`. |
| 2026-06-05 | Harness shape and baseline validation passed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` passed with contracts, 190 backend tests, 79 extension tests, TypeScript compile, and WXT build. |
| 2026-06-05 | Read relevant project docs and Laravel docs before editing. | `AGENTS.md`, `ARCHITECTURE.md`, `docs/DESIGN.md`, `docs/FRONTEND.md`, `docs/references/project-guardrails.md`, `docs/quality/golden-principles.md`, Context7 `/laravel/docs/__branch__13.x`. |
| 2026-06-05 | Downloaded the document's small WebP assets into the Laravel public tree and kept video/installers remote. | `app/backend/public/img/desktop/`; remote MP4/DMG/EXE URLs remain in Blade. |
| 2026-06-05 | Implemented the scoped Hermes landing page, `/desktop` alias, SEO metadata, sitemap entry, JS interactions, and Laravel feature coverage. | `app/backend/resources/views/layouts/hermes-desktop.blade.php`; `app/backend/resources/views/marketing/home.blade.php`; `app/backend/public/css/site.css`; `app/backend/public/js/hermes-desktop.js`; `app/backend/tests/Feature/SaasWebsiteAndSeoTest.php`. |
| 2026-06-05 | Browser QA passed at desktop and mobile widths. | Screenshots: `docs/design-assets/hermes-black-theme/qa/landing-desktop.png`, `landing-mobile.png`, `landing-feature-scroll-desktop.png`; DOM checks found scroll width equal to viewport, 6 feature rows, 3 platform cards, no failed images, and the Hermes MP4 source present. |
| 2026-06-05 | Required validation passed. | `vendor/bin/pint --dirty --format agent`; `php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php` (8 passed, 135 assertions); `.\scripts\agent\doc-gardening.ps1`; `.\scripts\agent\check.ps1` (191 backend tests, 79 extension tests, contracts, TypeScript, WXT build); `.\scripts\agent\verify-pr.ps1`. |

## Completion Notes

- What changed: Replaced the Laravel homepage with a scoped Hermes Desktop black-theme landing page from the supplied Word design document; added `/desktop`; copied referenced WebP assets locally; added no-build JS for navbar scroll state, CTA scramble, and feature reveals; updated SEO/sitemap/tests/docs and captured browser evidence.
- Validation results: Pint passed, targeted website feature tests passed, desktop/mobile browser checks passed, `doc-gardening` found no issues, full harness `check.ps1` passed, and `verify-pr.ps1` passed.
- Simplicity/readability review: The implementation keeps the existing Laravel Blade route/controller/test shape, avoids adding a frontend build step, scopes the new page through a dedicated layout/body class, and leaves non-home marketing/account surfaces untouched.
- Residual risk: The design document's Hermes/Nous brand and assets intentionally do not match the repository's normal Transcribed Subtitle Extension product direction. This branch implements that requested override verbatim for the landing page only.
- Follow-up debt: None for the requested scope.

