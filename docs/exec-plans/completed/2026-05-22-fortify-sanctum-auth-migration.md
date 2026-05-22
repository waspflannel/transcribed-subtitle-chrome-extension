# Plan: Fortify Sanctum Auth Migration

Status: completed
Owner: agent
Created: 2026-05-21
Last updated: 2026-05-22

## Goal

Move the Phase 03 account/auth implementation onto Laravel-maintained auth primitives. Web registration, login, logout, password reset, and email verification should be handled by Laravel Fortify while preserving the current Blade pages and user-facing account workflow.

Extension API authentication should use Laravel Sanctum personal access tokens instead of the custom `extension_api_tokens` table and middleware. Existing custom extension tokens do not need compatibility; users must sign in again and receive a new opaque Sanctum bearer token.

## Scope

- In scope:
  - Install and configure Laravel Fortify and Sanctum.
  - Keep existing Blade auth screens, account routes, `/dashboard`, and `/v1/extension-auth/*` URLs.
  - Replace custom extension token issuance/auth/revocation with Sanctum personal access tokens, 30-day expiry, and existing ability strings.
  - Update contracts, generated types, extension token validation, tests, architecture/security docs, and validation evidence.
- Out of scope:
  - Starter-kit UI scaffolding.
  - Legacy custom-token compatibility.
  - OAuth, social login, two-factor auth, refresh tokens, billing entitlements, and device-code login.

## Acceptance Criteria

- [x] Fortify owns web register/login/logout/password-reset/email-verification routes while current Blade pages still render and tests pass.
- [x] Sanctum tokens protect all extension-facing account, subtitle job, and learning-token routes with the existing ability names.
- [x] Extension login issues an opaque bearer token, enforces verified email and HTTPS in production, and logout deletes the active Sanctum token.
- [x] Old custom token infrastructure is removed or made inert, with a cleanup migration for `extension_api_tokens`.
- [x] Public `/v1/*` auth failures keep stable JSON error codes for unauthenticated, unauthorized, unverified, and invalid-credential states.
- [x] Contracts, generated TypeScript types, extension storage validation, backend tests, extension tests, and harness checks are updated.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-05-22-saas-roadmap-phase-03-accounts-and-extension-auth.md`
- Known risks:
  - Auth package installers may publish default route/config files that conflict with the app's `apiPrefix: ''` `/v1` API shape.
  - Fortify route names may differ from the current custom `login.store` and `register.store` form targets.
  - Sanctum tokens are opaque and will intentionally invalidate existing extension sessions.
  - Browser-extension local storage remains a bearer-token storage boundary.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Install Fortify/Sanctum and publish only needed config/providers/migrations.
- [x] Configure Fortify with existing views and remove custom web auth controller ownership.
- [x] Replace extension API token model/middleware/routes with Sanctum token issuance and middleware.
- [x] Update contracts, generated types, extension token storage validation, and tests.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend
php artisan test --compact tests\Feature\WebAuthTest.php tests\Feature\ExtensionAuthApiTest.php tests\Feature\SubtitleJobApiTest.php tests\Feature\ContractResponseValidationTest.php tests\Feature\ContractBoundaryTest.php
vendor\bin\pint --dirty --format agent
Pop-Location
Push-Location .\packages\contracts
npm run check
Pop-Location
Push-Location .\app\extension
npm test
npm run compile
npm run build
Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests: targeted backend auth/API tests, contracts check/build, extension tests, full harness check.
- Screenshots or video: not required unless manual browser auth UI regression is found.
- Logs: route list and validation output when useful.
- Metrics or traces: not applicable.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-22 | Use Fortify with existing Blade views instead of a starter-kit UI. | Reduces custom auth ownership without broad frontend scaffolding churn. |
| 2026-05-22 | Use Sanctum personal access tokens for extension bearer auth and force relogin for old custom tokens. | Removes custom token security code and avoids a temporary compatibility layer. |
| 2026-05-22 | Preserve `/v1` URL shape and stable public error codes. | The extension and contracts already depend on these API boundaries. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-21 | Plan created. |  |
| 2026-05-22 | Baseline harness and Laravel docs review completed before migration. | `.\scripts\agent\doctor.ps1` passed; `.\scripts\agent\check.ps1` passed with 158 backend tests, 56 extension tests, contracts build/check, TypeScript compile, and WXT build. Context7 Laravel 13 docs checked for Fortify and Sanctum. |
| 2026-05-22 | Installed Fortify/Sanctum, trimmed Fortify to approved auth features, wired existing Blade views, and moved extension route protection to Sanctum tokens. | `composer require laravel/fortify laravel/sanctum --no-interaction`; `php artisan fortify:install --no-interaction`; Sanctum config/migration publish; `php artisan route:list` confirmed Fortify owns web auth routes and app-owned `/v1/*` routes remain unchanged. |
| 2026-05-22 | Updated contracts and extension storage/tests for opaque Sanctum bearer tokens instead of `tse_` tokens. | `php artisan test --compact tests\Feature\WebAuthTest.php tests\Feature\ExtensionAuthApiTest.php` passed; `npm run check` in `packages/contracts` passed and regenerated TypeScript types. |
| 2026-05-22 | Verified broader auth-owned API boundaries after fixing Sanctum test guard caching and install-ID middleware priority. | `php artisan route:list --path=v1/subtitle-jobs -v`; `php artisan test --compact tests\Feature\WebAuthTest.php tests\Feature\ExtensionAuthApiTest.php tests\Feature\SubtitleJobApiTest.php tests\Feature\ContractResponseValidationTest.php tests\Feature\ContractBoundaryTest.php` passed with 68 tests and 519 assertions. |
| 2026-05-22 | Verified extension account/token changes. | `npm test`, `npm run compile`, and `npm run build` in `app/extension` passed with 56 tests and a successful WXT production build. |
| 2026-05-22 | Updated affected Symfony packages after Composer audit surfaced advisories in the lockfile. | `composer update symfony/http-kernel symfony/mailer symfony/mime symfony/routing symfony/yaml --with-dependencies --no-interaction`; Composer reported no remaining security advisories. |
| 2026-05-22 | Final harness and PR-readiness validation passed. | `vendor\bin\pint --format agent`; `.\scripts\agent\check.ps1` passed with 159 backend tests, 56 extension tests, contracts build/check, TypeScript compile, and WXT build; `.\scripts\agent\doc-gardening.ps1` reported no findings; `.\scripts\agent\verify-pr.ps1` passed. |

## Completion Notes

- What changed: Fortify now owns web auth routes with existing Blade views; Sanctum personal access tokens now own extension bearer auth; custom extension token model/middleware/table creation was removed with a cleanup migration; contracts and extension storage now treat tokens as opaque bearer strings.
- Validation results: targeted backend auth/API tests, contracts check/build, extension tests/compile/build, full harness check, doc-gardening, verify-pr, Pint, and Composer audit remediation passed.
- Simplicity/readability review: removed unused Fortify passkey/two-factor scaffolding, kept only registration/reset/email-verification features, retained the existing `/v1` API boundary, and avoided a legacy custom-token bridge.
- Residual risk: existing extension sessions using custom `tse_` tokens must sign in again; browser extension storage still holds a bearer token by design.
- Follow-up debt: none added.
