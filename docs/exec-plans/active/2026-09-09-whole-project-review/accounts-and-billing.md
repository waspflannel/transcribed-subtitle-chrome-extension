# Accounts and billing

Created: 2026-09-09
Last updated: 2026-09-09

Use the [index](00-index.md) for shared execution rules, ownership and the old-number map. Historical package numbers below identify audit evidence; they are sections of these consolidated documents, not separate work plans. This consolidation does not authorize new implementation or experiments.

Billing, account deletion during checkout, subscription access and account-verification policy have one owner here. Implementation remains planned; external acceptance is separate.

## Billing and checkout

Former audit section 01.

Status: planned — not started
Owner: unassigned
Type: Confirmed fixes; external acceptance remains separate

### Goal

A paid subscription produces the correct entitlement and minutes, delayed events cannot restore canceled access, and changing plans cannot leave multiple payable checkout sessions.

### Source and evidence

[Review F01–F03](../../../whole-project-review-2026-09-09.md:101): item-level Stripe periods produced active status without access; delayed checkout regressed the event watermark; Base→Plus created two sessions without expiring the first. Deployed Stripe version and actual double payment were not observed.

Coverage: F01, F02, F03; opportunity 1 (billing); E08 (billing portion).

### Dependencies

No other package is required to start. Coordinate final Stripe/staging proof with the [operations security and release](operations-and-release.md#operations-security-and-release) section.

### Scope

Subscription/invoice payload compatibility, monotonic event ordering, outstanding checkout lifecycle, and account deletion while checkout is open. Preserve existing signature validation, owner checks, receipt idempotency, ledger identity, same-plan reuse, and configured portal selection.

Out of scope: AI performance, subtitle normalization, queue redesign, or unrelated pricing/product changes.

### Relevant files and context

- [app/backend/app/Services/Billing/StripeWebhookService.php](../../../../app/backend/app/Services/Billing/StripeWebhookService.php)
- [app/backend/app/Services/Billing/StripeClient.php](../../../../app/backend/app/Services/Billing/StripeClient.php)
- [app/backend/app/Services/Billing/BillingEntitlementService.php](../../../../app/backend/app/Services/Billing/BillingEntitlementService.php)
- [app/backend/app/Http/Controllers/AccountController.php](../../../../app/backend/app/Http/Controllers/AccountController.php)
- [app/backend/tests/Feature/BillingAndUsageTest.php](../../../../app/backend/tests/Feature/BillingAndUsageTest.php)

### Implementation or investigation steps

- [ ] Reproduce the three review cases against the current checkout using isolated database state and HTTP fakes. Inspect current official Stripe documentation and actual configured request/webhook versions when available.
- [ ] Choose a tested version and parse its subscription item periods and invoice parent. Make incomplete active subscription state actionable; preserve existing billing period/renewal semantics.
- [ ] Keep ordering monotonic across checkout refreshes and subscription updates. Cover canceled T+300 → checkout T+100 → active T+200, plus equal-second and different-subscription cases.
- [ ] Expire or safely reconcile superseded pending checkout sessions before issuing alternatives. Cover completion racing plan changes and deletion; do not lose external session identity during partial failures.
- [ ] Run authorized Stripe test-mode checkout, renewal, portal, event-replay and deletion-during-checkout acceptance. Record code completion separately if account access is unavailable.

### Acceptance criteria

- [ ] Provider-shaped current-version fixtures grant the expected current-period access/minutes exactly once.
- [ ] The delayed-checkout sequence remains canceled; stale events cannot regress the ordering marker.
- [ ] Only one outstanding payable session exists under the chosen lifecycle, including cross-plan and deletion races.
- [ ] Failure/retry paths reconcile external Stripe operations without duplicate subscriptions or ledger grants.
- [ ] Request/webhook configuration, rollout and customer reconciliation instructions are documented; external verification is not claimed from fakes.

### Validation and rollout

Run focused billing/account-deletion tests through the real HTTP adapter, then the repository check. Use E08's test-mode matrix without real charges. Capture sanitized event/session IDs and local state. Deployment parser and provider version changes must be coordinated; rollback must not blindly replay grants.

## Account verification and security contract

Moved from former 12 so account behavior is not split across billing and operations plans.

- [ ] Resolve intended email-verification policy and truthful `emailVerified` responses with product intent. Implement or document the agreed contract in a narrow slice; missing verification is not a proven vulnerability without that requirement.
- [ ] Ensure the response and access policy agree and no fabricated verified claim remains under the chosen contract.
- Preserve existing authentication, scoped extension tokens, ownership checks and password-reset token revocation. No authentication rewrite is selected by this consolidation.

Evidence: [review reconciliation](../../../whole-project-review-2026-09-09.md:371). Inspect [ExtensionAuthController](../../../../app/backend/app/Http/Controllers/Api/ExtensionAuthController.php) and [BillingEntitlementService](../../../../app/backend/app/Services/Billing/BillingEntitlementService.php). Coordinate deployed mail/security documentation with [Operations and release](operations-and-release.md).
