# Plan: Resolve smoothening UX audit

Status: active
Owner: agent
Created: 2026-09-07
Last updated: 2026-09-08

## Goal

Resolve every non-password finding in `docs/ux-audit-smoothening-2026-09-06.md`, one finding per commit on `audit/smoothening-fixes`. Foundation is committed. Implementation is paused at the user's request for transfer to another agent; see root `handoff.md` for the verified multi-worktree checkpoint.

## Scope

- Main integration worktree: `C:\Users\jaden\AppData\Local\Temp\opencode\smoothening-ux-audit`. Dedicated sibling panel/overlay/backend audit worktrees contain unintegrated worker changes, mapped in `handoff.md`. Never read/edit the concurrent lyrics checkout as a shortcut.
- Included: G1-G4, non-password G5, R1-R23, U1-U3, U4 connection expiry only, U5. Grouped ordering below does not permit mixed-finding commits.
- Excluded by user: R24; all password recovery, password-rule/hint, and email-verification portions of G5/U4. Withdraw our register/reset minlength/hints/tests. Preserve pre-existing auth/password enforcement and security. No reintroduction.
- Latest user instruction (2026-09-08): no test suites or browser tests. Only non-test metadata, diff, and doc checks; mark new changes untested. No production/paid calls, dependency upgrades, shared services, real-data migrations, pushes, or coauthors.

## Acceptance Criteria

- [x] Documentation baseline committed separately; prior observations/worktree evidence clearly labeled.
- [x] Existing G3, G4, G2, G5 non-password, U4 expiry each have separate implementation/test/doc commits (U4 is this final foundation commit).
- [x] Foundation changes fully accounted for; password/auth implementation unchanged relative to baseline. Verify clean status immediately after final commit.
- [ ] Every remaining included finding gets a narrow fix, regression checks, audit What Changed/How to Test section, and individual commit.
- [x] Preserve native mechanisms: G2 navigation-only and G3 manual GET refresh resolve their allowed minimal acceptance; do not add retry protocols or polling to close them again.
- [x] Prior worker's foundation harness results retained as historical reports only. Current handoff is untested; do not repeat suites without user authorization.

## Relevant Context

- Product docs: audit report and its Completed Tasks And Testing sections.
- Architecture docs: root/backend AGENTS, ARCHITECTURE, FRONTEND, RELIABILITY, SECURITY, Boost skill routing.
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: completed G3/G4 and G2/G5 logs preserve pre-commit worktree evidence, not per-commit test results.
- Known risks: R16-R19 require isolated concurrency evidence; passing SQLite tests cannot establish Postgres locking. Worker delegation unavailable in foundation session.

## Implementation Steps

- [x] Verify worktree/status/diff/log and create a new branch, without switching an existing unexpected branch.
- [x] Withdraw remaining uncommitted password hints/tests using apply_patch only.
- [x] Commit docs baseline, then G3, G4, G2, G5 non-password, U4 expiry using noninteractive index patches (U4 is this final foundation commit).
- [x] Validate final foundation, review each staged diff, record commit mapping, stop for parallel batch coordination after verifying final commit/status.

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

Dependencies, not urgency alone, govern ordering. Parallel workers use isolated audit worktrees and must coordinate shared entrypoints during integration. Each finding still gets its own commit and testing section. The table is the original ordering, not current completion evidence; use the checkpoint below and `handoff.md`.

## Validation Plan

Current permitted command (documentation only):

```powershell
.\scripts\agent\check.ps1 -SkipAppChecks
```

Evidence to capture:

- Tests: deferred by latest user instruction, including focused tests and the full root harness. Existing results below are prior-worker reports, not current-session verification.
- Screenshots or video: deferred; record setup, steps, expected outcomes per completed finding for later testing.
- Index isolation: inspect each cached diff for foreign finding hunks; whole-worktree tests validate the combined foundation, not pretend to test historical commit trees.
- Current checks: documentation lint and git diff --check only. Never stage test-only .env, caches, dependencies, or generated no-op files.

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
| 2026-09-07 | G4 committed as `2adf8d6`; G2 isolated next. | History renderer suite: 3 tests passed. Main entrypoint stages only History listener/handler removal, excluding account/billing wiring. G2 resolved by approved navigation-only action. |
| 2026-09-07 | G2 committed as `a80f1c1`; G5 non-password staged. | Account/API suites: 24 tests passed; TypeScript compile passed. No backend password/auth changes; frontend docs reconcile already-committed G2/G3/G4 behavior too. |
| 2026-09-07 | G5 committed as `81f9764`; final U4 expiry commit prepared. | U4: 1 test/9 assertions passed. Pint passed. Full harness: backend 327 tests/2622 assertions, extension 157 tests/26 files; docs/contracts/TypeScript/build all pass. |
| 2026-09-07 | Foundation isolation reviewed. | No diff from 4969225 for auth views, WebAuthTest, routes, or Fortify actions. Temporary index patches removed. No new findings started. |
| 2026-09-08 | Resumed with baseline/G3/G4/G2/G5 already committed and only U4 staged. | Preserved existing commits and U4 code/test hunks. Updated validation instructions only; new handoff changes are untested. No test suites or browser tests run. |

## Completion Notes

### Transfer Checkpoint (2026-09-08)

- User paused implementation and requested `handoff.md`. No new implementation or integration is included in this handoff checkpoint.
- Main branch foundation is complete through `743fe96`, plus the documentation-only handoff commit.
- Panel branch saved R1 `ee9258e`, R21 `0b0e85d`, R2 `6603447`; clean worktree.
- Overlay branch saved R4 `0d214a7`, R6 `e80f732`, R5 `d94ada5`, R10 `0411bb2`; five dirty files contain unfinished R11 cue-sync work. Preserve them.
- Backend branch saved R16 `7f44ae9`, R17 `0d17c64`; clean worktree.
- Those nine worker commits are not integrated into main, not fully coordinator-reviewed, and untested. Failed worker calls did leave real commits and working changes.
- Remaining: R3, R7-R9, R11-R15, R18-R20, R22-R23, U1-U3, U5, then G1 after dependencies. Password exclusions remain unchanged.
- Next agent should follow root `handoff.md` for locations, commit order, shared-file conflicts, manual-test policy and continuation prompt. The plan remains active; the audit is not complete.

### Historical Foundation Notes

- Foundation mapping: baseline `31a91f1`; G3 `3f3bea5`; G4 `2adf8d6`; G2 `a80f1c1`; G5 non-password `81f9764`; U4 expiry = commit titled `fix: omit expired extension connections (U4)` containing this entry (resolve ID with git log, avoiding a self-referential hash).
- Validation results: prior worker reported the focused checks and full harness above passed; not rerun or independently verified in this handoff. Current changes are untested; only doc lint/diff checks precede the U4 commit. Whole-worktree test evidence is not per-commit checkout testing. No browser evidence.
- Simplicity/readability review: stage by finding, not by file; keep existing runtime behavior and avoid speculative replacements.
- Residual risk: remaining findings are unimplemented, browser validation deferred, password scope explicitly excluded.
- Follow-up: foundation complete; remaining table is still pending and this plan stays active. User will coordinate parallel next batches. Next candidates R1/R21; one finding per commit, no password work or extra G2 retry/G3 polling features.
