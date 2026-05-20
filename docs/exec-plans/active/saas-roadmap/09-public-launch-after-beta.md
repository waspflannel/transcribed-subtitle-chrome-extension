# Phase 09: Public Launch After Beta

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Goal

Define the gate from paid beta to public launch and the hardening work required before the product is open to anyone.

Public launch should be a decision based on beta evidence, not just the completion of feature work.

## Scope

- In scope:
  - Public launch criteria.
  - Beta exit metrics.
  - Reliability, security, support, billing, and marketing hardening.
  - Chrome Web Store readiness.
  - Public onboarding and self-serve support.
- Out of scope:
  - Enterprise/team product line.
  - Non-YouTube platforms.
  - Major AI provider rewrites without beta evidence.
  - New learning product systems such as vocabulary review or quizzes.

## Acceptance Criteria

- [ ] Beta metrics meet launch thresholds for completion rate, wait time, cost, conversion, retention, and support load.
- [ ] Production operations have passed staging and production smoke checks repeatedly.
- [ ] Billing, usage, refunds, plan changes, cancellation, and failed payments are proven with real beta users.
- [ ] Public documentation, support pages, privacy, terms, refund policy, and onboarding are complete.
- [ ] Abuse controls handle unauthenticated traffic, account creation, login attempts, generation requests, and provider quota exhaustion.
- [ ] Chrome Web Store listing, screenshots, privacy practices, and production extension build are ready.
- [ ] Known launch blockers are either fixed or explicitly accepted with owner and mitigation.

## Key Implementation Areas

- Launch gates:
  - Define numeric thresholds for generation success, p95 wait by tier, gross margin, first-generation activation, paid conversion, churn, and support tickets.
  - Use beta evidence to decide whether to adjust tiers or delay launch.
- Reliability:
  - Hardening for provider outages, queue backlog, stuck jobs, slow stages, expired tracks, and payment-related access changes.
  - Automated alerts with actionable runbooks.
- Security and privacy:
  - Review auth, token revocation, billing webhooks, CORS/host permissions, logs, traces, data retention, and admin access.
  - Confirm public UI exposes only safe telemetry.
- Self-serve readiness:
  - Help center, FAQ, onboarding emails, status messaging, and recovery flows for common failures.
- Release process:
  - Public release checklist, rollback, changelog, Chrome Web Store submission, and post-launch monitoring window.

## Required Product Decisions

- Exact beta exit thresholds.
- Whether to increase, reduce, or restructure tier minutes before public launch.
- Public launch date and announcement channels.
- Whether beta users are grandfathered.
- Whether free trial/free tier is offered at public launch.
- Which feature requests remain deferred after beta.

## Validation/Evidence Required

- Beta metrics report.
- Production smoke history.
- Security review notes.
- Billing and usage audit.
- Chrome Web Store package and listing review.
- Support/runbook drill.
- Public launch go/no-go document.

## Risks and Follow-up Debt

- Public launch can multiply costs and support load before tier economics are stable.
- Beta users may tolerate manual workarounds that public users will not.
- Chrome Web Store review can delay launch if permissions or privacy copy are unclear.
- Launch should not include new major features unless they have passed beta-quality validation.

