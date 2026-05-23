# Phase 05: SaaS Website And SEO

Status: completed
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-22

## Goal

Build the Laravel web app surface users need to discover, buy, manage, and trust the product.

The website should serve both marketing and account workflows without adding a separate frontend application for the beta.

## Scope

- In scope:
  - SEO-ready marketing pages.
  - Authenticated account dashboard.
  - Pricing, checkout entry points, billing portal entry, usage, and job history pages.
  - Privacy, terms, FAQ, language coverage, and support pages.
  - Analytics events for signup, checkout, install, first generation, and retention.
- Out of scope:
  - Separate Next.js or SPA frontend.
  - Blog/content engine beyond simple pages needed for beta.
  - Team/admin customer portals.
  - Complex CMS or A/B testing infrastructure.

## Acceptance Criteria

- [x] Public pages explain the product, supported workflow, limits, privacy posture, and pricing.
- [x] Users can register, log in, subscribe, manage billing, view usage, and inspect recent jobs from the web app.
- [x] Dashboard links clearly guide users to install/open the extension.
- [x] Job pages show public-safe status, timings, language pair, minute usage, and failure code.
- [x] SEO basics exist: titles, descriptions, canonical URLs, sitemap, robots, structured content hierarchy, and social previews.
- [x] Legal/support pages cover video/audio processing, AI providers, retention, refunds, and beta limitations.
- [x] Analytics capture key funnel events without logging sensitive generated content.

## Key Implementation Areas

- Marketing site:
  - Home, pricing, language coverage, FAQ, how it works, privacy, terms, and support.
  - Clear positioning for polyglot power users.
  - Chrome extension install path and beta access flow.
- Account dashboard:
  - Plan, usage, billing portal, extension connection state, recent jobs, and support links.
  - Public-safe job detail pages useful for support and self-debugging.
- SEO and content:
  - Use server-rendered Laravel pages for beta simplicity.
  - Add metadata, sitemap, robots, and stable URLs.
  - Avoid promising unsupported platforms, live captions, teams, or vocabulary review.
- Analytics:
  - Track anonymous marketing page views and authenticated funnel events.
  - Keep generated text, prompts, transcripts, and token payloads out of analytics.
- Design system:
  - Select a restrained SaaS visual direction and document it in `docs/DESIGN.md`.
  - Keep operational pages dense, clear, and scannable.

## Required Product Decisions

- Final product name and domain: use the existing extension-facing product name, **AI Language Subtitles**, for beta pages. Canonical URLs use `APP_URL`; the production domain remains an environment/deployment decision for Phase 06.
- Beta positioning headline and primary offer: "AI Language Subtitles for YouTube language learners" with generated-video-minute plans for polyglot power users.
- Pricing copy and tier comparison details: use the current billing catalog (`Base`, `Plus`, `Pro`) as the source of truth for prices, minute caps, queue speed, concurrency, and Full word cards availability.
- Privacy/retention copy: public/legal pages must state that public YouTube audio/text are sent to backend AI providers only after explicit generation, raw audio is temporary, generated tracks are retained for 30 days, and analytics excludes generated content.
- Support channel: paid beta users are directed to the support page and configured support email; job detail pages expose public-safe support IDs.
- Analytics provider and cookie/privacy posture: beta uses first-party Laravel structured logs only. No third-party analytics script, no marketing cookies, and no transcripts, prompts, generated subtitles, YouTube URLs, provider payloads, tokens, or install IDs in analytics context.

## Refined Implementation Slices

1. Public marketing and SEO:
   - Build server-rendered home, pricing, languages, how-it-works, FAQ, privacy, terms, and support pages with shared metadata, canonical URLs, social tags, `robots.txt`, and `sitemap.xml`.
   - Defer blog/CMS, A/B testing, and hardcoded production domain.
   - Validation: feature tests for public routes, metadata, sitemap, robots, and link integrity; browser desktop/mobile screenshots.
2. Account dashboard and job detail:
   - Replace the placeholder account page with plan, usage, billing, extension connection state, recent jobs, support links, and safe job detail pages scoped to the authenticated owner.
   - Defer team/admin portals and transcript/text exposure in web support pages.
   - Validation: feature tests for auth redirects, dashboard content, owner-only job detail, and no generated subtitle text leakage.
3. Funnel analytics:
   - Add a small first-party analytics service that logs anonymous page views plus signup, checkout, extension connection, first generation, and repeat-generation retention events with sanitized scalar context.
   - Defer external analytics provider selection and cookie consent until a real provider is chosen.
   - Validation: tests assert expected event names and absence of sensitive video/transcript fields.
4. Harness memory:
   - Update design, product, security/observability docs where this phase changes durable product behavior.
   - Validation: `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1`, and doc gardening when docs move.

## Design Direction

- Visual thesis: restrained SaaS editorial surface with a full-bleed product-workflow hero, ink-and-paper readability, and a single teal action accent balanced by warm failure/support states.
- Content plan: hero explains the beta offer; workflow section shows the YouTube-to-overlay path; pricing and language coverage prove fit; legal/support pages make limits and privacy inspectable.
- Interaction thesis: lightweight CSS entrance on hero copy, sticky/calm header with clear auth state, and compact hover/focus transitions on links, plan rows, and job rows.

## Plan Review Notes

- Current code already has Fortify/Sanctum auth, Stripe-hosted billing entry points, local usage accounting, and an authenticated placeholder dashboard. This phase should compose those surfaces instead of adding a separate frontend app.
- `docs/product-specs/index.md` still carries older "user accounts" non-goal language; this phase must update that durable product memory because SaaS account/billing workflows now exist.
- The app entered this phase without an external analytics decision or cookie posture. First-party logs satisfy beta funnel evidence without introducing third-party scripts or content leakage risk.
- Browser validation should focus on server-rendered pages because the extension screenshot harness remains tracked separately as `TD-003`.

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-22 | Activated Phase 05, loaded `phased-implementation-v2`, `frontend-skill`, Laravel best-practices, and Laravel security guidance; reviewed current routes, auth, billing, dashboard, language catalog, and tests. | `php artisan boost:list-skills`; Context7 `/laravel/docs`; local skill files. |
| 2026-05-22 | Baseline harness check passed before implementation. | `.\scripts\agent\check.ps1` passed: docs lint, contracts, Laravel 178 tests, extension 56 tests, TypeScript compile, WXT build. |
| 2026-05-22 | Implemented server-rendered marketing pages, SEO endpoints, dashboard, owner-scoped job details, first-party analytics, CSS direction, config, and docs updates. | New `SaasWebsiteAndSeoTest` covers public SEO pages, robots/sitemap, protected dashboard, job details, and sanitized analytics. |
| 2026-05-22 | Browser smoke completed for public pages, pricing, registration page, and protected dashboard redirect. CDP screenshots reviewed at 1440x1000 and 390x844; mobile `scrollWidth` stayed at 390px and the next section is visible below the hero. | Local server `http://127.0.0.1:8025`; screenshots in `%TEMP%\tse-phase05-shots`; browser console errors: none. |
| 2026-05-22 | Final validation passed. | `vendor/bin/pint --dirty --format agent`; targeted PHP tests; `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`. |

## Validation/Evidence Required

- Feature tests for protected dashboard and billing/account routes.
- Browser smoke for public pages, signup, dashboard, pricing, and job detail.
- SEO crawl check for title, meta description, sitemap, robots, canonical URLs, and broken links.
- Screenshot review for desktop and mobile.
- `.\scripts\agent\check.ps1`
- `.\scripts\agent\verify-pr.ps1`

## Completion Notes

- What changed: added the beta Laravel SaaS website, shared metadata layout, public pricing/language/how-it-works/FAQ/privacy/terms/support pages, `robots.txt`, `sitemap.xml`, account dashboard, extension connection state, recent jobs, owner-scoped job detail pages, first-party funnel analytics, marketing config, and durable docs updates.
- Validation results: `vendor/bin/pint --dirty --format agent` passed; `php artisan test --compact tests/Feature/SaasWebsiteAndSeoTest.php`, `tests/Feature/BillingAndUsageTest.php`, `tests/Feature/WebAuthTest.php`, `tests/Feature/ExtensionAuthApiTest.php`, `tests/Feature/SubtitleJobApiTest.php`, and `tests/Unit/LanguageCatalogTest.php` passed; `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1` passed.
- Browser evidence: in-app browser verified pricing route metadata/copy and zero default-viewport overflow, registration page, dashboard auth redirect, and no console errors; local Chrome/CDP screenshots verified desktop and mobile public-page layout.
- Residual risk: legal/privacy/refund copy is product-grade beta copy and still needs counsel/operator review before public launch; production domain and external analytics provider selection remain in later roadmap phases.
- Follow-up debt: no new technical debt added; existing screenshot-harness debt remains scoped to extension/YouTube automation, not these server-rendered pages.

## Risks and Follow-up Debt

- Marketing copy can overpromise before beta proves real performance.
- Legal/privacy pages need careful review because audio and generated text leave the browser.
- A server-rendered Laravel app keeps beta simple but may need a richer frontend later.
- Analytics must not become a content-leakage path.

