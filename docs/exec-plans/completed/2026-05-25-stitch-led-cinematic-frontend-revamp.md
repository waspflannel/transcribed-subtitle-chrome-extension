# Plan: Stitch Led Cinematic Frontend Revamp

Status: completed
Owner: agent
Created: 2026-05-25
Last updated: 2026-05-25

## Goal

Revamp every user-facing frontend surface through a Stitch-led "Cinematic Study Console" visual direction while preserving the existing Laravel Blade and WXT/static DOM architecture.

The redesign should make the product feel like a premium video-native language-learning console: full-bleed, product-led marketing; dense and calm account surfaces; a compact extension popup; and a glass subtitle overlay that keeps the source/translation/token rail as the signature interaction.

## Scope

- In scope:
  - New Google Stitch project and design system for `AI Language Subtitles - Cinematic Study Console`.
  - Canonical Stitch screen references for marketing, account, popup, and overlay states.
  - Laravel Blade/CSS visual revamp for marketing, auth, dashboard, and job detail pages.
  - WXT popup CSS/markup refinements that preserve existing runtime hooks.
  - YouTube overlay Shadow DOM styling revamp.
  - Durable docs updates and visual validation evidence.
- Out of scope:
  - Backend API, billing, auth, route, contract, or message-shape changes.
  - React/Next.js/SPA migration or frontend framework introduction.
  - New product workflows, unsupported platforms, or vocabulary review.
  - Changing user-visible pricing/plan semantics beyond visual presentation.

## Acceptance Criteria

- [x] Stitch project, design system, generated screens, and downloaded references exist for this revamp.
- [x] Homepage first viewport is full-bleed, product-led, and clearly drives paid beta signup.
- [x] Public pages retain SEO metadata, routes, canonical behavior, and legal/support copy.
- [x] Dashboard and job detail remain dense, operational, and support-safe.
- [x] Popup keeps all `data-*` runtime hooks and core controls while matching the new visual system.
- [x] Overlay remains Shadow DOM isolated, compact, readable over video, and supports active cue, token detail, compact/top/bottom, and message states.
- [x] Desktop/mobile website and popup/overlay screenshots show no horizontal overflow, text overlap, or broken focus states.
- [x] Required Laravel, extension, harness, and PR-readiness validation passes.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`, `docs/DESIGN.md`.
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`.
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-22-saas-roadmap-phase-05-saas-website-and-seo.md`, `docs/exec-plans/completed/2026-05-21-saas-roadmap-phase-02-extension-frontend-upgrade.md`.
- Known risks: Stitch generation may produce non-implementable decorative concepts; keep implementation grounded in current Blade/WXT constraints and validated UI states.

## Implementation Steps

- [x] Create Stitch project, design system, canonical screens, and local design references.
- [x] Update `docs/DESIGN.md` with the accepted Cinematic Study Console system.
- [x] Implement website/account visual revamp in Blade/CSS and repo-owned assets.
- [x] Implement popup command-console styling while preserving DOM hooks and TS behavior.
- [x] Implement overlay glass rail styling while preserving render/data flow.
- [x] Run targeted tests/builds, browser screenshot QA, full harness checks, and self-review.
- [x] Record evidence, completion notes, and archive this plan.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: Laravel SaaS/auth feature tests; extension Vitest; TypeScript compile; WXT build; full harness check.
- Screenshots or video: website desktop/mobile, popup tabs/states, overlay active/token/error/position states.
- Logs: command outputs summarized in progress/completion notes.
- Metrics or traces: not applicable; this is visual/frontend work without runtime telemetry changes.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-25 | Keep `AI Language Subtitles`, current Blade/WXT stack, paid-beta homepage CTA, compact token-rail overlay, and one cohesive delivery phase. | User-approved implementation plan and repo guardrails favor direct changes over architecture migration. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-25 | Plan created and refined from user-approved Stitch-led implementation plan. | `phased-implementation-v2`; `frontend-skill`; `.\scripts\agent\doctor.ps1` passed. |
| 2026-05-25 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed: docs lint, contracts, Laravel 186 tests, extension 56 tests, TypeScript compile, WXT build. |
| 2026-05-25 | Created dedicated Stitch project and generated canonical references for desktop/mobile marketing, SaaS/account/auth/job states, popup tabs, and overlay states. | Project `projects/2987099361838226750`; design system `assets/871d344e6dab43e185c4573dfa4b95ce`; reference manifest `docs/design-assets/stitch-cinematic/README.md`. |
| 2026-05-25 | Implemented first visual slices: local cinematic SVG artwork, Laravel site/account CSS revamp, popup command-console CSS, overlay glass-rail CSS, and durable design docs. | Updated `app/backend/public/img/cinematic-study-console.svg`, `app/backend/public/css/site.css`, `app/extension/entrypoints/popup/style.css`, `app/extension/utils/overlay.ts`, `docs/DESIGN.md`. |
| 2026-05-25 | Completed browser QA matrix for website, popup, and overlay states; fixed CTA text, mobile auth overflow, fixed popup width, and hidden-state CSS discovered during capture. | Screenshots in `C:\Users\jaden\AppData\Local\Temp\tse-stitch-revamp-shots`: home desktop/mobile, pricing, login, dashboard fixture, popup generate/jobs/jobs-empty/usage/account-auth/account-unauth/settings, overlay active/top/compact/error/unsupported. |
| 2026-05-25 | Completed validation and self-review. | Targeted Laravel SaaS/auth tests passed; extension Vitest, TypeScript compile, and WXT build passed; `.\scripts\agent\doc-gardening.ps1`, `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, and `git diff --check` passed. |

## Completion Notes

- What changed: Introduced the Stitch-led Cinematic Study Console direction across durable design docs, marketing/account Blade surfaces, the WXT popup, and the YouTube overlay. Added a repo-owned cinematic product SVG and updated homepage/social references to avoid production hotlinks.
- Validation results: `php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php tests\Feature\WebAuthTest.php` passed 12 tests / 131 assertions; extension `npm test`, `npm run compile`, and `npm run build` passed; full `.\scripts\agent\check.ps1` passed 186 Laravel tests plus extension validation/build; `.\scripts\agent\verify-pr.ps1` passed; `git diff --check` passed with line-ending warnings only.
- Simplicity/readability review: Kept the existing Blade and WXT/static DOM architecture, preserved backend contracts and extension `data-*` hooks, and confined the revamp to CSS, small Blade text/asset swaps, local assets, overlay Shadow DOM styling, and docs.
- Residual risk: Stitch `download_assets` reported success twice but did not create local files; `docs/design-assets/stitch-cinematic/README.md` preserves the project/screen/design-system references and the implementation uses repo-owned assets. QA screenshots use generated static fixtures for authenticated/dashboard/popup/overlay states because some routes require auth and the extension overlay is runtime-injected.
- Follow-up debt: None added; no behavior/API follow-up is required from this phase.

