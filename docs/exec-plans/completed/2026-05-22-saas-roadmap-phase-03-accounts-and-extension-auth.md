# Phase 03: Accounts And Extension Auth

Status: completed
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-22

## Goal

Add real SaaS identity so subtitle jobs, generated tracks, usage, billing, and support can belong to authenticated users instead of anonymous extension install IDs.

The user preference for beta is email/password login inside the extension popup. Implement that as a scoped token flow: the extension submits credentials over HTTPS, the backend returns a scoped API token, and the background script uses that token for extension API requests.

## Scope

- In scope:
  - Laravel user accounts, email/password login, logout, password reset, and email verification.
  - Scoped extension API tokens using Laravel's documented API-token pattern unless implementation review selects a better first-party fit.
  - Account/job/track ownership migration from install ID only to authenticated user plus install ID.
  - Extension login/logout/account state.
  - Authenticated API contracts for extension calls.
- Out of scope:
  - Social login.
  - Team, school, or organization accounts.
  - Two-factor authentication unless beta risk requires it.
  - Billing implementation beyond account fields needed for later integration.

## Acceptance Criteria

- [x] Users can register, verify email, log in, log out, and reset passwords through the Laravel web app.
- [x] Extension popup can log in with email/password and store only a scoped extension API token plus safe account summary.
- [x] Extension logout revokes the active token and clears account state.
- [x] Subtitle job creation, job polling, history, track access, and learning-token enrichment require authenticated user ownership.
- [x] Existing install ID remains available as a device/abuse signal but is no longer the ownership boundary.
- [x] Public API errors distinguish unauthenticated, unauthorized, validation, rate-limited, and expired states.
- [x] Tests cover wrong-user access, revoked tokens, invalid credentials, missing token, unverified user behavior, and install ID abuse controls.

## Key Implementation Areas

- Backend auth:
  - Add user model, auth routes, session-backed web auth, password reset, email verification, and guarded dashboard routes.
  - Add scoped extension token issuance and revocation endpoints.
  - Protect extension-facing generation routes with token authentication.
- Data ownership:
  - Add `user_id` ownership to subtitle jobs, tracks, artifacts/events where needed through job relations.
  - Preserve install ID for device limits, support diagnostics, and abuse throttles.
  - Decide migration behavior for anonymous local/test data.
- Extension auth state:
  - Add login/logout messages through popup/background.
  - Store token in extension storage and attach `Authorization: Bearer` in the API client.
  - Ensure content scripts never receive raw credentials.
- Contracts:
  - Add account/session response schemas and stable auth error codes.
  - Update generated TypeScript types.
- Security:
  - Require HTTPS in production.
  - Never store provider credentials or web session cookies in extension state.
  - Add rate limits for login and token issuance.

## Required Product Decisions

- Whether email verification is required before any generation or only before paid checkout.
  - Decision: require verified email before extension token issuance and generation in beta.
- Whether one user may use multiple browser installs and how many active extension tokens are allowed per tier.
  - Decision: allow multiple installs for now; bind every token to an install ID and leave tier-specific active-token caps to billing/entitlement phases.
- How anonymous pre-auth job history is handled after a user logs in.
  - Decision: do not auto-claim anonymous install history. New authenticated jobs are user-owned; old anonymous rows are left inaccessible through authenticated extension APIs.
- Token lifetime and refresh/re-login behavior.
  - Decision: extension tokens expire after 30 days and are refreshed by logging in again. No refresh token is added for beta.
- Whether unverified or trial users get free beta minutes.
  - Decision: unverified users cannot create extension tokens; trial/free-minute policy remains in the billing phase.

## Refined Slices

1. Backend account and token foundation.
   - Builds: user/password reset/session tables, minimal Laravel web auth routes, email verification, hashed scoped extension token table, token issuance/revocation/account endpoints, and public auth error codes.
   - Defers: social login, two-factor auth, token refresh, paid-plan caps, and dependency changes such as Sanctum unless explicitly approved later.
   - Touches: migrations, user/token models, auth controllers/requests/middleware, API contracts, feature tests.
   - Validation: backend auth tests and contract check.
2. Authenticated subtitle ownership.
   - Builds: `user_id` ownership on subtitle jobs, user-scoped job create/list/show and learning-token enrichment, and install ID as a device/abuse signal only.
   - Defers: anonymous history migration and tier entitlement enforcement.
   - Touches: subtitle job service/controller/request/resource relationships, factories, existing API tests.
   - Validation: wrong-user, missing token, revoked token, unverified user, and install-ID abuse tests.
3. Extension login/logout/account state.
   - Builds: popup login/logout messaging, hashed-token-safe local storage, Authorization header attachment, account summary rendering, and logout state clearing.
   - Defers: browser-based device-code login and paid billing UI.
   - Touches: extension API client, storage helpers, background message handling, popup account panel, Vitest coverage.
   - Validation: extension API/storage/message tests, TypeScript compile, WXT build.

## Decision Log

| Date | Decision | Reason |
| --- | --- | --- |
| 2026-05-22 | Do not add Laravel Sanctum in this phase without explicit dependency approval; implement a narrow first-party Laravel token table using hashed bearer tokens and scoped abilities. | Backend local guidelines forbid dependency changes without approval, while the beta needs a small inspectable token flow now. |
| 2026-05-22 | Require verified email before issuing extension tokens. | The extension can create provider-costing jobs, so beta access should prove inbox ownership before token issuance. |
| 2026-05-22 | Keep old anonymous install-owned jobs inaccessible after login instead of silently claiming them. | Auto-claiming by install ID could expose another user's old local/test jobs on shared browser profiles. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-22 | Baseline harness and app validation passed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` passed: contracts check/build, 147 backend tests, 51 extension tests, TypeScript compile, WXT build. |
| 2026-05-22 | Added Laravel web account auth, email verification, password reset, hashed scoped extension tokens, token auth middleware, account/token contracts, and user-owned subtitle job boundaries. | `php artisan test --compact tests\Feature\ExtensionAuthApiTest.php tests\Feature\WebAuthTest.php tests\Feature\ContractResponseValidationTest.php tests\Feature\ContractBoundaryTest.php`; `php artisan test --compact tests\Feature\SubtitleJobApiTest.php`; `vendor\bin\pint --dirty --format agent`. |
| 2026-05-22 | Added extension login/logout state, scoped-token storage, Authorization headers, account summary rendering, and contract/type coverage. | `Push-Location .\packages\contracts; npm run check; Pop-Location`; `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location`. |
| 2026-05-22 | Updated durable architecture, security, contract, and schema docs for authenticated ownership and extension token behavior. | `.\scripts\agent\doc-gardening.ps1` reported no findings. |
| 2026-05-22 | Final harness and PR readiness checks passed. | `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1` passed: contracts check/build, 158 backend tests, 56 extension tests, TypeScript compile, WXT build. |

## Validation/Evidence Required

- Backend auth feature tests passed: `php artisan test --compact tests\Feature\ExtensionAuthApiTest.php tests\Feature\WebAuthTest.php tests\Feature\ContractResponseValidationTest.php tests\Feature\ContractBoundaryTest.php`.
- Existing subtitle API feature tests passed after the ownership migration: `php artisan test --compact tests\Feature\SubtitleJobApiTest.php`.
- Full backend suite passed: `php artisan test --compact` with 158 tests and 852 assertions.
- Extension auth/storage/API tests, TypeScript compile, and WXT build passed: `npm test`, `npm run compile`, `npm run build`.
- Contract validation and generated type check passed: `npm run check` in `packages/contracts`.
- Security review: tokens are stored hashed server-side, extension storage keeps only the scoped token plus safe account summary, production login rejects non-HTTPS requests, logout revokes the active token, route middleware distinguishes unauthenticated/unauthorized/expired/unverified states, and logs avoid raw credentials/tokens.
- `.\scripts\agent\check.ps1` passed.
- `.\scripts\agent\verify-pr.ps1` passed.

## Risks and Follow-up Debt

- Email/password in an extension popup is higher risk than a browser-based device-code flow and must be kept narrow.
- Token storage in browser extension storage is not equivalent to server-side session security.
- Account migration can accidentally expose old anonymous jobs if ownership is not explicit.
- Auth work touches every extension-facing API and needs broad regression coverage.

## Completion Notes

- Implemented verified Laravel accounts, password reset, email verification, session-backed web auth, and minimal account dashboard routes.
- Implemented scoped hashed extension bearer tokens without adding a new dependency, because backend guardrails require dependency approval before adding Sanctum.
- Moved subtitle job reuse/history/show and learning-token access from install-owned queries to authenticated `user_id` ownership; install ID remains required and persisted for device/abuse controls.
- Added account/login/logout schemas, OpenAPI paths, generated TypeScript types, extension token storage, Authorization headers, and popup login/logout controls.
- No follow-up debt was added. Billing-tier token caps, anonymous-history claiming, and refresh-token behavior remain explicitly deferred to later roadmap phases rather than Phase 03 debt.
