# transcribed-subtitle-extension Agent Map

This repository is designed for agentic development. Keep this file short; it is a map to the system of record, not the system of record itself.

## Start Here

- Product intent: `docs/product-specs/index.md`
- How to use this harness: `docs/USING_AGENT_HARNESS.md`
- Architecture map: `ARCHITECTURE.md`
- Design principles: `docs/DESIGN.md`
- Frontend expectations: `docs/FRONTEND.md`
- Reliability expectations: `docs/RELIABILITY.md`
- Security expectations: `docs/SECURITY.md`
- Observability expectations: `docs/OBSERVABILITY.md`
- Review expectations: `docs/REVIEW.md`
- Project guardrails: `docs/references/project-guardrails.md`
- Laravel Boost skill routing: `docs/references/boost-skill-routing.md`
- Quality score: `docs/QUALITY_SCORE.md`
- Active plans: `docs/exec-plans/active/`
- Completed plans: `docs/exec-plans/completed/`
- Technical debt: `docs/exec-plans/tech-debt-tracker.md`

## Operating Loop

1. Read the smallest relevant docs before editing.
2. For Laravel backend work, apply `docs/references/boost-skill-routing.md`.
3. For complex work, create an execution plan with `scripts/agent/new-plan.ps1`.
4. Keep scope, assumptions, decisions, and validation evidence in the plan.
5. Make the narrowest change that satisfies the acceptance criteria.
6. Run `scripts/agent/check.ps1` before calling work complete.
7. Update docs when behavior, architecture, contracts, or workflow expectations change.
8. Turn repeated review feedback into a doc, script, lint, test, or template.

## Guardrails

- Do not rely on chat history as project memory. Put durable decisions in the repo.
- Do not add implementation code directly to the scaffold without a plan or explicit user request.
- Prefer boring, inspectable dependencies and project structures.
- Parse and validate data at boundaries.
- Keep logs structured and useful for future debugging.
- Preserve agent legibility: future agents should be able to understand what exists, why it exists, and how to validate it.

## Standard Commands

```powershell
.\scripts\agent\doctor.ps1
.\scripts\agent\check.ps1
.\scripts\agent\new-plan.ps1 -Title "Describe the work"
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```
