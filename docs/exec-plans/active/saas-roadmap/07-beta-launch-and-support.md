# Phase 07: Beta Launch And Support

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Goal

Launch a controlled paid beta with real users, real payments, and a tight feedback loop while keeping operational risk manageable.

The beta should prove reliability, willingness to pay, support burden, cost per generated minute, and tier value before public launch.

## Scope

- In scope:
  - Beta invite/onboarding flow.
  - Support process and admin diagnostics.
  - Beta launch checklist.
  - Manual and automated release evidence.
  - Feedback collection and product metrics.
  - Refund, credit adjustment, and user communication process.
- Out of scope:
  - Public self-serve launch.
  - Teams/schools.
  - Large-scale paid acquisition.
  - Enterprise support tooling.

## Acceptance Criteria

- [ ] Beta users can sign up, pay, install the extension, log in, generate subtitles, view jobs, manage billing, and contact support.
- [ ] Admin/support can inspect a user, subscription, usage ledger, jobs, traces, and failure codes without seeing sensitive generated content.
- [ ] Beta runbook covers onboarding, common failures, refunds/credits, provider outages, queue backlog, and release rollback.
- [ ] Product metrics capture activation, first generation, completed jobs, failed jobs, wait times, minute usage, churn, and support contacts.
- [ ] Public-video acceptance matrix and visual QA screenshots are captured for release candidates.
- [ ] A clear beta feedback channel exists and is linked from web app and extension.
- [ ] Beta limits can be adjusted without code changes where practical.

## Key Implementation Areas

- Onboarding:
  - Invite or manual approval flow.
  - Welcome email or dashboard checklist.
  - Chrome extension install and login instructions.
- Support/admin:
  - Admin user lookup, plan, usage, jobs, trace summaries, and manual credits.
  - Support-safe job detail with public IDs and sanitized failure context.
- Evidence:
  - Release checklist using the existing public-video matrix plus account/billing flows.
  - Screenshots for extension and web app core states.
  - Provider-backed performance and cost reports.
- Feedback:
  - In-app support link, structured feedback form, and issue categories.
  - Weekly beta review notes covering bugs, speed, cost, conversion, and feature requests.

## Required Product Decisions

- Beta invite size and acceptance criteria.
- Support channel and expected response time.
- Refund and credit policy.
- Whether beta users get discounted lifetime pricing, grandfathered plans, or normal pricing.
- Which metrics determine beta success.
- How often beta feedback is reviewed and promoted into plans.

## Validation/Evidence Required

- End-to-end beta dry run with a test user.
- Stripe test-mode and production-mode readiness checks.
- Public-video release matrix with real provider credentials.
- Screenshots for extension and web dashboard states.
- Admin/support workflow smoke.
- Release checklist signed off in the relevant execution plan.

## Risks and Follow-up Debt

- Paid beta users will expose reliability and support gaps faster than local testing.
- Manual support can hide recurring product problems unless issues are tracked and promoted.
- Generous caps can produce high provider bills before pricing is tuned.
- Beta feedback should not derail the phase order without explicit roadmap updates.

