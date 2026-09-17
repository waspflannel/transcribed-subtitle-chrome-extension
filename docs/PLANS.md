# Plans

Use execution plans for work that crosses files, changes architecture, alters user-facing behavior, or may outlive one agent run.

## Plan Lifecycle

- Create active plans in `docs/exec-plans/active/`.
- Move completed plans to `docs/exec-plans/completed/`.
- Update `docs/exec-plans/tech-debt-tracker.md` when follow-up work remains.
- Keep progress, decisions, validation evidence, and completion notes in the plan.

## Create A Plan

```powershell
.\scripts\agent\new-plan.ps1 -Title "Short descriptive title"
```

## Required Sections

Use `docs/exec-plans/templates/exec-plan-template.md`.

## Work Modes

The user can select **Brain / Worker** by saying "use brain / worker" when starting or continuing a plan. Follow the [runbook](work-modes/brain-worker.md) and record the mode in the plan. Keep its ordered chunk backlog, current packet, ownership, review evidence, and acceptance state in the owning plan or linked chunk notes. Only one top-level chunk may be active; completing a chunk does not authorize starting the next.

## Current Work

- [International SEO for website and extension](exec-plans/active/2026-09-16-optimize-international-seo-for-website-and-extension.md): launch basics verified; broader content, store, and acquisition work deferred until after launch.
- [Whole-project audit follow-up by topic](exec-plans/active/2026-09-09-whole-project-review/00-index.md): accounts, pipeline, learning, and operations.
- [Current delivery and testing record](exec-plans/active/2026-09-09-whole-project-review/delivery-and-testing.md): awaiting user acceptance; the pipeline document retains the current slowdown investigation.
- [Technical debt](exec-plans/tech-debt-tracker.md): remaining evidence and deferred requirements.

## Documentation Retention

The 2026-09-09 cleanup removed old execution plans and temporary handoff/review notes at the user's request. Their history remains in Git; historical evidence links use revision `5f3a92b7347072471b59bb2b956e23559ada1e6f`. Locally, use `git show <revision>:<path>` to read a removed file.

Keep the current audit folder's four topic documents, combined delivery/testing record and index, along with architecture/design documents, product specs, standards, operations guidance, reusable prompts, templates and debt tracker. Update the existing topic or delivery record instead of creating overlapping follow-up files. Before pruning future completed plans, keep lasting decisions in the relevant current docs and outstanding requirements in the debt tracker. Deleting a plan does not mark its unfinished work complete.
