# Plan: Backend smoothening audit fixes

Status: active
Owner: agent
Created: 2026-09-08
Last updated: 2026-09-08

## Goal

Implement R16, R17, R18, R19, R20 and U2 in separate commits in the isolated backend worktree, preserving the foundation at 743fe96.

## Scope

- In scope: backend run/credit integrity, publication recovery, admission, history and honest minute labels; finding-local audit updates and regression source.
- Out of scope: G1, auth/password/verification, other extension work, dependencies, test execution, builds, browsers, migrations and production/paid actions.

## Acceptance Criteria

- [ ] Each assigned finding has one implementation commit and an implemented UNTESTED audit section.
- [ ] Worktree is clean and only metadata/docs checks were executed.

## Relevant Context

- Product docs: `docs/ux-audit-smoothening-2026-09-06.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/RELIABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: shared audit plan is intentionally untouched.
- Known risks: actual Postgres locking, Redis ambiguity and filesystem crash cleanup remain untested.

## Implementation Steps

- [ ] Inspect current state.
- [ ] Confirm or refine acceptance criteria.
- [ ] Implement the smallest end-to-end slice.
- [ ] Add or update validation.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Update docs and quality score if needed.
- [ ] Run validation and record evidence.
- [ ] Complete review notes.

## Validation Plan

Commands:

```powershell
git diff --check
.\scripts\agent\check.ps1 -SkipAppChecks
```

Evidence to capture:

- Tests: source only; execution expressly prohibited.
- Screenshots or video: none; browser runs prohibited.
- Logs: metadata checks only.
- Metrics or traces: no runtime claims.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-08 | Account before job locks, immutable run cleanup, existing ledger settlement. | Avoid deadlocks and replacement-run credit loss. |
| 2026-09-08 | Boost routing and backend instructions read; local skill files absent and boost:list-skills cannot load vendor/autoload.php. | No dependencies installed; consulted Laravel 13 pessimistic-locking documentation instead. Ponytail loaded; no extra infrastructure. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-08 | Plan created. |  |

## Completion Notes

- What changed:
- Validation results:
- Simplicity/readability review:
- Residual risk:
- Follow-up debt:

