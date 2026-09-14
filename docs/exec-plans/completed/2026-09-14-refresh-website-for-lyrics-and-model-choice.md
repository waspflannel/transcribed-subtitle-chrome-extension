# Plan: Refresh website for lyrics and model choice

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-14
Last updated: 2026-09-14

## Goal

Update the marketing website for lyrics correction, model choice, progressive subtitle generation, and current study tools while preserving the Ink & Marker theme. Work on `codex/marketing-feature-refresh`.

## Scope

- Homepage copy, interactive example, lyrics/model/study sections, compact language directory, pricing and beta install guidance, metadata, and shared navigation/footer copy.
- Preserve billing, registration plan carry, routes, real plan prices, and extension behavior. No provider calls, new dependencies, invented benchmarks, or deployment.

## Acceptance Criteria

- [x] Keep the existing colors, fonts, notebook rules, marker highlights, and tactile buttons.
- [x] Bring the example into the initial desktop viewport and provide working generation, lyrics, and word-card previews.
- [x] Describe full lyrics replacement, Luna/Cerebras choice, progressive availability, and current study tools accurately.
- [x] Keep language coverage accessible in a native disclosure and preserve old anchor links.
- [x] Explain beta installation before purchase and remove infrastructure jargon from plan cards.
- [x] Verify desktop/mobile, keyboard, reduced motion, no-JavaScript content, registration links, and both install configurations.
- [x] Run the repository checks and record evidence.

## Relevant Context

- Product: `docs/product-specs/index.md`, `docs/product-specs/lyrics-editing.md`.
- Design: `docs/DESIGN.md`, `docs/FRONTEND.md`.
- Quality: `docs/quality/golden-principles.md`, `docs/REVIEW.md`.
- Speed evidence: `docs/exec-plans/evidence/2026-09-13-first-subtitle-experiment.md` measures backend availability, not a general browser timing guarantee.

## Design Decisions

- Visual thesis: preserve the red marker on black and cream paper; make the working product the visual focus.
- Content plan: hero/example, lyrics correction, speed/model choice, study tools, setup, languages, pricing, FAQ, final CTA.
- Interaction thesis: retain the hero marker entrance and scroll reveals; add accessible preview selection and deliberate before/after actions. No artificial speed timer or automatic playback.
- Apply frontend, ponytail, browser, and Laravel best-practices skills. Laravel skill required a read-only rule-reader subagent; it reviewed Blade/security/testing/style and existing website tests. Use existing partials, escaped values, and checkout forms.
- Boost MCP search tools are unavailable in this session; current Laravel 13 Blade/testing documentation was fetched through Context7.
- The preview uses clearly labeled original example text and browser-only interactions. Full product generation requires the extension and an active plan.

## Validation Plan

Run the existing website feature test, Pint, and `scripts/agent/check.ps1`. Inspect the rendered page with agent-browser at desktop, mobile, and 320px widths. Check preview actions and keyboard navigation, native language disclosure, no horizontal overflow, no-JavaScript access, reduced motion, pricing and install links. Save visual evidence outside production assets.

## Progress

- Created branch from a clean `main`; reviewed current templates, theme, product specifications, and existing purchase flow.

- Rebuilt the homepage around an interactive example, full lyrics correction, model choice, progressive subtitle availability, and existing study tools.
- Retained the current A–Z language catalog in a native disclosure; the initial draft used an older grouped view variable, which the website tests caught and the final implementation removes.
- Preserved named routes, configured plan prices, authenticated checkout forms, and plan-carry registration links. Added coverage for store-link-present and store-link-absent marketing paths.
- Kept all styling in existing assets and example markup in one partial. No dependencies, provider calls, backend workflow changes, or fabricated performance claims.
- Self-review removed obsolete player styles and duplicate language-cloud styling. Preserved focus on the lyrics-preview tab after the native anchor navigation using one animation frame.
- Browser CLI offscreen mouse clicks required scrolling/focus first. The language disclosure was verified through real keyboard Enter toggles. No-JavaScript verification blocked the exact observed script URL and reloaded; all three examples, word content, plan cards, and install links remained rendered.

## Validation Results

- `php artisan test --compact tests/Feature/SaasWebsiteAndSeoTest.php`: 19 passed, 225 assertions.
- `php vendor/bin/pint --dirty --format agent`: passed.
- `scripts/agent/check.ps1`: passed; 568 backend tests / 4537 assertions, 277 extension tests / 31 files, contracts, TypeScript compilation, extension build, and documentation checks. Local log: `app/backend/storage/logs/marketing-refresh-check.log` (ignored).
- Final JavaScript focus adjustment: rechecked in browser; Home, End, ArrowLeft and wraparound ArrowRight select and focus the correct tabs. `node --check app/backend/public/js/site-interactions.js` passed.
- Browser: generation reset/show; lyrics before/after; word-card show/hide; translation hide/reveal; lyrics-section link selects and focuses the correct preview; language directory opens/closes with Enter.
- Rendered 1440px desktop, 1024px tablet, 390px mobile, and 320px minimum width. No horizontal overflow; mobile puts the subtitle example ahead of its controls.
- Reduced motion and script-blocked progressive enhancement verified. No broken local anchors, duplicate element IDs, or JavaScript errors found.
- Billing/registration flow and metadata remain covered by the existing website tests. No real checkout or paid generation was submitted.
- Browser screenshots: `docs/design-assets/marketing-feature-refresh/qa/home-desktop.png`, `home-mobile.png`, and `lyrics-models-desktop.png`.
- Resolved the local preview URL through the installed Boost GetAbsoluteUrl tool class: `http://127.0.0.1:8001`.

## Commit Plan

1. Website assets, Blade views/partial, metadata, and website regression tests: `Refresh marketing for lyrics and model choice`.
2. Design/architecture notes, execution record, and visual evidence: `Document marketing refresh and browser checks`.

## Completion Notes

Implemented and locally verified. The original Ink & Marker design remains the visual system. The demonstration uses clearly labeled original example content and does not run real generation. Public speed messaging describes progressive availability without a numeric guarantee. Existing prices and purchase behavior are preserved. The branch is local; no deployment or remote push was requested.
