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

## Current Phase Backlog

The current implementation split is tracked in `docs/exec-plans/active/00-phase-index.md`.

Phase status:

- Phase 01: Project Scaffold And Contracts, completed in `docs/exec-plans/completed/phase-01-project-scaffold-and-contracts.md`
- Phase 02: YouTube Extension Shell
- Phase 03: Laravel Job API And Persistence
- Phase 04: Audio Acquisition And Transcription Proof
- Phase 05: Generated Track And Overlay Sync
- Phase 06: Translation And Arabic Learning Data
- Phase 07: Hardening And Release Readiness
