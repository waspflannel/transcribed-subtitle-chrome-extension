# Phase 04: Billing Tiers And Usage

Status: completed
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-22

## Goal

Turn the authenticated product into a paid beta SaaS with generous minute-credit tiers, tier-based speed, and enforceable usage controls.

Users should understand pricing in generated video minutes. Internally, the system should track provider/model cost so plan limits can protect margins without exposing AI token accounting to normal users.

## Scope

- In scope:
  - Stripe/Cashier-style subscriptions, hosted checkout, billing portal, and webhook handling.
  - Subscription status and plan entitlement model.
  - Monthly minute-credit ledger with reservation, debit, refund, and reset behavior.
  - Tier-based speed, concurrency, feature gates, and usage limits.
  - Admin/support adjustments for beta users.
- Out of scope:
  - Team billing, seats, invoicing for schools, and enterprise plans.
  - Public usage-based billing without subscriptions.
  - Tax/legal/accounting automation beyond hosted Stripe capabilities needed for beta.

## Acceptance Criteria

- [x] Users can start, manage, cancel, and resume subscriptions through hosted Stripe flows.
- [x] Stripe webhooks update local subscription state idempotently and securely.
- [x] Subtitle generation checks active subscription, available minutes, concurrency, tier feature gates, and abuse limits before provider work starts.
- [x] Minute credits are reserved when a job starts, debited when it completes, and refunded or released when it fails before producing a track.
- [x] Compatible cached/reused tracks do not double-charge minutes.
- [x] Higher tiers receive faster queue priority, larger minute pools, higher concurrency, and access to premium generation options.
- [x] Usage and entitlement data are visible in the web dashboard and extension.
- [x] Tests cover successful checkout state, webhook replay, failed payment, cancellation, plan change, reservation, debit, refund, and denied generation.

## Key Implementation Areas

- Billing integration:
  - Add subscription and customer fields using Laravel Cashier conventions unless implementation review chooses a narrower Stripe integration.
  - Use hosted checkout and billing portal rather than custom payment forms.
  - Verify webhook signatures and record handled events.
- Plan model:
  - Define plan codes, Stripe price IDs, monthly minutes, speed priority, concurrency, retention, max video duration if needed, and feature flags.
  - Keep plan config inspectable and environment-safe.
- Usage ledger:
  - Add append-only usage events for reservations, debits, refunds, adjustments, and monthly grants.
  - Store public minute units and internal cost estimates separately.
  - Tie usage events to user, job, track, plan, and billing period.
- Entitlement checks:
  - Run before queue dispatch and again before provider-heavy stages when needed.
  - Keep stable public error codes for payment required, usage exhausted, feature unavailable, and concurrency exceeded.
- Admin/support:
  - Add manual usage adjustment notes for beta support.
  - Expose enough billing state to debug user issues without Stripe dashboard hopping for every case.

## Required Product Decisions

- Initial beta plans:
  - Base: $9/month, 90 generated-video minutes, standard queue, 1 concurrent generation.
  - Plus: $19/month, 240 generated-video minutes, priority queue, 2 concurrent generations.
  - Pro: $39/month, 600 generated-video minutes, fast queue, 3 concurrent generations.
- Overage behavior: deny generation with `usage_exhausted`; no public overage billing during beta.
- Full word-card mode consumes normal generated-video minutes but is available only on Plus and Pro.
- Failed jobs release reserved minutes when no completed track is produced.
- Compatible cached/reused completed tracks are returned without another reservation or debit.
- Unused minutes do not roll over during beta; each Stripe billing period receives a fresh grant.
- Plan downgrades are expected to take effect at period end through Stripe-hosted subscription management; the app trusts Stripe webhook period/price state and never grants more than the active period's configured minutes.

## Implementation Review

- Cashier is not installed and `app/backend/AGENTS.md` says not to change dependencies without approval. This implementation will use a narrow Stripe HTTP adapter shaped like hosted Cashier flows rather than adding `laravel/cashier`.
- Stripe-hosted checkout and billing portal remain the only payment-management UI. The app will not collect card data.
- Webhook verification uses the raw payload, `Stripe-Signature`, HMAC SHA-256 `v1` signatures, and timestamp tolerance per current Stripe documentation.
- Billing state is local and inspectable on `users`; Stripe events are idempotently recorded before local state mutation.
- Usage credits are append-only ledger events. Public minute accounting is separate from internal provider-cost telemetry already stored on subtitle jobs.
- The extension remains a thin client: it displays account/usage/entitlement state from the backend and maps stable public denial codes to user-safe copy.
- Laravel Boost skills loaded for this phase: `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, `laravel-security`, and `subtitle-pipeline`. Generic package-install recommendations are intentionally skipped because this repository requires approval before dependency changes.

## Integration Slices

1. Billing and usage persistence:
   - Add Cashier-style Stripe customer/subscription fields to users.
   - Add idempotent Stripe webhook event storage.
   - Add append-only usage events for grants, reservations, debits, releases/refunds, and adjustments.
   - Validation: migration-backed model tests and targeted billing service tests.
2. Plan, entitlement, and ledger services:
   - Define plan config with Stripe price IDs, public minutes, speed tier, concurrency, and feature gates.
   - Gate generation by active subscription, minutes, concurrency, and full-word-card entitlement before dispatch.
   - Reserve on new/reset jobs, adjust after actual audio duration, debit on completed track, and release on failure.
   - Validation: API tests for denied generation, reservation/debit/release, cache reuse, concurrency, and feature gates.
3. Stripe hosted flows and webhooks:
   - Add dashboard checkout/portal routes, narrow Stripe HTTP client, verified webhook route, and idempotent subscription state updates.
   - Validation: HTTP-faked checkout/portal tests, webhook signature/replay tests, failed-payment/cancel/plan-change tests.
4. Account/dashboard/extension visibility:
   - Return ledger-backed account summaries through the extension account endpoint.
   - Show billing plan, status, minutes, reset date, checkout actions, and portal actions in the web dashboard.
   - Update extension copy for billing denial codes.
   - Validation: contract checks, web dashboard tests, and extension helper tests.
5. Support and reporting:
   - Add a support adjustment command with required notes.
   - Add a margin report command comparing used public minutes with provider-cost telemetry.
   - Validation: command tests and documented examples.

## Progress Log

- 2026-05-22: Loaded harness, Laravel, security, subtitle-pipeline, and current Laravel/Stripe docs. Baseline `.\scripts\agent\check.ps1` passed before edits.
- 2026-05-22: Added billing config, Cashier-style user subscription fields, Stripe webhook event storage, and append-only usage ledger events.
- 2026-05-22: Added plan/entitlement services, stable billing denial codes, generation reservation/debit/refund hooks, dashboard billing actions, Stripe webhook handling, support adjustment command, and margin report command.
- 2026-05-22: Updated contracts, extension billing-denial copy, architecture/security/reliability/observability/frontend/database docs, and debt tracker `TD-011` for live Stripe test-mode evidence.
- 2026-05-22: Ran a code-simplifier pass on the merged billing branch, tightened billing config validation, made webhook processing failures retry-visible, removed read-side grant mutation from account summaries, and collapsed duplicated subtitle failure cleanup.
- 2026-05-22: User confirmed Phase 04 complete. Archived this plan from active to completed; live Stripe test-mode proof remains tracked as `TD-011` release debt.

## Validation Evidence

- 2026-05-22: `.\scripts\agent\check.ps1` passed before edits.
- 2026-05-22: `php artisan test --compact tests\Feature\BillingAndUsageTest.php` passed, 9 tests / 51 assertions.
- 2026-05-22: `php artisan test --compact tests\Feature\SubtitleJobApiTest.php` passed, 53 tests / 404 assertions.
- 2026-05-22: `php artisan test --compact tests\Feature\WebAuthTest.php tests\Feature\ExtensionAuthApiTest.php` passed, 14 tests / 70 assertions.
- 2026-05-22: `npm run check` in `packages/contracts` passed and regenerated TypeScript contract types.
- 2026-05-22: `npm test` and `npm run compile` in `app/extension` passed.
- 2026-05-22: `npm run build` in `app/extension` passed.
- 2026-05-22: `vendor/bin/pint --dirty --format agent` passed after formatting fixes.
- 2026-05-22: `php artisan test --compact` passed, 170 tests / 916 assertions.
- 2026-05-22: `.\scripts\agent\doc-gardening.ps1` passed with no findings.
- 2026-05-22: `.\scripts\agent\check.ps1` passed after implementation.
- 2026-05-22: `.\scripts\agent\verify-pr.ps1` passed after implementation.
- 2026-05-22: `php artisan test --compact tests\Feature\BillingAndUsageTest.php` passed after simplification, 10 tests / 55 assertions.
- 2026-05-22: `php artisan test --compact tests\Feature\SubtitleJobApiTest.php` passed after simplification, 53 tests / 404 assertions.
- 2026-05-22: `.\scripts\agent\check.ps1` passed after simplification, including backend 171 tests / 920 assertions and extension 56 tests.
- 2026-05-22: `.\scripts\agent\verify-pr.ps1` passed after simplification on a standalone rerun.

## Completion Notes

- Implemented the local billing system without changing Composer dependencies. Stripe interactions use hosted Checkout and Billing Portal through a narrow Laravel HTTP client.
- Implemented signed webhook verification, idempotent webhook replay handling, subscription state updates, monthly grants, plan-change delta grants, failed-payment and cancellation state updates.
- Implemented current-period minute reservations, debit on completed track, refund/release on failure or duration reduction, no double-charge on cached compatible tracks, feature/concurrency/minute denial codes, and extension-safe copy.
- Added dashboard visibility for subscription and usage, support usage adjustments, and a JSON margin report that compares public used minutes with provider-cost telemetry.
- Simplification follow-up removed fake billing-plan defaults, prevented read-side account summary grant mutation, and made handled Stripe webhook failures record durable retry-debugging state.
- Residual release debt: live Stripe test-mode checkout and webhook proof was not run in this workspace because test-mode credentials and price IDs are not present. It remains tracked as `TD-011` rather than blocking phase archive.

## Risks and Follow-up Debt

- Generous tiers can become unprofitable if full word-card usage is heavy.
- Stripe webhook ordering and retries can corrupt state without idempotency.
- Minute reservations must handle queued, failed, stale, retried, and reused jobs clearly.
- Pricing may need adjustment after beta cost evidence.

