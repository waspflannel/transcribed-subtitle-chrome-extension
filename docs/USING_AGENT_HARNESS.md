# Using The Agent Harness

This project starts with an agent harness: a small set of docs, scripts, and conventions that make future work easier to inspect, plan, validate, review, and resume.

## Mental Model

- `AGENTS.md` is the map.
- `docs/` is the memory.
- `docs/exec-plans/` is the work log.
- `scripts/agent/` is the feedback loop.
- `docs/QUALITY_SCORE.md` is the current health snapshot.
- `docs/quality/golden-principles.md` is where repeated feedback becomes a rule.

Do not put everything in `AGENTS.md`. Keep it short and move detail into focused docs.

## First Run

From the project root:

```powershell
.\scripts\agent\doctor.ps1
.\scripts\agent\check.ps1
```

`doctor.ps1` confirms the harness shape exists. `check.ps1` runs documentation checks and, once an app stack exists, can run stack-specific validation.

## Starting New Work

For small, obvious edits, read the relevant docs and make the change directly.

For larger work, create a plan:

```powershell
.\scripts\agent\new-plan.ps1 -Title "Implement first usable workflow"
```

Then fill the generated plan in `docs/exec-plans/active/`:

- Goal
- Scope and non-goals
- Acceptance criteria
- Relevant files and docs
- Implementation steps
- Validation plan
- Decision log
- Progress log
- Completion notes

The plan is not ceremony. It is durable context for later agents.

## During Implementation

When the user says **"use brain / worker"**, apply the [Brain / Worker runbook](work-modes/brain-worker.md). Record the selected mode in the owning plan. It defines chunk sequencing, worker packets, review, and user acceptance; it stays selected for that plan across sessions until the user changes it.

Use this loop:

1. Read `AGENTS.md`.
2. Read only the task-relevant docs.
3. Update or create an active plan when the work is nontrivial.
4. Implement the smallest end-to-end slice.
5. Add or update validation while changing behavior.
6. Check `docs/quality/golden-principles.md` for repeated readability and simplicity rules.
7. Run `.\scripts\agent\check.ps1`.
8. Record validation evidence in the plan.
9. Update docs if behavior, architecture, contracts, or workflow changed.

Keep decisions in the repo, not in chat history.

## What To Put Where

| Need | Location |
| --- | --- |
| Short agent entrypoint | `AGENTS.md` |
| How the harness works | `docs/USING_AGENT_HARNESS.md` |
| System shape and boundaries | `ARCHITECTURE.md` |
| Product intent and specs | `docs/product-specs/` |
| Active implementation plans | `docs/exec-plans/active/` |
| Completed implementation plans | `docs/exec-plans/completed/` |
| Deferred cleanup | `docs/exec-plans/tech-debt-tracker.md` |
| Current quality snapshot | `docs/QUALITY_SCORE.md` |
| Repeated review feedback | `docs/quality/golden-principles.md` |
| Generated schemas and references | `docs/generated/` |
| Long external notes | `docs/references/` |
| Agent commands | `scripts/agent/` |
| Application code | `app/` |

## Validation Commands

Use these commands as the default workflow:

```powershell
.\scripts\agent\doctor.ps1
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Add stack-specific checks to `scripts/agent/check.ps1` as soon as the app stack exists. Good targets include tests, lint, build, typecheck, app startup, browser smoke tests, screenshots, logs, metrics, and traces.

## Review Readiness

Before review, run:

```powershell
.\scripts\agent\verify-pr.ps1
```

The PR or handoff should include:

- What changed.
- Why it changed.
- Validation commands and results.
- Screenshots, videos, logs, metrics, or traces when relevant.
- Known limitations.
- Follow-up debt added to `docs/exec-plans/tech-debt-tracker.md`.

## Doc Gardening

Run periodically:

```powershell
.\scripts\agent\doc-gardening.ps1
```

This surfaces placeholders, stale quality areas, and other cleanup signals. Treat the output as a queue for focused cleanup plans, not as a failure by itself.

## Growing The Harness

When an agent struggles, ask what durable support would prevent the same problem next time.

Repeated simplification feedback belongs in `docs/quality/golden-principles.md` first. Promote it to a script, lint, test, or template only when a doc rule is not enough.

Good upgrades:

- Add a script for repeated manual commands.
- Add a lint for repeated review comments.
- Add a test for repeated regressions.
- Add a plan template section for repeated missing context.
- Add generated docs for schemas, routes, API contracts, or runtime state.
- Add browser or observability checks for workflows agents need to inspect.

## Completion

When work is complete:

1. Run the relevant validation commands.
2. Record validation evidence in the active plan.
3. Move finished plans from `docs/exec-plans/active/` to `docs/exec-plans/completed/`.
4. Update `docs/QUALITY_SCORE.md` when the work changes project health.
5. Update `docs/exec-plans/tech-debt-tracker.md` for remaining cleanup.

## Test service isolation

The standard backend test commands bootstrap an in-memory SQLite database, array cache/session/mail, sync queues, temporary private/public storage and independent config/route/event cache paths before Laravel starts. Tests load an empty environment file in that temporary storage, so local `.env` settings cannot hide missing fixtures or supply runtime credentials. Loaded configuration is checked before providers and migration traits. Inherited host credentials and cached configuration cannot select the test database. `composer test` no longer clears shared configuration. Focused external-service tests require explicit disposable loopback services and their documented opt-in environment variables; normal tests never discover or reuse local databases.

For the checkout, password-reset/login and paid-generation cancellation concurrency suites, start a new disposable PostgreSQL instance and set `SUBTITLE_DISPOSABLE_PG_PORT`, `SUBTITLE_DISPOSABLE_PG_DATABASE`, `SUBTITLE_DISPOSABLE_PG_USERNAME` and `SUBTITLE_DISPOSABLE_PG_PASSWORD`. The database name must match `subtitle_review_test_[a-z0-9]+`; each test creates, migrates and drops only its random schema. For shared provider permits and queue counts, start a separate disposable Redis instance and set `SUBTITLE_TEST_REDIS_PORT`; keys use a random test prefix. The suites force loopback, and none accepts a runtime connection URL. Run the root harness with these variables to include all eleven service-dependent tests; without them the suites explicitly skip. Stop the disposable services after validation. Do not use an existing application database or Redis instance.

## Continuous integration and release checks

`.github/workflows/check.yml` runs the root harness on Ubuntu with PHP 8.4, Node 22 and fresh PostgreSQL/Redis services for pull requests and pushes to main or Codex branches. Actions are pinned, permissions are read-only, and no application secrets are required. Dependency audits and a fixture-origin release ZIP check follow the harness. The fixture ZIP is not uploaded or published and is not a release for users.

The root harness includes `scripts/ops/tests/release-checks.ps1`. It checks URL and ZIP rejection cases using temporary archives inside the ignored extension output directory and deletes those fixtures afterward. Use `scripts/ops/build-extension-release.ps1 -ApiBaseUrl https://<actual-host>/v1` only once the deployment origin is known. Hosted billing/provider/browser acceptance remains separate from CI.
