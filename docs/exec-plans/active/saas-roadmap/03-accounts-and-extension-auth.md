# Phase 03: Accounts And Extension Auth

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

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

- [ ] Users can register, verify email, log in, log out, and reset passwords through the Laravel web app.
- [ ] Extension popup can log in with email/password and store only a scoped extension API token plus safe account summary.
- [ ] Extension logout revokes the active token and clears account state.
- [ ] Subtitle job creation, job polling, history, track access, and learning-token enrichment require authenticated user ownership.
- [ ] Existing install ID remains available as a device/abuse signal but is no longer the ownership boundary.
- [ ] Public API errors distinguish unauthenticated, unauthorized, validation, rate-limited, and expired states.
- [ ] Tests cover wrong-user access, revoked tokens, invalid credentials, missing token, unverified user behavior, and install ID abuse controls.

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
- Whether one user may use multiple browser installs and how many active extension tokens are allowed per tier.
- How anonymous pre-auth job history is handled after a user logs in.
- Token lifetime and refresh/re-login behavior.
- Whether unverified or trial users get free beta minutes.

## Validation/Evidence Required

- Backend auth feature tests.
- Extension auth unit tests and compile/build.
- Contract validation and generated type check.
- Security review for token storage, route protection, CORS/host permissions, rate limits, and log sanitization.
- `.\scripts\agent\check.ps1`
- `.\scripts\agent\verify-pr.ps1`

## Risks and Follow-up Debt

- Email/password in an extension popup is higher risk than a browser-based device-code flow and must be kept narrow.
- Token storage in browser extension storage is not equivalent to server-side session security.
- Account migration can accidentally expose old anonymous jobs if ownership is not explicit.
- Auth work touches every extension-facing API and needs broad regression coverage.

