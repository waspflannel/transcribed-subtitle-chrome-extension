# Plan: Localize website and extension in nine languages

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-16
Last updated: 2026-09-16

## Goal

Provide a saved interface-language choice and complete website/extension UI translations for English, Spanish, Brazilian Portuguese, French, German, Japanese, Korean, Indonesian and Simplified Chinese on `codex/website-extension-localization`.

## Scope

- Website marketing, guide, legal, auth, account and billing copy; metadata and language links.
- Extension panel, overlay controls, dynamic progress/errors, language names and browser manifest.
- No change to generated subtitles, video titles, study-language choices, API identifiers, payments or generation behavior. No deployment.

## Acceptance Criteria

- [x] Nine supported interface locales with native names and browser-language matching.
- [x] Website query links preserve the page and parameters; preference cookie survives sign-out.
- [x] Extension saves interface language independently of study settings and updates open content through existing settings broadcasts.
- [x] Native Laravel translations, escaped markup placeholders and bundled TypeScript dictionaries; no runtime translation requests or new dependency.
- [x] Catalog parity, placeholder integrity, request isolation, locale fallback and public/auth rendering checks.
- [x] Complete browser review and required harness validation.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`.
- Architecture: `ARCHITECTURE.md`; shared interface catalog under `packages/localization`.
- Quality: `docs/quality/golden-principles.md`, `docs/references/project-guardrails.md`.
- Skills: ponytail, Laravel best practices, Laravel AI SDK for temporary translation drafting, agent-browser for UI review.
- Boost MCP was not exposed. Context7 supplied Laravel 13 localization and anonymous-agent guidance; installed SDK source confirmed usage.

## Implementation Steps

- [x] Inspect current website and extension flows and create branch.
- [x] Add locale choice, native website translation calls and explicit extension translation calls.
- [x] Draft and validate eight additional catalogs through the configured provider, storing only public interface copy.
- [x] Add locale behavior and catalog regression tests.
- [x] Finish visual review, full harness, documentation and self-review.

## Validation Plan

- `php artisan test --compact tests/Feature/WebsiteLocalizationTest.php`
- `npm test`, `npm run compile`, Chrome and Firefox production builds.
- `php vendor/bin/pint --dirty --format agent`
- `scripts/agent/check.ps1`
- Real local Laravel pages and the existing isolated side-panel fixture via agent-browser; screenshots under `docs/review-evidence/2026-09-16-localization/`.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-16 | Keep interface locales separate from subtitle-language catalog. | Choosing Japanese UI must preserve every source/target pair and existing track. |
| 2026-09-16 | Use `?lang=` variants and an encrypted preference cookie. | Keep existing auth/account routes and form actions; preserve registration/reset parameters; permit shareable/indexable localized pages. |
| 2026-09-16 | Share locale names; keep website and extension message files separate. | Avoid shipping long marketing/legal copy in content scripts. |
| 2026-09-16 | Translate complete phrases with protected markup/value placeholders. | Permit natural word order while escaping translated strings and dynamic values. |
| 2026-09-16 | Update only explicitly marked static extension copy when locale changes. | Preserve drafts, learning content and active progress labels on normal polling. |
| 2026-09-16 | AI-draft and mechanically validate translation catalogs. | Produce complete maintained assets now; native-speaker editorial review remains a quality limit. Existing guide images remain English examples with translated surrounding instructions. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-16 | Existing public/auth website tests pass after localization. | 32 tests, 398 assertions. |
| 2026-09-16 | Added focused localization validation. | 5 backend tests, 31,331 assertions; 15 extension locale/account/panel tests. |
| 2026-09-16 | Corrected static-copy refresh overwriting unchanged progress; preserved inline whitespace. | Existing panel integration test and narrow German browser review. |
| 2026-09-16 | Final review corrected untranslated guide/legal/form fragments, saved-generation caching, technical identifiers, and narrow language columns. | API endpoint checks in all nine locales, transcript draft/active-cue preservation, rendered public-page comparison, browser screenshots. |
| 2026-09-16 | Completed required validation and native manifest packaging. | Harness: 689 backend tests / 37,044 assertions, 355 extension tests, TypeScript, contracts, Chrome build; separate Firefox build and Pint pass. Nine environment-dependent backend tests skipped. |

## Completion Notes

- Implemented on `codex/website-extension-localization`, with 683 website messages and 469 extension messages in each of the nine catalogs.
- Browser screenshots, validation details, and limits are recorded in `docs/review-evidence/2026-09-16-localization/README.md`.
- Maintenance instructions live in `packages/localization/README.md`. The backend release must retain this shared package alongside `app/backend`, as it does the shared contracts package.
- Translation assets are AI drafts with structural checks, not native-speaker certification. English guide images, external service pages and transactional emails are outside the interface-copy work. No runtime translation service or dependency was added.
