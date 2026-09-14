# Plan: Update privacy and terms pages

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-14
Last updated: 2026-09-14

## Goal

Update the existing Privacy and Terms pages for the current product and repair their document layout while preserving the Ink & Marker theme.

## Scope

- In scope: legal-page content, navigation, responsive typography, metadata, existing page assertions, and visual evidence on `codex/marketing-feature-refresh`.
- Out of scope: changing data handling, provider configuration, billing behavior, or extension code; publishing; choosing a legal entity or jurisdiction for the owner.

## Acceptance Criteria

- [x] Explain model providers, lyrics corrections, browser storage, analytics, retention, and account deletion accurately against the current implementation.
- [x] Explain recurring billing, video minutes, cancellation, refunds, content rights, and AI/timing limitations.
- [x] Replace the narrow padded article with readable desktop and mobile layouts, section links, and a revision date.
- [x] Keep both documents accessible without the site script and retain existing site styling.
- [x] Run relevant tests, formatting, browser checks, and the repository documentation harness.

## Relevant Context

- Product: `docs/product-specs/index.md` and the completed marketing refresh plan.
- Design: `docs/DESIGN.md`, `docs/FRONTEND.md`.
- Quality rules: `docs/quality/golden-principles.md`.
- Existing pages used `.section.legal-copy`, which combined viewport gutters with a 720px maximum outer width and squeezed the text. Their short beta copy omitted current features.
- Product evidence reviewed: `MarketingPageController`, `AccountController`, `FunnelAnalytics`, `LyricsCorrectionService`, transcript caching/pruning, billing/usage services, extension account/preferences/active-track storage, and the shared site layout.
- Operator identity, business jurisdiction, and a real privacy contact address are not defined by the repository. The user was asked for these details; no answer was available during implementation. The pages use the configured product name and existing support route, without an invented legal entity, address, governing-law clause, or placeholder email.

## Implementation Steps

- [x] Inspect the existing pages and verify processing and deletion behavior.
- [x] Rewrite the two Blade documents and their metadata.
- [x] Separate outer gutters from the article; add native numbered navigation and responsive rules.
- [x] Update existing SEO/page assertions and design guidance.
- [x] Review simplicity, browser behavior, and validation evidence.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-14 | Use ordinary Blade markup and shared CSS, with no new script or dependency. | Two static documents need readable structure and native links. |
| 2026-09-14 | Remove scroll reveal from the documents. | A long article can exceed the reveal intersection threshold and must remain readable without JavaScript. |
| 2026-09-14 | Name OpenAI, Cerebras, and ElevenLabs as processors. | Public model branding does not replace disclosure of the companies receiving content; full lyrics correction uses OpenAI even for Spark tracks. |
| 2026-09-14 | Describe account deletion separately from the public transcript cache and provider records. | Account deletion cancels subscriptions and removes account-linked data, but does not delete Stripe's own records or the independent shared cache. |
| 2026-09-14 | Avoid training, zero-retention, data-location, or legal-compliance guarantees. | Provider account settings, infrastructure regions, and applicable business jurisdiction were not established. |

## Source Review

Product facts come from the repository. External notices were reviewed to identify provider information and keep links useful; the pages do not claim that a provider's general website notice replaces its service agreement.

- [OpenAI business data privacy](https://openai.com/enterprise-privacy/)
- [Cerebras privacy policy](https://www.cerebras.ai/privacy-policy)
- [ElevenLabs privacy policy](https://elevenlabs.io/privacy-policy)
- [Stripe privacy policy](https://stripe.com/privacy)
- [Google Fonts privacy information](https://fonts.googleblog.com/2022/11/your-privacy-and-google-fonts.html)
- [OPC guidance on meaningful consent](https://www.priv.gc.ca/en/privacy-topics/privacy-laws-in-canada/the-personal-information-protection-and-electronic-documents-act-pipeda/p_principle/principles/p_consent/) informed plain-language disclosure structure; Canadian jurisdiction was not assumed.

## Validation Results

- `php artisan test --compact tests/Feature/SaasWebsiteAndSeoTest.php`: 19 tests passed, 225 assertions.
- `php vendor/bin/pint --dirty --format agent`: passed.
- `scripts/agent/check.ps1 -SkipAppChecks`: documentation checks; the focused website suite covers this content/CSS change. Full application checks already passed for the parent marketing refresh; unrelated extension builds were not repeated.
- `git diff --check`: passed.
- Browser: Privacy at 1440px and 1920px keeps a 760px text column; 390px and 320px have no horizontal overflow. Terms at 1440px and 320px has readable wrapping. All 17 contents links have valid targets.
- Keyboard Enter follows contents links. Desktop target starts at 92px below the 68px header; mobile targets start at 120px below the 98px header. Reduced-motion preference honored.
- Blocked the exact site script URL and reloaded both pages: all sections remain visible; native Terms navigation still works. Restored script loading afterward. No JavaScript errors.
- Visual evidence: `docs/design-assets/marketing-feature-refresh/qa/privacy-desktop.png`, `privacy-mobile.png`, `privacy-mobile-content.png`, `terms-desktop.png`, and `terms-mobile-content.png`.

## Completion Notes

- Replaced the old beta placeholders with structured privacy and service terms covering the current feature set.
- Preserved the site's palette, type stack, notebook background, and marker treatment. Added no dependencies or JavaScript and kept content in two plain Blade templates.
- Business identity and jurisdiction-specific provisions remain for owner confirmation before publication. Existing support contact configuration also needs the owner's real details; the default is not treated as a valid legal contact. This change does not attest to compliance in an unconfirmed jurisdiction.
- Branch work only; no remote push or deployment.
