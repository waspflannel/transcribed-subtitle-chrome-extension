# Plan: Open source BYOK application

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-30
Last updated: 2026-09-30

## Goal

Convert Transcribe to a personal local/self-hosted BYOK application. Keep the existing parallel subtitle and learning pipeline; remove accounts, payments, minute credits and commercial speed tiers.

## Scope

- Remove account/billing UI, API, runtime dependencies and checks, tier queues and caps.
- Add encrypted backend provider settings with OpenAI, Cerebras, ElevenLabs and optional TypeSafe fields. Never return saved keys to clients or log/flash input.
- Use real provider/model labels. Check required keys before generation. Make lyrics replacement use the saved analysis provider/model.
- Remove the video duration product cap; warn above 30 minutes without blocking.
- Optional saved-track expiry, default disabled. Temporary audio cleanup stays mandatory.
- Preserve history, corrections, on-demand cards, caching, localization and study controls.
- Single-owner private instance; no shared public multi-user hosting, new AI providers, publishing or deployment in scope.

## Acceptance Criteria

- [x] Generate without sign-in/payment using configured ElevenLabs and selected analysis provider.
- [x] Missing keys fail clearly before new provider work; secrets remain backend-only and encrypted.
- [x] All generation uses shared generation/analysis pools with configurable technical bounds.
- [x] History and editing work across extension installations attached to the same backend.
- [x] Videos above an hour are accepted; a non-blocking warning appears above 30 minutes.
- [x] Tracks persist indefinitely by default; optional expiry works with history, reuse and pruning.
- [x] No active Stripe, account or subscription features remain.
- [x] Contract, backend, extension and repository checks pass.

## Relevant Context

- Architecture: `ARCHITECTURE.md`
- Product: `docs/product-specs/index.md`
- Quality rules: `docs/quality/golden-principles.md`
- Skills: Ponytail, Laravel best-practices, Laravel security, subtitle pipeline and AI SDK.
- Branch: `codex/open-source-byok`. Prior uncommitted changes are preserved; baseline patch and status are in `.git/byok-starting-*`.

## Implementation Steps

- [x] Inspect current state and agree product scope.
- [x] Refactor processing, persistence and private-instance API.
- [x] Add provider setup and remove commercial frontend workflows.
- [x] Update contracts, tests, deployment setup and current docs.
- [x] Review and validate the integrated implementation.

## Validation Plan

Run targeted checks during implementation, then `scripts/agent/check.ps1`. Use isolated SQLite/array/sync test configuration; no runtime migration or paid provider requests. Validate browser layout with local fixtures where available.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-30 | Personal instance owns saved generations; no account or synthetic user. | Matches the chosen local/self-hosted deployment. |
| 2026-09-30 | Encrypted database settings overlay environment keys and refresh per HTTP/queue job. | Provides key fields without worker restarts or browser secret persistence. |
| 2026-09-30 | Restrict no-login instance to loopback/configured trusted networks and expected hosts/origins. | Account removal must not expose keys and history to arbitrary browser pages or public clients. |
| 2026-09-30 | Keep historical migrations and inert legacy records; remove live SaaS code. | Preserves upgrade history and avoids deleting unrelated account/billing records. |
| 2026-09-30 | Context7 quota exhausted; official Laravel docs and installed source used. | Verified encrypted casts and actual provider-manager cache reset behavior. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-30 | Branch created; three parallel work areas assigned. | Pipeline, settings/web, extension/contracts. |
| 2026-09-30 | Runtime, settings, website and extension implemented; nine locales updated. | Targeted API tests, 342 extension tests, contracts, compile and build pass. |
| 2026-09-30 | Tested legacy upgrade on disposable Postgres 17 and provider controls on disposable Redis 7. | Duplicate saved tracks preserved, old runs fenced, account/tier columns removed, encrypted keys and retention updates verified; 2 Redis integration tests pass. |
| 2026-09-30 | Fixed retention/finalization race and preserved original generation dates during corrections. | Shared settings-before-job lock; Postgres regression verifies both update orders with real lock contention. Independent review found no remaining blocker. |
| 2026-09-30 | Full repository harness passed with disposable Postgres/Redis integration tests enabled. | 593 backend tests, 27,796 assertions; 342 extension tests in 32 files; contracts, TypeScript, Chrome build, release guards and docs lint passed. |

## Completion Notes

Merge preparation: the user approved merging this chat's work into main. Earlier uncommitted Scribe point-timestamp fixes, their version assertions and agent-workflow notes were separated and preserved locally. Luna model updates required by the fixed-model BYOK request are included. The isolated merge tree passes 565 backend tests / 21,582 assertions and 342 extension tests, contracts, TypeScript, Chrome build and release guards; four optional service integration tests are skipped. The lower backend count reflects excluded timing regressions, not removed BYOK coverage. Evidence: `.git/byok-merge-check.log`. Changes are grouped into the BYOK application and guide-only website commits; no runtime migrations or provider calls are part of the merge.

Provider settings follow-up: one card contains three API key inputs and a Set button. Saved-key status is shown with an accessible checkmark or cross; retention is a separate card. Fixed models are OpenAI `gpt-6-luna`, Cerebras `gpt-oss-120b` and ElevenLabs `scribe_v2`. Removed model edits from the API contract, validation and UI; old saved model overrides and model environment variables are ignored. Existing jobs retain their pinned model. Credentials remain masked, encrypted and cleared from input/submission memory after saving. Explicit key removal remains available.

Validation: full harness passed (581 backend tests / 21,614 assertions, 342 extension tests, contract checks, TypeScript, Chrome build and release guards; four optional service integration tests skipped). Regression checks cover three inputs in one card, no editable model, both saved-status states, key privacy, API rejection of model edits and all three legacy overrides. At 375px, a temporary preview using the real settings renderer with fake data showed no overflow and changed a cross to a checkmark after a local save; no provider API call or real key change was made. Evidence: `.git/fixed-model-settings-check.log`. Extension output was rebuilt; reload it to use the new UI. Laravel config/validation skill review preserved worker provider-cache invalidation and existing job model pinning.

Website simplification follow-up: the existing How To Use guide now renders at `/` and the eight other localized home URLs. Removed the landing, privacy, terms and support views/routes, marketing navigation/footer, language/FAQ aliases, marketing-only CSS/animations and 185 unused website translations per locale. Old guide and installation links redirect directly to the localized homepage or its installation anchor; sitemap/canonical/alternate links list only the nine home URLs. Laravel routing/Blade skill guidance favored the existing named-route and locale helpers rather than a new page layer.

Follow-up validation: full `scripts/agent/check.ps1` passed (580 backend tests / 21,580 assertions, 342 extension tests, contracts, TypeScript, Chrome build and release guards; four optional service integration tests skipped). Existing website tests now cover homepage guide content, deleted routes, all nine locales, direct redirects and the reduced sitemap, preserving the legacy-session regression. Pint, docs lint and whitespace checks pass. Browser checks at 1280px and 375px show no horizontal overflow; guide disclosure and anchor navigation work with no console errors. Local evidence: `.git/guide-home-check.log`, `.git/guide-home-desktop.jpg`, `.git/guide-home-mobile.jpg`. No deployment or database changes were required.

Follow-up: previously signed-in browsers could still load the deleted User model because Laravel database sessions call the auth guard while saving. Removed StartSession and ShareErrorsFromSession from read-only web routes, preserving encrypted locale cookies and using native origin-only request-forgery protection. No dummy User model or restored auth layer is needed.

The new regression forces the database session driver with an old login payload; it reproduced the reported HTTP 500 before the fix and now renders six site paths without session/XSRF cookies. Website/localization/security tests pass (13 tests); the running local home page and guide return HTTP 200. Default array-session tests had hidden the upgrade case. Installed framework source and [Laravel request-forgery documentation](https://laravel.com/docs/13.x/csrf) confirm the stateless configuration. Context7 remained quota-blocked. The follow-up full harness passed: 590 backend tests, 342 extension tests, contracts, compile/build and release guards; four optional Postgres/Redis cases were skipped because their disposable containers were already removed. Pint and whitespace checks passed. Evidence: `.git/byok-session-check.log`.

Implemented on `codex/open-source-byok`; no commits or publishing performed. Prior uncommitted work remains preserved. Setup and upgrade instructions are in the root README and private-instance operations guide.

Validation: `scripts/agent/check.ps1` passed with `SUBTITLE_DISPOSABLE_PG_*` pointing to disposable Postgres 17 and `SUBTITLE_TEST_REDIS_PORT` pointing to disposable Redis 7. All 593 backend and 342 extension tests ran without skips. The separate Postgres upgrade check preserved two account-owned copies of a saved video, cleared their deadlines, fenced interrupted runs, removed ownership/tier columns and verified encrypted settings plus retention toggling. PowerShell parsing passed for all four changed scripts. Pint and `git diff --check` passed. Test containers were removed after validation. Local command logs and the starting-work snapshot remain under `.git/byok-*`.

Live runtime migrations and paid provider calls were not run. Browser visual QA was unavailable (no browser listed); extension DOM tests cover the settings flow. Existing Composer audit advisories remain in Laravel, CommonMark and Flysystem; this change removes dependencies without upgrading unrelated packages. Historical account/billing tables remain inert to preserve upgrade data. Future provider integrations and selecting/publishing an open-source license are outside this implementation.
