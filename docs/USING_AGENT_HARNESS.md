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
