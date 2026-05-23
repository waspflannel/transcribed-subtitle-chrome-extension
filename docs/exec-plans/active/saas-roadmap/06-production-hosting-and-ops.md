# Phase 06: Production Hosting And Ops

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

## Goal

Create a production environment that can safely run a paid beta: web app, API, queue workers, scheduler, database, Redis, backups, logs, alerts, and repeatable releases.

The first production posture should be a managed Laravel VPS-style setup with managed Postgres and Redis, not a large cloud platform unless beta evidence requires it.

## Scope

- In scope:
  - Production environment design and deployment scripts/runbook.
  - Managed Postgres, managed Redis, supervised Laravel workers, scheduler, HTTPS, and secrets.
  - CI/CD checks, migrations, rollback, backups, and monitoring.
  - Extension production API config and Chrome package release flow.
  - Operational dashboards and alerts for generation failures and queue health.
- Out of scope:
  - Kubernetes, multi-region deployment, autoscaling platform work, or AWS-style architecture.
  - Enterprise compliance certification.
  - Full incident-management program beyond beta needs.

## Acceptance Criteria

- [ ] Production environment runs Laravel web/API, supervised subtitle generation and AI batch workers, scheduler, Postgres, Redis, and HTTPS.
- [ ] `APP_DEBUG=false`, secrets are environment-only, and provider keys never enter extension builds.
- [ ] Deploy process runs tests, builds, migrations, cache optimization, health checks, and worker restart safely.
- [ ] Queue worker heartbeat, failed jobs, slow stages, high queue wait, provider rate limits, and disk/temp cleanup are observable.
- [ ] Database backups and restore test exist.
- [ ] Scheduled pruning runs and is monitored.
- [ ] Chrome extension production build points to the production API host and has correct host permissions.
- [ ] Rollback process is documented and tested at least once in staging.

## Key Implementation Areas

- Infrastructure:
  - Managed VPS or Laravel-oriented host for web/API.
  - Managed Postgres and Redis, with network restrictions where possible.
  - Separate staging and production env files/secrets.
- Workers and scheduler:
  - Supervised queue workers for the configured generation-priority, batch-priority, base-generation-guarantee, and base-batch-guarantee queue groups.
  - Scheduler for cleanup and recurring operations.
  - Clear worker timeout and retry-after configuration.
- CI/CD:
  - Contracts, backend tests, extension tests, TypeScript compile, WXT build, doc lint, and deployment smoke.
  - Migration and rollback runbook.
- Observability:
  - Log collection, alerting, runtime trace inspection, slow job review, and provider error summaries.
  - Dashboards for queue depth, job outcomes, generation timing, usage, and cost.
- Extension release:
  - Production API base URL, extension manifest permissions, ZIP build, versioning, and Chrome Web Store checklist.

## Required Product Decisions

- Hosting provider and region.
- Managed database/Redis vendor.
- Logging and alerting provider.
- Deployment strategy: manual approved deploy, push-to-main deploy, or tagged release.
- Staging domain and production domain.
- Data retention and backup retention windows.

## Validation/Evidence Required

- Staging deploy smoke: `/up`, login, checkout test mode, job creation, queue worker processing, trace command, scheduler check.
- Provider-backed public-video generation in staging.
- Backup restore proof.
- CI run evidence.
- Chrome extension production build smoke.
- Security checklist for env, debug mode, CORS/host permissions, provider secrets, logs, and webhooks.

## Risks and Follow-up Debt

- Queue workers are the real product runtime; missing supervision or bad timeouts will make paid jobs hang.
- Provider-backed staging runs need real credentials and can cost money.
- Extension production host permissions must be exact enough for Chrome review but broad enough for the production API.
- Backups without restore proof are not sufficient for paid beta.

