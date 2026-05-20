# Phase 04: Billing Tiers And Usage

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

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

- [ ] Users can start, manage, cancel, and resume subscriptions through hosted Stripe flows.
- [ ] Stripe webhooks update local subscription state idempotently and securely.
- [ ] Subtitle generation checks active subscription, available minutes, concurrency, tier feature gates, and abuse limits before provider work starts.
- [ ] Minute credits are reserved when a job starts, debited when it completes, and refunded or released when it fails before producing a track.
- [ ] Compatible cached/reused tracks do not double-charge minutes.
- [ ] Higher tiers receive faster queue priority, larger minute pools, higher concurrency, and access to premium generation options.
- [ ] Usage and entitlement data are visible in the web dashboard and extension.
- [ ] Tests cover successful checkout state, webhook replay, failed payment, cancellation, plan change, reservation, debit, refund, and denied generation.

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

- Initial beta plan names, monthly prices, monthly minute pools, and overage behavior.
- Whether full word-card mode consumes normal minutes, weighted minutes, or high-tier-only entitlement.
- Whether failed jobs always refund minutes or only refund when no completed track is produced.
- Whether unused minutes roll over during beta.
- How many concurrent jobs each tier gets.
- Whether plan downgrades take effect immediately or at period end.

## Validation/Evidence Required

- Stripe test-mode checkout and webhook run.
- Backend billing and usage tests.
- Extension and dashboard usage display tests.
- Ledger audit examples for completed, failed, reused, adjusted, and plan-changed jobs.
- Margin report comparing provider cost to plan minute usage.
- `.\scripts\agent\check.ps1`
- `.\scripts\agent\verify-pr.ps1`

## Risks and Follow-up Debt

- Generous tiers can become unprofitable if full word-card usage is heavy.
- Stripe webhook ordering and retries can corrupt state without idempotency.
- Minute reservations must handle queued, failed, stale, retried, and reused jobs clearly.
- Pricing may need adjustment after beta cost evidence.

