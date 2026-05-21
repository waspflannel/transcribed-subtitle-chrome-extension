# SaaS Roadmap: Paid Beta To Public Launch

Status: active
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Summary

This roadmap splits the SaaS release work into focused phases so the product can move from the current extension proof to a paid beta, then later to a public launch.

The current product already has a working Laravel subtitle-generation API, Postgres/Redis queue runtime, WXT extension, schema-first contracts, job history, and sanitized runtime traces. The SaaS work should build around that core instead of replacing it.

## Beta Goal

Ship a paid beta for polyglot power users using:

- A Chrome extension for YouTube subtitle generation and language-learning overlay.
- A Laravel SaaS web app for registration, billing, usage, jobs, and account management.
- Minute-credit subscriptions with generous caps.
- Tier-based speed and feature advantages.
- Production hosting, support, and operational evidence good enough for real paying users.

## Public Launch Goal

Move to public launch only after the paid beta proves:

- Reliable generation completion and clear failure handling.
- Acceptable generation wait times by tier.
- Sustainable provider cost per generated minute.
- Low support burden for onboarding, billing, and generation failures.
- Working production operations, monitoring, backups, and release process.
- Clear marketing positioning and conversion evidence.

## Phase Order

| Phase | Document | Primary Outcome | Exit Gate |
| --- | --- | --- | --- |
| 01 | `01-generation-optimization.md` | Measured, faster, tier-aware generation pipeline. | Performance/cost budgets are measured and enforced. |
| 02 | `02-extension-frontend-upgrade.md` | Product-grade extension popup and job visibility. | Popup and overlay states pass visual and functional smoke checks. |
| 03 | `03-accounts-and-extension-auth.md` | User accounts and authenticated extension requests. | Jobs, tracks, and tokens are scoped to authenticated users. |
| 04 | `04-billing-tiers-and-usage.md` | Paid plans, minute credits, and entitlement checks. | Billing and usage gates protect margins before beta traffic. |
| 05 | `05-saas-website-and-seo.md` | Laravel web dashboard and SEO-ready marketing site. | Users can sign up, buy, manage, and understand the product. |
| 06 | `06-production-hosting-and-ops.md` | Production environment and deployment operations. | App, workers, scheduler, logs, backups, and alerts are production-ready. |
| 07 | `07-beta-launch-and-support.md` | Paid beta launch process, support, and evidence loop. | Paying beta users can onboard and receive support safely. |
| 08 | `08-marketing-and-growth.md` | Acquisition, positioning, analytics, and retention loops. | Growth experiments are measurable and tied to product funnels. |
| 09 | `09-public-launch-after-beta.md` | Public-launch criteria and post-beta hardening. | Public launch is blocked until beta exit metrics are met. |

## Shared Assumptions

- Paid beta comes before public launch.
- Each phase should be completed and validated before moving to the next.
- The roadmap targets polyglot power users first, not schools or teams.
- The SaaS web app should live inside the existing Laravel backend unless a future plan proves a separate frontend is necessary.
- Extension login will use email/password in the popup, implemented through scoped backend-issued API tokens.
- Public pricing should use generated video minutes; internal telemetry may track provider tokens, model, and cost for margin analysis.
- Higher tiers should receive faster queues, higher concurrency, more monthly minutes, and stronger feature access.
- Production should start with a managed Laravel VPS-style deployment, managed Postgres, managed Redis, supervised workers, and scheduled cleanup.
- Provider secrets, prompts, full transcripts, raw audio paths, raw provider payloads, install IDs, and token payload dumps must not be exposed in public UI or logs.

## Operating Rules

- Keep each phase narrow and finishable.
- Update the relevant phase document before implementing that phase.
- Use the existing harness: `AGENTS.md`, `ARCHITECTURE.md`, `docs/`, `packages/contracts`, Laravel tests, WXT tests, and agent scripts.
- Add or update canonical contracts when extension-facing API shapes change.
- Keep durable decisions in docs, not only in chat.
- Do not add speculative providers, platforms, team accounts, subtitle editing, or vocabulary review until a phase explicitly accepts that scope.

## Cross-Phase Validation

Every implementation phase should run:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Docs-only roadmap changes should at minimum run:

```powershell
.\scripts\agent\lint-docs.ps1
.\scripts\agent\doc-gardening.ps1
```

