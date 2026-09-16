# Plan: Annotated screenshots for How To Use

Status: completed
Owner: agent
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Capture the current extension with agent-browser and add useful annotations to the How To Use page. Keep screenshots readable alongside the instructions on desktop and mobile.

## Scope

- Seven captures: manual installation, generation, transcript, full lyrics replacement, single-word correction, caption display/timing, and active recall.
- Same branch as the approved website cleanup: `codex/how-to-use-website-cleanup`.
- No changes to extension behavior, account state, billing, or provider calls. Videos remain future content.

## Acceptance Criteria

- [x] Use agent-browser to capture actual interface markup and styles with clearly identified example content.
- [x] Place numbered highlights on useful controls with matching accessible text captions.
- [x] Keep screenshots responsive, lazily loaded, and available at full size.
- [x] Verify desktop/mobile, asset links, and project checks.
- [x] Record capture provenance and review evidence.

## Relevant Context

- `docs/FRONTEND.md`, `docs/DESIGN.md`, `docs/REVIEW.md`, `docs/quality/golden-principles.md`.
- `app/backend/AGENTS.md`, `docs/references/boost-skill-routing.md`.
- Prior plan: `docs/exec-plans/completed/2026-09-16-website-cleanup-and-how-to-use-guide.md`.
- Skills: agent-browser (explicit request), ponytail, frontend-skill, laravel-best-practices.

## Decisions

- No attachable Chrome debugging session was available. Use the existing local fixture that builds the actual side-panel entrypoint, substituting sample extension transport and original example lyrics. Chrome installation uses the browser's real Extensions page.
- Use the agent-browser CLI for all captures and browser QA. Context7 supplied current CLI documentation. Boost SearchDocs supplied installed Laravel anonymous-component guidance.
- Keep original PNG captures intact. Draw precise SVG boxes and numbered circles in one shared Blade figure component. Text captions describe every highlight independently of color or the image.
- Use native image links for enlargement, with explicit new-tab labels. No lightbox, image editing service, new JavaScript, or dependency.
- Reserve image dimensions and load lazily. Seven small PNGs total about 455 KiB; screenshots use double-density capture for legibility.

## Implementation

- [x] Inspect current guide and available extension capture harness.
- [x] Capture and visually inspect actual UI states with sample data.
- [x] Add reusable annotated figure and scoped guide styles.
- [x] Integrate seven figures beside matching written instructions.
- [x] Complete browser and harness verification, review, and evidence.

## Validation Plan

- `php artisan test --compact --filter=SaasWebsiteAndSeoTest`.
- `vendor/bin/pint --dirty --format agent`.
- `.\scripts\agent\check.ps1`.
- Agent-browser desktop/mobile screenshots, all image loads and declared dimensions, narrow-width overflow, full-size links, and console errors.
- `git diff --check` and self-review of the new component, captions, assets, and styles.

## Progress

- Seven annotated figures render correctly. Desktop and 390px mobile screenshots inspected; no horizontal overflow. Focused website tests: 20 passed, 293 assertions. Pint passed.
- Captures and QA images are recorded under `docs/review-evidence/2026-09-16-guide-screenshots`.

## Completion Notes

Completed seven annotated figures with responsive captions and native full-size links. Self-review confirmed readable highlights, equivalent text instructions, reserved image dimensions, and no new JavaScript or dependencies. All seven images load with matching declared dimensions and alt text. Full-size navigation opens the expected image. No browser errors or horizontal overflow at 320, 390, 768, 1024, or 1440px. Source and integrated desktop/mobile images were visually inspected.

Required harness passed: 684 backend tests, 9 skipped, 5443 assertions; 342 extension tests across 32 files; contract checks, TypeScript compile, and production build. Focused website tests, Pint, and diff checks also passed. Evidence: `docs/review-evidence/2026-09-16-guide-screenshots/README.md`. Local full check log: `app/backend/storage/logs/guide-screenshots-check.log` (ignored). Captures show the actual UI with sample data; refresh image dimensions and annotation coordinates together when the extension interface changes. Video walkthroughs remain future content.
