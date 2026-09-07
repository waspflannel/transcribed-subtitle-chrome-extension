# Plan: Resolve smoothening UX audit

Status: active
Owner: agent
Created: 2026-09-07
Last updated: 2026-09-07

## Goal

Resolve every non-password finding in `docs/ux-audit-smoothening-2026-09-06.md`, one finding per commit on `audit/smoothening-fixes`. First establish a clean commit foundation from the existing work; do not start remaining findings during this foundation task.

## Scope

- Worktree: `C:\Users\jaden\AppData\Local\Temp\opencode\smoothening-ux-audit` only. Never read/edit the concurrent lyrics checkout as a shortcut.
- Included: G1-G4, non-password G5, R1-R23, U1-U3, U4 connection expiry only, U5. Grouped ordering below does not permit mixed-finding commits.
- Excluded by user: R24; all password recovery, password-rule/hint, and email-verification portions of G5/U4. Withdraw our register/reset minlength/hints/tests. Preserve pre-existing auth/password enforcement and security. No reintroduction.
- No browser until all batches and explicit testing authorization; no production/paid calls, dependency upgrades, shared services, real-data migrations, pushes, or coauthors.

## Acceptance Criteria

- [x] Documentation baseline committed separately; prior observations/worktree evidence clearly labeled.
- [ ] Existing G3, G4, G2, G5 non-password, U4 expiry each have separate implementation/test/doc commits.
- [ ] Foundation ends with clean status and unchanged password/auth implementation relative to baseline.
- [ ] Every remaining included finding gets a narrow fix, regression checks, audit What Changed/How to Test section, and individual commit.
- [ ] Preserve native mechanisms: G2 navigation-only and G3 manual GET refresh resolve their allowed minimal acceptance; do not add retry protocols or polling to close them again.
- [ ] Full harness components checked individually; limitations and exclusions never presented as runtime evidence.

## Relevant Context

- Product docs: audit report and its Completed Tasks And Testing sections.
- Architecture docs: root/backend AGENTS, ARCHITECTURE, FRONTEND, RELIABILITY, SECURITY, Boost skill routing.
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: completed G3/G4 and G2/G5 logs preserve pre-commit worktree evidence, not per-commit test results.
- Known risks: R16-R19 require isolated concurrency evidence; passing SQLite tests cannot establish Postgres locking. Worker delegation unavailable in foundation session.

## Implementation Steps

- [x] Verify worktree/status/diff/log and create a new branch, without switching an existing unexpected branch.
- [x] Withdraw remaining uncommitted password hints/tests using apply_patch only.
- [ ] Commit docs baseline, then G3, G4, G2, G5 non-password, U4 expiry using noninteractive index patches.
- [ ] Validate final foundation, review each staged diff, record commit mapping, stop for parallel batch coordination.

## Remaining Batch Order

| Order | Findings | Shared cause / boundary |
| --- | --- | --- |
| 1 | R1, R21 | Startup ordering and complete response deadlines. |
| 2 | U1, U2 | Honest queued-state and estimated/charged usage labels. |
| 3 | R2, R3, R9, R12 | Exact-operation synchronization, recoverable polling, preference/submission ordering, action feedback (no password-flow changes). |
| 4 | R8, R15, U3 | Account-owned cached/pending state, documented local reset, same-session stale history. |
| 5 | R4, R5, R6, R7, R11 | Content/tab/player lifecycle, stale responses, scoped cue/seek relay. |
| 6 | R10, R13, R14, R22, R23 | Track content/enrichment, study focus/pause, attachment recovery, timing/editability/layout. |
| 7 | R16, R17, R18, R19, then G1 | Run/credit integrity, queue publication/admission, then explicit cancellation. |
| 8 | R20 | Owned operational history independent of track compatibility. |
| 9 | U5 | Read-only partial Watch results after preservation/lifecycle fixes. |

Dependencies, not urgency alone, govern ordering. Parallel workers must own disjoint files or coordinate shared entrypoints; inspect and preserve concurrent work. Each finding still gets its own commit and testing section. No implementation beyond foundation in this turn.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: focused backend website/deletion suites, extension history/account/API suites, then full root harness.
- Screenshots or video: deferred; record setup, steps, expected outcomes per completed finding for later testing.
- Index isolation: inspect each cached diff for foreign finding hunks; whole-worktree tests validate the combined foundation, not pretend to test historical commit trees.
- Formatting: Pint; git diff --check. Never stage test-only .env, caches, dependencies, or generated no-op files.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-07 | Created audit/smoothening-fixes from smoothening 4969225. | Target branch did not exist. Original worktree remains on lyrics branch and is not modified. |
| 2026-09-07 | Broader password exclusion supersedes earlier hint retention. | Remove only our additions; R24 and password/verification G5/U4 portions excluded. |
| 2026-09-07 | Documentation-only checkpoint retains historical uncommitted evidence. | Implementation is split into following per-finding commits, not silently bundled with the audit. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-07 | Plan created by repository script; full existing diff reviewed. | No staged user work at entry. All known edits belong to prior audit batches. |
| 2026-09-07 | Documentation checkpoint committed. | `31a91f1`; no application code included. |
| 2026-09-07 | G3 isolated into refresh-only view/test hunks. | Focused G3: 2 tests/29 assertions passed; no G4/U4 hunks staged. Resolved by allowed manual GET refresh, browser validation deferred. |
| 2026-09-07 | G3 committed as `3f3bea5`; G4 staged separately. | G4-focused validation: 17 tests/94 assertions passed. Controller count/video data exclude U4 expiry filtering; existing deletion behavior untouched. |

## Completion Notes

- Foundation mapping: pending individual commits below.
- Validation results: pending final foundation check.
- Simplicity/readability review: stage by finding, not by file; keep existing runtime behavior and avoid speculative replacements.
- Residual risk: remaining findings are unimplemented, browser validation deferred, password scope explicitly excluded.
- Follow-up: stop after foundation; user will coordinate parallel next batches.
