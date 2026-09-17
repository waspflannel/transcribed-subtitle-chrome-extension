# Launch SEO verification

Scope: launch basics on `codex/website-extension-localization`, alongside the previously implemented nine-language website and extension localization.

## Implementation

- Six public pages across nine interface locales use deterministic language URLs. English stays unprefixed; translated home URLs use `/es`, `/pt-br`, `/fr`, `/de`, `/ja`, `/ko`, `/id`, and `/zh-hans`.
- The URL determines public content language even when browser preferences or the saved cookie disagree. Auth/account pages retain their original query/cookie behavior.
- Canonicals, reciprocal language alternates, page-preserving switcher links, the 54-entry sitemap, and robots sitemap address use the configured website origin.
- Legacy language queries, retired page aliases, and trailing-slash variants redirect to their final canonical equivalents. Normalization redirects drop query parameters; marketing attribution remains deferred.
- Existing page-view and signup logs now include `interface_locale`. The existing generation-start/completion logs already support counting first successful use by joining public job IDs; the procedure is in `docs/OBSERVABILITY.md`.

## Automated checks

- `php artisan test --compact tests/Feature/WebsiteLocalizationTest.php tests/Feature/SaasWebsiteAndSeoTest.php`: 27 passed, 33,412 assertions.
- `php vendor/bin/pint --dirty --format agent`: passed.
- `scripts/agent/check.ps1`: passed documentation lint, contracts, 691 backend tests / 38,562 assertions, 355 extension tests, TypeScript, and the Chrome production build.
- `npm run build:firefox`: passed with the existing extension-ID and data-collection-permission distribution warnings. This is build evidence, not Firefox store approval.
- Nine environment-dependent PostgreSQL/Redis integration tests were skipped by the standard profile. This work changes no database schema, billing processing, or generation worker behavior.
- Targeted Laravel review found no merge blockers in routing, locale middleware, canonical/alternate generation, sitemap, switcher, or analytics changes.

The route tests inspect every public locale/page pair, canonical and alternate targets, switcher URLs, sitemap membership, contradictory language preferences, direct legacy redirects, malformed selectors, unknown prefixes, configured-origin behavior, auth flows, and exclusion of query values from analytics.

## Browser checks

Agent-browser exercised the existing local Laravel server at `127.0.0.1:8001`.

- The switcher navigated from Spanish pricing to `/ja/pricing`, retaining pricing as the selected page. [Japanese pricing](japanese-pricing.png) shows translated navigation, pricing introduction, and installation instructions.
- [German mobile switcher](german-mobile-switcher.png) shows all nine language choices at 390 px. The dropdown and translated pricing introduction fit the viewport. The DOM probe confirmed no horizontal overflow, a German canonical, and all nine direct pricing-page language links.
- An agent-browser URL-wait call reported a tool connection timeout after navigation; the subsequent URL read and screenshot confirmed that navigation succeeded. This was not counted as a failed application request.

## Remaining launch operations

Set the actual production `APP_URL`, brand, support address, and Chrome Web Store listing URL; verify the public site after deployment; connect Search Console and submit the sitemap using the owner's account. These steps require a real launch environment and are not accomplished by merging this branch.

Native editorial review, translated screenshot production for every locale, store-listing publication, keyword research, content campaigns, detailed attribution, and field-performance measurement remain deferred. Existing localization limitations are recorded in [the localization review](../2026-09-16-localization/README.md).
