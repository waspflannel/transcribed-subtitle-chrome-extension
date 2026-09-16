# Plan: Website cleanup and How To Use guide

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Replace the public interactive preview with a navigable How To Use guide. Remove public beta messaging, preserve pricing and accounts, and repair the dashboard Support ID destination layout.

## Scope

- In scope: homepage, public navigation, installation links, documentation page, support copy, beta wording, Support ID detail layout, focused regression coverage.
- Out of scope: account/billing behavior, beta distribution management, extension features, new screenshots/videos, deployment.
- Branch: `codex/how-to-use-website-cleanup`.

## Acceptance Criteria

- [x] Preview section, button, template, and unused CSS/JS removed; guide section and links replace them.
- [x] Hero subheader says Generate subtitles; public paid-beta messaging and FAQ are removed.
- [x] Public guide covers both installation methods and the current generation, correction, study, history, and account controls.
- [x] Guide uses accessible section navigation and supports future figures/videos without blank media placeholders.
- [x] Pricing, authentication, subscriptions, and dashboard account controls remain.
- [x] Support focuses on contact/bugs and links installation to the guide.
- [x] Dashboard Support ID route is covered end to end and detail layout is repaired.
- [x] Desktop/mobile and full harness validation complete.

## Relevant Context

- Product/UI: `docs/FRONTEND.md`, `docs/DESIGN.md` and actual extension control markup/renderers.
- Quality: `docs/quality/golden-principles.md`, `docs/REVIEW.md`.
- Laravel: `app/backend/AGENTS.md`, `docs/references/boost-skill-routing.md`.
- Skills: ponytail, frontend-skill, laravel-best-practices, browser.

## Decisions

- User confirmed full written instructions, both store/manual installation, and preserving existing pricing/account controls.
- Reuse Blade, the marketing controller metadata/analytics path, existing tokens, native links/details. No docs framework or new dependency.
- Laravel skill routing used named routes and escaped views; the skill-required helper read the relevant rules and investigated the independent Support ID bug.
- Boost SearchDocs verified installed Laravel view/routing conventions. Context7 Chrome documentation verified Load unpacked instructions.
- The Chrome listing is unconfigured locally. Render honest unavailable-link text, keep both instruction sets, and do not invent a store or package URL.
- Support ID routing succeeds locally. The proven defect is `.narrow-workspace` combining a capped width with viewport-sized padding. Center it with fixed gutters, restore metric grid display, and separate panels. Keep owner/privacy boundaries.

## Validation Plan

- Focused: `php artisan test --compact tests/Feature/SaasWebsiteAndSeoTest.php tests/Feature/WebSubtitleJobSupportTest.php tests/Feature/WebSubtitleJobDeletionTest.php`.
- Format: `php vendor/bin/pint --dirty --format agent`.
- Required harness: `.\scripts\agent\check.ps1`.
- Browser: desktop/mobile home, guide section links, shortcuts, support and synthetic job detail; inspect console and horizontal overflow.

## Progress

- Implemented public guide and cleanup. Focused tests: 37 passed, 388 assertions.
- Support detail fixture rendered from disposable in-memory tests to backend storage, without changing runtime account data.
- Desktop/mobile guide and home links, keyboard contents controls, support and wide/mobile job details verified. Fixed the inherited 680px table minimum for the guide shortcuts. No horizontal overflow at the checked widths.

## Completion Notes

Completed: full harness passed (684 backend tests, 9 skipped; 342 extension tests; contract validation, compile and build), Pint passed, JS syntax and diff checks passed. Visual evidence and detailed checks are in `docs/review-evidence/2026-09-16-website-guide/README.md`. Self-review removed obsolete preview code and preserved account/billing behavior. The Support ID route worked locally; the confirmed layout defect is repaired. The follow-up screenshot work is documented in `docs/review-evidence/2026-09-16-guide-screenshots/README.md`; videos and distribution URLs remain future content work.
