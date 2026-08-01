# Phase 06: Production Hosting And Ops

Status: active - repository artifacts implemented; external staging evidence pending
Owner: agent
Created: 2026-05-20
Last updated: 2026-06-02

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

## Plan Review Notes

- Current runtime already has Postgres, Redis queue, worker groups, runtime checks, trace inspection, slow-event inspection, metrics, and scheduled pruning commands. Phase 06 should make those production-operable rather than replacing the runtime path.
- Live provisioning cannot be completed inside the repository without the required hosting provider, domains, database/Redis vendor, alerting provider, and credentials. Repository deliverables should therefore be provider-neutral scripts, templates, checks, and runbooks that become executable once those decisions are supplied.
- Laravel 13 deployment docs confirm the production deploy primitives used by this phase: `php artisan migrate --force`, `php artisan optimize`, Supervisor-managed `queue:work`, and `php artisan queue:restart`.
- WXT docs confirm using env-aware manifest function configuration for API host permissions and `wxt zip` for Chrome Web Store packaging.
- Laravel Boost skills loaded for this phase: `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, and `laravel-security`. Generic recommendations for Horizon, Kubernetes, or large cloud architecture are intentionally skipped because this phase calls for a managed Laravel VPS-style beta posture.

## Refined Implementation Slices

1. Production safety checks:
   - Add a backend production readiness command that verifies app debug posture, HTTPS URL, key presence, Postgres/Redis queue profile, worker timeout/retry-after relationship, billing test switcher posture, provider/Stripe config presence, and logging posture without printing secret values.
   - Defer live host connectivity and secret manager verification until the staging environment exists.
   - Validation: PHPUnit command tests and harness check.
2. Extension production packaging:
   - Make the extension backend API base URL and manifest backend host permission derive from `WXT_BACKEND_API_BASE_URL`, while keeping localhost defaults for local builds.
   - Add a production release script that requires an HTTPS API base URL, runs tests/compile/build/zip, and verifies the built manifest permission matches the configured API origin.
   - Validation: Vitest coverage for URL normalization/host permission derivation and WXT build.
3. Managed Laravel ops scripts:
   - Add deploy, readiness, Supervisor config rendering, Postgres backup/restore-test, and extension release scripts under `scripts/runtime`.
   - Defer actually running destructive restore against staging until a staging database exists.
   - Validation: static doc lint plus non-destructive script inspection through repo checks.
4. Production runbook and harness memory:
   - Document staging/production environment shape, worker supervision, deploy/rollback, backups, monitoring dashboards/alerts, smoke checks, extension release, and security checklist.
   - Update architecture/reliability/security/observability/quality memory where the phase changes durable operational expectations.
   - Validation: doc gardening, `check.ps1`, and `verify-pr.ps1`.

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

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-02 | Activated Phase 06, loaded `phased-implementation-v2`, relevant harness docs, Laravel security/implementation skills, and current Laravel 13/WXT docs. | `php artisan boost:list-skills`; Context7 `/laravel/docs/__branch__13.x`; Context7 `/websites/wxt_dev`. |
| 2026-06-02 | Baseline validation passed before edits. | `.\scripts\agent\check.ps1` passed: docs lint, contracts, Laravel 186 tests / 1076 assertions, extension 58 tests, TypeScript compile, WXT build. |
| 2026-06-02 | Implemented backend production readiness checks and tests without printing secret values. | `php artisan test --compact tests\Feature\ProductionReadinessTest.php` passed: 2 tests / 13 assertions. |
| 2026-06-02 | Implemented env-driven extension API base URL and manifest host permission derivation. | `npm test -- --run tests/api-config.test.ts tests/api.test.ts`, `npm run compile`, and `npm run build` passed. |
| 2026-06-02 | Added provider-neutral runtime scripts for readiness, deploy, Supervisor config rendering, Postgres backup/restore-test, and extension Chrome release packaging. | PowerShell parser passed for all new runtime scripts; Supervisor config rendering smoke wrote expected `tse-*` worker groups. |
| 2026-06-02 | Added production operations runbook and durable harness memory updates for architecture, reliability, security, observability, quality score, and technical debt. | `.\scripts\agent\lint-docs.ps1` passed. |
| 2026-06-02 | Ran final repository validation and PR readiness checks. | `vendor\bin\pint --dirty --format agent`; `.\scripts\agent\check.ps1`; `.\scripts\agent\doc-gardening.ps1`; `.\scripts\agent\verify-pr.ps1`. |

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-02 | Use provider-neutral managed VPS/Laravel ops artifacts instead of committing a cloud-provider-specific stack. | The phase explicitly avoids Kubernetes, multi-region, autoscaling platform work, and AWS-style architecture unless beta evidence requires it. |
| 2026-06-02 | Keep live staging/prod deploy, rollback, provider-backed generation, and backup restore evidence open until hosting, domains, alerting, and credentials are chosen. | The repository cannot safely invent provider/domain/credential decisions or run paid provider proofs without operator input. |

## Validation Evidence Captured

- 2026-06-02: Baseline `.\scripts\agent\check.ps1` passed before edits.
- 2026-06-02: `php artisan test --compact tests\Feature\ProductionReadinessTest.php` passed, 2 tests / 13 assertions.
- 2026-06-02: `npm test -- --run tests/api-config.test.ts tests/api.test.ts` passed, 12 tests.
- 2026-06-02: `npm run compile` in `app/extension` passed.
- 2026-06-02: `npm run build` in `app/extension` passed.
- 2026-06-02: PowerShell parser passed for all new runtime scripts.
- 2026-06-02: `.\scripts\runtime\render-supervisor-config.ps1` rendered `tse-generation-priority`, `tse-batch-priority`, `tse-base-generation-guarantee`, and `tse-base-batch-guarantee` Supervisor programs from current Laravel queue config.
- 2026-06-02: `.\scripts\runtime\build-extension-release.ps1 -ApiBaseUrl "https://api.example.test/v1" -SkipTests` built and zipped a Chrome extension artifact, verified `https://api.example.test/*` host permission, and rejected localhost backend permission.
- 2026-06-02: `vendor\bin\pint --dirty --format agent` passed.
- 2026-06-02: `.\scripts\agent\check.ps1` passed after implementation: docs lint, contracts, Laravel 188 tests / 1089 assertions, extension 62 tests, TypeScript compile, WXT build.
- 2026-06-02: `.\scripts\agent\doc-gardening.ps1` passed with no findings.
- 2026-06-02: `.\scripts\agent\verify-pr.ps1` passed.

## Current Completion Notes

- What changed: added a backend production readiness command, command tests, env-driven WXT backend API config and manifest host permission, extension config tests, production runtime scripts, extension release script, production runbook, and durable harness documentation updates.
- Validation results: all repository-level checks pass, including final `verify-pr.ps1`.
- Simplicity/readability review: kept the implementation provider-neutral and Laravel/VPS-oriented; skipped Kubernetes, Horizon, cloud-specific infrastructure, and live provisioning because this phase explicitly starts with managed VPS-style beta operations.
- Residual risk: live staging/prod deploy, rollback proof, provider-backed public-video generation, alert wiring, and backup restore proof remain unproven until operator decisions and credentials exist.
- Follow-up debt: `TD-012` tracks remaining production ops evidence.

## Next External Steps

- Choose hosting, region, managed Postgres/Redis, logging/alerting provider, staging domain, and production domain.
- Configure staging secrets and run `.\scripts\runtime\check-production-readiness.ps1 -Target staging`.
- Deploy staging with `.\scripts\runtime\deploy-managed-laravel.ps1 -Target staging -HealthUrl "https://<staging-host>/up"`.
- Install rendered Supervisor worker config and scheduler cron.
- Run staging rollback, provider-backed public-video generation, backup restore, alert smoke, and extension release build against the real staging/production API host.

## Risks and Follow-up Debt

- Queue workers are the real product runtime; missing supervision or bad timeouts will make paid jobs hang.
- Provider-backed staging runs need real credentials and can cost money.
- Extension production host permissions must be exact enough for Chrome review but broad enough for the production API.
- Backups without restore proof are not sufficient for paid beta.
