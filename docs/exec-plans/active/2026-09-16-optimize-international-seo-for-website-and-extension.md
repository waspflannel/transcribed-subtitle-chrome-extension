# Plan: Optimize international SEO for website and extension

Status: deferred growth roadmap; launch basics implemented and verified
Owner: agent for engineering; product owner for publishing, account access, and editorial resources
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Authorized Launch Scope

The user chose launch basics, followed by merging `codex/website-extension-localization` into `main` and deleting stale branches. The detailed growth roadmap below is retained for a later discussion and is not a launch gate.

- [x] Implement stable public locale paths, deterministic page language, canonicals, reciprocal alternate links, sitemap and legacy redirects.
- [x] Preserve localized titles, clear product/pricing/install pages, account flows, and mobile usability; verify direct switcher links.
- [x] Add interface locale to existing page-view/signup events and document how existing completion logs identify first successful use. Defer new analytics infrastructure and attribution tracking.
- [x] Run localization/SEO regression tests, the project harness, formatting, and targeted browser checks.

Delivery instruction: commit the authorized localization and SEO changes in reviewable groups, merge and push `main`, and delete branches verified as merged. Git history and remote branch state are the completion evidence for these repository operations.

All six public pages receive all nine locales. English remains at existing paths; translated home URLs use Laravel's normal no-trailing-slash form, such as `/es`. Unknown locale paths return 404. Native editorial review, content production, store-listing publication, keyword research, live Search Console setup, and the 90-day growth cycle stay outside this implementation.

## Goal

Make the website discoverable in each of its nine interface languages and turn relevant organic visits into signups, connected extensions, successful first generations, and paying customers. Improve Chrome Web Store discovery and the consistency of the journey from search result to website to extension.

Deliver the technical foundation for every supported locale, then use measured demand and native editorial review to choose where to invest in deeper content. Success means correct indexing signals, useful localized pages, a fast experience, and measurable acquisition. Rankings, indexing dates, and traffic growth are outcomes to monitor, not promises this plan can guarantee.

## Scope

- In scope: public website URLs and crawlability, metadata, internal links, structured data where justified, performance, market research, localized content and screenshots, Chrome Web Store assets, acquisition measurement, release checks, and a 90-day improvement cycle.
- Languages: English, Spanish, Brazilian Portuguese, French, German, Japanese, Korean, Indonesian, and Simplified Chinese. Interface language and the language a customer studies are separate concepts.
- Preserve: existing English addresses, account and billing behavior, extension settings, shared translation catalogs, and the single Simplified Chinese version.
- Out of scope: translating all 90+ study languages, adding Traditional Chinese, buying country domains, a new CMS or frontend framework, paid advertising, buying links, fabricated reviews, or publishing thousands of language-pair pages.
- The follow-up request authorizes the launch subset above, its merge into `main`, and stale-branch cleanup. Store submission, external account setup, purchases, outreach, and broader growth work remain future steps.

## Acceptance Criteria

- [x] Every existing public page has a deterministic URL in each supported locale: six pages times nine locales, initially 54 canonical URLs.
- [x] Each published translation returns localized HTML, a self-referencing canonical, accurate language attributes, reciprocal alternate links, and a matching sitemap entry. Browser preferences cannot change its content language.
- [x] Existing query-language URLs and legacy aliases redirect to the correct final equivalent without application-level chains or loops.
- [ ] Search Console setup, the live crawl baseline, and release verification are recorded, or clearly marked as awaiting the required account access.
- [ ] Core commercial pages have researched search intent, reviewed localized titles and copy, real product screenshots, and accurate product and pricing claims.
- [ ] Performance is measured before and after changes on representative devices and regions; remaining field-data gaps are explicit.
- [ ] Nine localized Chrome Web Store listing packages are prepared and reviewed; their actual publication status is recorded separately from extension build status.
- [ ] Organic acquisition reporting distinguishes visits, store clicks, signups, extension connections, successful activation, and paid conversion, with attribution gaps visible.
- [ ] Three distinct content briefs are researched and, where demand supports them, piloted in English and two selected additional locales before wider publication.
- [x] Automated route and indexing checks, relevant application tests, manual locale QA, and the project harness pass before engineering delivery is called complete.
- [ ] The 30-, 60-, and 90-day review decisions are recorded before the ongoing growth portion of this plan is closed.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture and frontend: `ARCHITECTURE.md`, `docs/FRONTEND.md`
- Operations: `docs/operations/production-hosting-and-ops.md`
- Security and review: `docs/SECURITY.md`, `docs/REVIEW.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related implementation: `docs/exec-plans/completed/2026-09-16-localize-website-and-extension-in-nine-languages.md`
- Existing localization evidence: `docs/review-evidence/2026-09-16-localization/README.md`
- Working branch at planning time: `codex/website-extension-localization`. Preserve the existing localization changes when implementing this plan.

### Verified repository baseline before launch changes

| Area | Current state | Work required |
| --- | --- | --- |
| Public pages | Home, pricing, how-to-use, privacy, terms, support | Keep one purposeful page per topic; add content only for distinct demand. |
| Rendering | Laravel server-rendered Blade | Reuse this foundation; no rendering rewrite is needed for crawlable content. |
| Localization | Shared catalogs for nine website and extension locales | Add a web URL mapping without renaming saved extension locale IDs. |
| Locale selection | `?lang`, then cookie, then browser language | Make the public URL authoritative; keep account preferences separate. |
| Search metadata | Localized titles, descriptions, canonical and alternate links already exist | Replace query-based canonical addresses and research the wording. |
| Sitemap | Lists the six pages across nine locales | List the new canonical URLs and only translations actually published. |
| Visual content | Guide screenshots use English UI | Produce reviewed screenshots that match the translated extension. |
| Structured data | No marketing JSON-LD found | Add a small amount of truthful, relevant markup. |
| Analytics | Structured funnel logs, with no marketing locale or organic attribution | Extend existing events and create a usable acquisition report. |
| Live evidence | No production crawl, Search Console export, or field-performance audit completed for this plan | Capture these before making claims about current traffic or rankings. |

The localization implementation is useful groundwork. It is not evidence that Google has indexed the translations, that store listings are published, or that translations have had native editorial review.

### Primary implementation locations

- Public routes: `app/backend/routes/web.php`
- Locale handling: `app/backend/app/Http/Middleware/SetWebsiteLocale.php`, `app/backend/app/Support/WebsiteLocale.php`, `app/backend/config/localization.php`
- Page metadata and indexing endpoints: `app/backend/app/Http/Controllers/MarketingPageController.php`, `SitemapController.php`, `RobotsController.php`
- Markup and navigation: `app/backend/resources/views/layouts/site.blade.php`, `components/locale-switcher.blade.php`, and `marketing/`
- Translations and locale inventory: `packages/localization/`
- Acquisition events: `app/backend/app/Services/Analytics/FunnelAnalytics.php`
- Existing locale tests: `app/backend/tests/Feature/WebsiteLocalizationTest.php`
- Extension locale assets: `app/extension/public/_locales/`, `app/extension/utils/i18n.ts`, `app/extension/wxt.config.ts`

### Inputs required during execution

1. Actual production domain, canonical host, live/development status, and any existing search history. Validate production configuration rather than treating local defaults as deployed values.
2. Product-owner access to DNS, Google Search Console, and the Chrome Web Store dashboard. Start with exports if direct tool access is unavailable.
3. Confirmed brand name, support address, store listing URL, current pricing, and rights to screenshots and demonstration material.
4. Native reviewers for commercial copy and a realistic editorial budget. Record review status per locale rather than calling machine-generated copy approved.
5. Commercial availability and existing customers by market, if known. These help choose the two content-pilot locales without guessing their value.

These inputs do not block local routing work or preparation of content briefs. Live account verification, editorial signoff, and publication remain explicit dependencies.

## URL and language policy

Use one production host with language subdirectories. Keep English at its existing unprefixed addresses to avoid an unnecessary English URL migration. Keep current page slugs initially; translating slugs can be reconsidered only when there is a clear reader benefit.

| Language | Shared application locale | Public home path | Public language tag |
| --- | --- | --- | --- |
| English | `en` | `/` | `en` |
| Spanish | `es` | `/es` | `es` |
| Brazilian Portuguese | `pt-BR` | `/pt-br` | `pt-BR` |
| French | `fr` | `/fr` | `fr` |
| German | `de` | `/de` | `de` |
| Japanese | `ja` | `/ja` | `ja` |
| Korean | `ko` | `/ko` | `ko` |
| Indonesian | `id` | `/id` | `id` |
| Simplified Chinese | `zh-CN` | `/zh-hans` | `zh-Hans` |

Examples: `/pricing`, `/es/pricing`, `/pt-br/how-to-use`, `/zh-hans/support`. Only the English root ends with `/`; other canonical paths do not. Matched public routes with a trailing slash redirect to their canonical form. Unsupported path casing returns 404.

The Chinese web tag identifies the Simplified script without creating another Chinese translation or restricting the content to a single country. Chrome locale directory names and persisted app IDs remain unchanged. Do not add country variants such as `es-MX` and `es-ES` until there are meaningful differences to maintain.

Google recommends distinct language URLs and warns that cookie- or browser-dependent content can hide variants from crawlers. The folder convention above is our maintenance decision, not a claim that a folder keyword itself improves ranking. See [Google's multilingual site guidance](https://developers.google.com/search/docs/specialty/international/managing-multi-regional-sites).

### Redirect and rendering contract

| Request | Required behavior |
| --- | --- |
| `/pricing` with a Spanish cookie or browser preference | English content; an optional language suggestion may link to Spanish. |
| `/pricing?lang=es` | Permanent redirect to `/es/pricing`. |
| `/?lang=zh-CN` | Permanent redirect to `/zh-hans`. |
| `/pricing?lang=en` | Permanent redirect to `/pricing`. |
| `/fr/pricing?lang=ja` | Prefix wins; permanent redirect to `/fr/pricing`. |
| `/extension?lang=de` | Permanent redirect directly to `/de/how-to-use#how-to-install`. |
| `/languages?lang=ko` | Permanent redirect directly to `/ko#languages`. |
| A known page with invalid or array-valued `lang` | Safely strip the invalid selector and use the URL's locale; no server error. |
| Unknown locale or unknown page path | Genuine 404; no English success response masquerading as a translation. |
| A canonical page with campaign parameters | Same content and clean canonical without tracking parameters. |

- Inventory and map the other existing aliases (`/desktop`, `/how-it-works`, `/faq`) to their final equivalents. A localized alias follows the same rule.
- Keep redirects on the configured site origin. Retain only validated functional parameters and bounded, approved campaign parameters; never copy arbitrary redirect targets.
- Update internal links and extension website links to the final addresses. A language switch keeps the equivalent page and a valid section anchor when available.
- Public canonical language must not depend on IP location, login status, cookies, or `Accept-Language`. Account screens may continue to use saved preferences.
- Keep signed account links, authentication endpoints, billing routes, APIs, and webhooks outside the public locale migration.
- Do not return different SEO content to search bots. Suggestions must leave the requested page available without a forced redirect.

## Implementation Steps

### Phase 0 — Establish the baseline and measurement contract

Priority: P0. Owners: engineering and product owner. Can run alongside market research.

- [ ] Inventory all live public URLs, response codes, canonicals, alternate links, robots rules, sitemap entries, internal links, existing redirects, and externally linked URLs.
- [ ] If the site is already live, export available Search Console data before changes: pages, queries, countries, devices, clicks, impressions, CTR, and indexing issues. Separate branded queries from discovery queries where the data allows it.
- [ ] Verify the canonical HTTPS host and production values for app URL, brand, support address, and store link. Establish a Search Console Domain property through the product owner's available access.
- [ ] Define the acquisition report and event semantics before changing analytics. Record interface locale independently from subtitle source and target languages.
- [ ] Extend existing first-party measurement only as needed: normalized landing path and locale, bounded acquisition category, allowlisted campaign fields, signup, connected extension, first successful generation, and confirmed paid conversion.
- [ ] Record the attribution window and whether reports use first touch or last touch. Use authenticated linkage where available and report unattributed activity explicitly. Do not treat a store click as an installation, a generation start as success, or checkout start as a sale.
- [ ] Reuse backend billing and job outcomes for conversion evidence. Prevent duplicate conversion counts from retries. Avoid raw referrers, arbitrary query strings, user text, video contents, or personal data in marketing analytics; document retention and any consent requirements before adding persistent tracking.
- [ ] Start with a reproducible export/report over available data. Add an analytics vendor or data pipeline only if a concrete reporting need cannot be met simply.

Exit evidence: URL inventory, baseline exports or a documented absence of data, event definitions, and a sample report that can group acquisition and conversions by landing locale. Country-level search demand can come from aggregate Search Console reporting; it does not require collecting users' precise location.

### Phase 1 — Make every language reliably crawlable

Priority: P0. Owner: engineering. Depends on the URL policy; capture the live baseline before release.

- [ ] Implement the public locale route mapping and redirect contract above using the existing locale helper and Blade pages.
- [ ] Render exactly one absolute self-canonical per indexable page from a trusted configured origin. Do not canonicalize translated pages to English.
- [ ] Generate reciprocal `hreflang` links for each published equivalent, including itself. Use the equivalent English page as `x-default`; `/pricing` is the fallback for pricing, not the homepage.
- [ ] Keep alternate links in HTML as the single implementation. Use valid language tags from the mapping; emit accurate HTML `lang` and `Content-Language` values.
- [ ] Keep the sitemap small and direct: 54 canonical URLs for the existing pages, all returning indexable 200 responses. Exclude redirects, account pages, APIs, tracking variants, and unpublished translations.
- [ ] Add `lastmod` only when a real significant content-update date is available; otherwise omit it. Do not use request time. Do not invest in sitemap `priority` or `changefreq` tuning.
- [ ] Preserve authentication and appropriate exclusion of private pages. Audit public robots rules and staging restrictions. If an already indexed URL needs removal, choose a deliberate removal/noindex approach; a robots block alone is not access control or proof of deindexing.
- [ ] Check all titles, headings, main copy, canonical links, alternate links, and navigation in initial HTML with JavaScript disabled.
- [ ] Keep missing translations out of discovery and alternate sets. Existing complete pages should expose all nine locales; future pilot pages expose only their actual published translations.

Google treats HTML and sitemap alternate annotations as equivalent methods; duplicating them adds maintenance without a search benefit. See [localized-version requirements](https://developers.google.com/search/docs/specialty/international/localized-versions). Google ignores sitemap priority/frequency hints and expects trustworthy modification dates; see [sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).

Exit evidence: an automated crawl matrix for all 54 page variants, the redirect cases above, clean HTML samples, and the generated sitemap. Keep deployed migration redirects for at least a year; record their introduction date. This is a path migration, so a domain Change of Address request is unnecessary. See [Google's URL migration guidance](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes).

### Phase 2 — Research demand and improve the core pages

Priority: P1. Owners: marketing/native editors with engineering support. Research can begin during Phase 0.

- [ ] Create a research brief for every interface locale. Start with roughly 10–20 relevant query candidates per locale, recording country, search engine, research date, intent, observed results, product fit, and proposed destination page. Record volume as unknown when no reliable measurement is available.
- [ ] Research how people actually describe this product in each language. Translate the intent rather than mechanically translating the English keywords. Evaluate subtitle generation, subtitle translation, vocabulary learning from videos, and music-based learning against actual search results.
- [ ] Keep reader language separate from learning language. A Spanish-speaking learner may want Japanese subtitles or Korean vocabulary; the Spanish site is not automatically about learning Spanish.
- [ ] Select two additional content-pilot locales using observed demand, existing users/conversions, competition, product availability, and reviewer availability. Avoid declaring countries attractive solely from population or interface support.
- [ ] Assign one primary intent to each page. Home explains the product; pricing answers cost and limits; how-to-use covers installation and use. Privacy, terms, and support serve their real purposes without commercial keyword stuffing.
- [ ] Rewrite titles, descriptions, H1s, introductions, and calls to action around the assigned intent. Keep them natural, specific, and accurate; review rendered search-preview length rather than imposing one English character limit on every writing system.
- [ ] Have native reviewers check commercial copy, terminology, tone, and button names against the localized extension. Record author/reviewer/date and unresolved issues per locale. AI drafting can help preparation but does not count as native review.
- [ ] Add localized screenshots and useful alt text. Show real supported workflows, explain limitations, and connect relevant pages with descriptive crawlable links. New guides should be reachable within three ordinary links from home.
- [ ] Verify all claims: supported YouTube workflows, desktop browser availability, translation and romanization limits, plan prices, and generation limits. Do not imply support for unrelated streaming services or advertise the paid product as a free tool.
- [ ] Review localized share metadata and images for consistent messaging. Use truthful Organization/WebSite identity markup where appropriate and BreadcrumbList only for an actual visible hierarchy.
- [ ] Consider SoftwareApplication markup only where the visible page supplies the required truthful facts. Do not invent ratings, reviews, or zero-price offers to qualify for Google's software rich results; omit the rich-result implementation when its requirements cannot be met.

The editorial standard is practical information and firsthand product evidence, consistent with [Google's helpful-content guidance](https://developers.google.com/search/docs/fundamentals/creating-helpful-content). Validate eligible markup against [Google's software-app requirements](https://developers.google.com/search/docs/appearance/structured-data/software-app); markup does not guarantee enhanced results.

Exit evidence: nine research briefs, a query-to-page map, reviewed core-page copy, locale screenshots, a glossary, and structured-data validation where used. Search-volume estimates and example phrases must be labeled by source and confidence.

### Phase 3 — Improve speed and usability where measurements justify it

Priority: P1. Owner: engineering. Can run alongside copy review.

- [ ] Record mobile and desktop performance for home, pricing, and the guide. Include a long-text locale such as German and CJK content, and test representative regions for the selected markets.
- [ ] Measure server response time, image sizes, font loading, layout shifts, and interaction delays. Keep reproducible lab settings and use field data when available.
- [ ] Optimize responsive screenshots, compression, width/height reservation, and loading priority. Lazy-load below-the-fold images while allowing the main visible image to load promptly.
- [ ] Inspect external fonts, weights, scripts, CSS, and asset cache headers. Remove demonstrably unused cost and preserve correct CJK fallback and readability. Use a CDN for static assets if regional latency measurements support it.
- [ ] Avoid blanket shared caching of marketing HTML: current pages contain login- and billing-dependent state. Keep personalized responses isolated; consider public HTML caching only after separating that state and proving a need.
- [ ] Check narrow-screen navigation, language switching, translated text expansion, keyboard access, screenshot legibility, and the mobile-to-desktop installation journey.
- [ ] Aim for field Core Web Vitals in the good range at the 75th percentile: LCP at most 2.5 seconds, INP at most 200 ms, CLS at most 0.1. Record insufficient field data honestly and use lab measurements to diagnose, not to claim a field pass.

References: [Core Web Vitals and Search](https://developers.google.com/search/docs/appearance/core-web-vitals) and [measurement thresholds and field-data methodology](https://web.dev/articles/vitals).

Exit evidence: before/after performance reports, representative locale screenshots, and a short explanation of each measured improvement or remaining constraint. Do not treat a perfect Lighthouse score as the business goal.

### Phase 4 — Publish a small set of useful search landing pages

Priority: P1 after research and URL foundations. Owners: content/native editors and engineering.

- [ ] Choose three distinct content briefs based on Phase 2 evidence. Candidate topics are translating YouTube subtitles, building vocabulary from videos, and learning through songs and lyric correction. These are hypotheses, not verified keywords.
- [ ] Give each brief a specific audience, search intent, primary page, unique demonstration, internal-link plan, conversion action, and review owner. Extend an existing page if that satisfies the intent better than adding a competing page.
- [ ] Produce original walkthroughs using the real extension, localized screenshots, useful examples, common problems, and honest limitations. Use material we own or may lawfully demonstrate rather than republishing lyrics or full video transcripts for traffic.
- [ ] Pilot the chosen content in English and two researched additional locales. Three new pages would mean nine initial variants; exact page count follows the intent map, not a publishing quota.
- [ ] Keep pilot locale availability explicit. A language switch must not lead to an English fallback labeled as a translated article, and unpublished variants must not enter the sitemap or alternate links.
- [ ] After quality and acquisition evidence supports expansion, adapt the strongest pages to the remaining locales. Do not create automatic pages for every source/target-language permutation.
- [ ] Keep a named review date and refresh content when the product, screenshots, or search intent changes. Consolidate overlapping pages with deliberate redirects rather than accumulating thin alternatives.

Exit evidence: approved briefs, published-page QA, first crawl/indexing checks, and an expansion decision with reasons. Where traffic is too sparse to evaluate conversion, use product relevance and editorial quality while recording that uncertainty.

### Phase 5 — Improve extension discovery and earn relevant referrals

Priority: P1. Owners: product owner and native editors; engineering supplies build and asset support.

- [ ] Prepare localized Chrome Web Store descriptions and supported visual assets for all nine locales. Match the terminology and promises on the website and in the extension.
- [ ] Capture clear locale-specific screenshots of actual features and provide localized video assets only where they add useful explanation. Follow the store's current image specifications; do not assume every promotional asset supports localization.
- [ ] Verify the publisher's site association, website link, support link, privacy page, and install destination. Confirm the actual listing URL before replacing configuration values.
- [ ] Record draft, editorially reviewed, submitted, and published states separately for each listing. Native `_locales` files in the extension do not prove that listing text and images have been published.
- [ ] Review the real journey in each locale: local search landing page, install guide, store listing, extension startup, account connection, and first successful generation. Include a useful handoff for visitors on devices that cannot install the desktop extension.
- [ ] Prepare a small, relevant outreach shortlist of language teachers, learning communities, and creators. Offer a useful demonstration or guide suitable for their audience. Draft messages and record relevance before any separately authorized outreach.
- [ ] Pursue genuine editorial mentions and user feedback. Avoid paid ranking links, bulk directory submissions, review manipulation, and location pages that imply offices we do not have.

The store supports localized listing content and selected assets; use its actual dashboard and current requirements as the source of truth. See [Chrome Web Store listing guidance](https://developer.chrome.com/docs/webstore/cws-dashboard-listing).

Exit evidence: nine reviewed store packages, a publication-status register, locale journey checks, and a relevant outreach brief. Website organic discovery, store discovery, and product activation remain separate measured stages.

### Phase 6 — Release, verify, and improve using real results

Priority: P0 for release checks; ongoing after launch. Owners: engineering and product owner.

- [ ] Before release, validate production host configuration, redirect map, crawlability, access controls, sitemap output, structured data, and analytics semantics on the deployable revision. Keep staging out of search.
- [ ] Run the required project checks and affected browser builds. Prepare a rollback procedure and a post-deploy smoke test; avoid simultaneous unrelated infrastructure or domain changes.
- [ ] After the authorized deployment, inspect actual production responses. Confirm there is no accidental global `noindex`, robots block, incorrect host, untranslated fallback, or broken store link.
- [ ] Submit the canonical sitemap in Search Console and inspect representative home, pricing, and guide URLs across locales. Compare rendered language, declared canonical, and Google's selected canonical when that data becomes available.
- [ ] Monitor redirects, 404/500 rates, unwanted indexed URLs, canonical conflicts, and lost organic landing pages during migration. Investigate before changing a second variable.
- [ ] Use Bing Webmaster Tools where it adds coverage. Evaluate other search engines only when market research and actual product availability justify the work; Simplified Chinese support alone is not evidence of an accessible mainland-China acquisition market.
- [ ] Review technical health weekly during the first month, then review demand and conversion monthly. These are operating tasks to schedule during execution; this plan creates no automation.
- [ ] Record the 30-, 60-, and 90-day decisions below and turn continuing work into an owned backlog.

Search Console supports demand analysis by queries, pages, countries, and devices. Keep this separate from first-party conversion reporting, with the limits of each source visible. See [Search Console performance reporting](https://support.google.com/webmasters/answer/7576553).

## Delivery sequence and ownership

| Window | Main deliverable | Dependency |
| --- | --- | --- |
| First work cycle | Baseline, event definitions, URL migration, crawl tests | Repository access; production exports before live migration if already indexed |
| Following work cycle | Researched core copy, localized screenshots, performance fixes | Native reviewer availability and representative measurements |
| Content pilot | Three validated briefs in English and two additional locales; store packages | Keyword evidence, product fit, editorial approval |
| Release | Production verification and sitemap submission | Authorized deployment and owner account access |
| Days 30–90 after release | Evidence-based fixes, content expansion, and market priorities | Enough comparable observations to support each decision |

This is an order of work, not a promised launch date. Engineering, editorial work, store review, and search-engine processing have different lead times. All nine locales receive the technical foundation; the pilot limits deeper editorial spending until evidence improves.

## Measurement and review decisions

| Measure | Initial success criterion | Interpretation |
| --- | --- | --- |
| Crawl integrity | All intended canonical URLs return the correct language and pass route/alternate/sitemap checks | A controllable engineering outcome |
| Publication quality | Reviewed core copy and accurate assets for all nine locales | Record technical readiness and native review separately |
| Search visibility | Track indexed intended URLs, non-brand impressions, clicks, queries, and landing pages by locale/country/device | No fixed growth percentage before a baseline exists |
| Visit quality | Track signups and extension connections from attributable organic visits | Show unattributed and cross-device gaps rather than inventing a complete funnel |
| Activation | First successful generation after acquisition | Generation-start events alone are insufficient |
| Commercial outcome | Confirmed paid conversion and attributable revenue/cohorts where available | Checkout initiation alone is insufficient |
| User experience | Good field Core Web Vitals when data is sufficient, plus no locale usability blockers | Lab scores help diagnosis; sparse field data is not a pass |

- Day 30: resolve crawl, wrong-language, redirect, and canonical issues; verify conversion reporting. Investigate visibility gaps before increasing page volume.
- Day 60: assess query intent, landing-page usefulness, and signup/activation quality. For high-impression pages with weak CTR, inspect query mix, position, and titles together. For clicks without activation, inspect the product and onboarding journey.
- Day 90: choose which pilot topics and markets deserve more content. Expand winners, improve or consolidate weak pages, and record continued maintenance ownership.

Use comparable 28-day periods where feasible, annotating releases and seasonal differences. Avoid judging a low-volume locale from a handful of impressions or assigning causation to one SEO change without supporting evidence.

## Risks and deliberate limits

- Incorrect redirects or cross-language canonicals can damage existing discovery. Preserve the URL inventory, test mappings, and avoid unnecessary English URL changes.
- Translation quality can undermine trust even with correct tags. Review core commercial copy and screenshots before treating a locale as editorially complete.
- More indexed pages are not automatically more useful pages. Keep each new page tied to a distinct reader need and a maintained product workflow.
- Authentication-dependent marketing content makes shared response caching risky. Prefer measured asset optimizations first.
- Tracking across website, store, devices, and extension will be incomplete. Do not present store clicks or inferred installs as verified conversions.
- Native review, store access, and a live domain are delivery dependencies. Record exactly which output is ready and which external step remains.
- Defer meta-keywords, speculative geo tags, special AI text files, and generic SEO-plugin infrastructure. Google states that AI search features use existing SEO foundations and require no special AI schema or text files: [AI features and site guidance](https://developers.google.com/search/docs/appearance/ai-features).

## Validation Plan

### Engineering checks during implementation

Extend the existing locale feature tests rather than building a second test framework. Cover:

1. All six public pages across nine locales: response status, localized main text, HTML language, one canonical, reciprocal alternate targets, and sitemap membership.
2. Conflicting cookies and browser headers against explicit paths and English root URLs; locale state must not leak between requests.
3. Query migration, aliases, invalid selectors, unknown paths, tracking parameters, and slash/case normalization; no loops, open redirects, or soft 404s.
4. Link generation and the language switcher preserving the page; only actual published translations enter the alternate set.
5. Nonpublic routes, authentication, signed URLs, billing, and private cache behavior remain correct.
6. New attribution fields are validated and bounded, and successful activation/paid events count the intended event once.

Run focused tests while developing, then the project harness before delivery:

```powershell
.\scripts\agent\check.ps1
```

Run the existing formatting checks for changed PHP. If extension source or locale assets change, run its tests, type checking, and applicable Chrome/Firefox builds through the existing project workflow. Do not repeat unrelated expensive checks without a change or new concern.

### Manual and live checks

- Inspect raw and rendered HTML, real links, keyboard access, and desktop/mobile layouts in every locale.
- Validate truthful structured data with the appropriate validators; distinguish schema validity from Google rich-result eligibility.
- Record performance test configuration and before/after results for the representative page/locale/device set.
- Verify live status codes, robots, canonical host, sitemap, redirects, metadata, and store destinations after deployment.
- Capture Search Console inspection examples and reporting exports when available. Do not call local test results proof of live indexing.

During implementation, store evidence under `docs/review-evidence/2026-09-16-international-seo/` with a README tying results to the revision, environment, and date. Maintain URL inventory, redirect map, keyword briefs, editorial review records, store publication status, and the acquisition scorecard there. Never store credentials or private user data.

### Planning-only validation

For this document change, run `.\scripts\agent\check.ps1 -SkipAppChecks` and a whitespace/diff check on this plan and `docs/PLANS.md`. Application checks from the prior localization work are not new SEO implementation evidence.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Plan only; preserve existing localization work. | The current request is for an execution plan. |
| 2026-09-16 | English stays unprefixed; translated public pages use folders on one host. | Preserve existing English addresses and make locale selection deterministic. |
| 2026-09-16 | Use web `zh-Hans` for the existing Simplified Chinese catalog. | Describe script coverage while keeping one Chinese version and stable app locale IDs. |
| 2026-09-16 | Keep one HTML alternate-link implementation and one sitemap. | Sufficient for the current page count and easier to maintain. |
| 2026-09-16 | All locales get technical foundations; deeper content starts in English plus two researched locales. | Limit editorial cost without withholding basic international discoverability. |
| 2026-09-16 | Extend first-party funnel evidence before adopting new analytics infrastructure. | Measure qualified acquisition with fewer dependencies and explicit attribution limits. |
| 2026-09-16 | Implement only launch basics, then merge and clean merged branches. | User explicitly deferred the full pre-launch growth campaign. |
| 2026-09-16 | Reuse Laravel route groups, shared locale helper, existing completion logs, and existing tests. | Ponytail and Laravel Best Practices guidance; Laravel 13 routing docs retrieved through Context7 because Boost search tools are unavailable in this session. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Audited current routes, locale handling, marketing metadata, sitemap, configuration, analytics, and localization evidence. | Repository baseline and implementation locations above. |
| 2026-09-16 | Reviewed current primary-source guidance for international URLs, alternate links, migration, sitemaps, content, performance, structured data, store localization, and measurement. | Supporting links appear beside the related decisions. |
| 2026-09-16 | Created prioritized execution plan with URL rules, acceptance criteria, ownership, external dependencies, and a 90-day review cycle. | This document; implementation checkboxes remain open. |
| 2026-09-16 | Planning documentation passed the project harness with application checks intentionally skipped. | `.\scripts\agent\check.ps1 -SkipAppChecks`: documentation harness lint passed. |
| 2026-09-16 | Implemented and verified the authorized launch subset. | `docs/review-evidence/2026-09-16-international-seo/README.md`: 691 backend tests, 355 extension tests, contracts, TypeScript, Chrome build, formatting, and targeted browser evidence. |

## Completion Notes

- What changed: implemented the authorized launch subset: stable locale paths, deterministic public language, direct redirects and switcher links, canonical/alternate/sitemap consistency, and basic locale measurement using existing logs. Preserved the rest of this plan as a deferred growth roadmap.
- Validation results: full project harness passed with 691 backend tests / 38,562 assertions and 355 extension tests; nine environment-dependent integration tests skipped. Focused SEO tests, Pint, and local browser checks passed. See `docs/review-evidence/2026-09-16-international-seo/README.md`.
- Simplicity/readability review: reuse Laravel routes, Blade, shared locale catalogs, existing tests, and first-party events. No new framework, CMS, analytics vendor, or SEO abstraction is assumed.
- Residual risk: the production domain, search baseline, field performance, native review, and store publication require evidence during execution.
- Follow-up work: production configuration and account setup at launch; the broader unchecked roadmap remains deferred until the user resumes it. No recurring review automation has been created.
