# Phase 05: SaaS Website And SEO

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

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

- [ ] Public pages explain the product, supported workflow, limits, privacy posture, and pricing.
- [ ] Users can register, log in, subscribe, manage billing, view usage, and inspect recent jobs from the web app.
- [ ] Dashboard links clearly guide users to install/open the extension.
- [ ] Job pages show public-safe status, timings, language pair, minute usage, and failure code.
- [ ] SEO basics exist: titles, descriptions, canonical URLs, sitemap, robots, structured content hierarchy, and social previews.
- [ ] Legal/support pages cover video/audio processing, AI providers, retention, refunds, and beta limitations.
- [ ] Analytics capture key funnel events without logging sensitive generated content.

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

- Final product name and domain.
- Beta positioning headline and primary offer.
- Pricing copy and tier comparison details.
- Privacy/retention copy for YouTube audio, transcripts, generated tracks, and provider processing.
- Support channel for paid beta users.
- Analytics provider and cookie/privacy posture.

## Validation/Evidence Required

- Feature tests for protected dashboard and billing/account routes.
- Browser smoke for public pages, signup, dashboard, pricing, and job detail.
- SEO crawl check for title, meta description, sitemap, robots, canonical URLs, and broken links.
- Screenshot review for desktop and mobile.
- `.\scripts\agent\check.ps1`
- `.\scripts\agent\verify-pr.ps1`

## Risks and Follow-up Debt

- Marketing copy can overpromise before beta proves real performance.
- Legal/privacy pages need careful review because audio and generated text leave the browser.
- A server-rendered Laravel app keeps beta simple but may need a richer frontend later.
- Analytics must not become a content-leakage path.

