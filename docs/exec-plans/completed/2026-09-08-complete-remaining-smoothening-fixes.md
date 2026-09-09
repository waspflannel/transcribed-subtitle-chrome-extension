# Plan: Complete remaining smoothening fixes

Status: complete; user manual validation pending
Owner: coordinator with Luna xhigh coding agents
Created: 2026-09-08
Last updated: 2026-09-08

## Goal

Finish all 19 remaining included findings in the smoothening audit after the lyrics merge at `4a25575`. Preserve previously implemented fixes and lyrics editing. Deliver reviewed code and a per-finding manual testing checklist for the user.

## Current Instructions

- Use isolated worktrees; leave `C:/transcribed-subtitle-extension` available for the user's unrelated feature.
- Luna xhigh agents write implementation code. The coordinator assigns bounded work, integrates and reviews it.
- Do not check up on workers. Await their completion reports; do not poll their Git state or request status.
- Useful focused automated code tests are allowed. No browser testing, live service calls, paid generation, real-data migrations, dependency upgrades, pushes or redundant test runs.
- Keep one implementation commit per finding. Review corrections may have follow-up commits.
- Exclude R24 and password/verification portions of G5/U4. Preserve current merged authentication behavior.
- These instructions supersede older no-tests and single-checkout restrictions in the historical audit plans and handoff.

## Worktree Ownership

| Worktree | Branch | Owner and scope |
| --- | --- | --- |
| `C:/transcribed-subtitle-smoothening` | `codex/remaining-smoothening-fixes` | Coordinator integration, plans, review and handoff |
| `C:/transcribed-subtitle-smoothening-panel` | `codex/smoothening-panel` | Luna xhigh: R3, R8, R9, R12, R15, U1, U3, U5 |
| `C:/transcribed-subtitle-smoothening-content` | `codex/smoothening-content` | Luna xhigh: R7, R11, R13, R14, R22, R23 |
| `C:/transcribed-subtitle-smoothening-backend` | `codex/smoothening-backend` | Luna xhigh: R18, R19, R20, U2, G1 backend/contracts |

After these complete, assign G1 extension integration and any cross-branch resolutions to a Luna xhigh agent. Workers must preserve shared-file changes from the other scopes.

## Acceptance And Evidence Map

| ID | Required outcome | Review evidence |
| --- | --- | --- |
| R3 | One transient GET/auth/restart interruption resumes the same job without another POST; partial data survives | Monitor creation/ownership, recovery callers, error guards and regression source |
| R7 | Notices and seeks belong to displayed tab/video/track across windows and duplicate videos | Message schemas, sender relay, panel filters, destination validation |
| R8 | A's late success/error cannot change B's session, history, tracks or pending work | Session identity guards through every async write/clear and persistent caches |
| R9 | Concurrent settings patches accumulate; Generate claims before awaits and uses pending preferences | Shared settings serialization and generation claim lifecycle |
| R11 | Paused reopen and every extension seek immediately update the owning panel cue | Snapshot and broadcast identities, stale result rejection |
| R12 | Account outcomes/busy state survive normal refresh; action failure preserves valid view | Action sequencing, local errors, render ownership |
| R13 | Stable word focus, overlapping study pause ownership, held-cue lifetime and silence feedback | Overlay focus keys/events, teardown, cue timer and status rendering |
| R14 | Partial layers reveal independently; attachment failures retry existing track | Partial markup/styles, binding errors/retry and success reset |
| R15 | Full local reset clears login/settings/history/tracks and invalidates old requests | Reset handler, storage and recovery guards; backend data retained |
| R18 | Publication failures release matching reservation/slot and leave retryable outcome | Both dispatch sites, failure handling and run-scoped settlement |
| R19 | Locked duplicate reuse; submission-time FIFO; fresh heartbeat prevents stale timeout | Lock order, compatible lookup, ordering and timeout claim |
| R20 | Old/old-version active work and recent terminal outcomes stay owned/discoverable | API/dashboard/detail queries; separate track compatibility |
| R22 | Negative inverse time yields no early cue; visible player wins | Timing functions and player selection predicates |
| R23 | Valid editable surfaces bypass shortcuts; cards keep close controls reachable | Native editability and composed path; flip/clamp/scroll constraints |
| U1 | Queue says waiting until admitted, no invented ETA | Job status propagated into Watch view model |
| U2 | Unknown estimate distinguished from reserved/charged/released minutes | Ledger-derived detail fields and labels |
| U3 | Same-session history survives refresh failure with stale indication | Cache ownership and error rendering |
| U5 | Partial Watch transcript is read-only and preserves reading continuity | Partial data path, actions disabled, revision/final transition |
| G1 | Watch/History cancel queued/running owned generation safely and honestly | Backend contract, UI targeting, run guards, settlement/admission and terminal behavior |

## Completion Gates

- All workers completed without progress check-ins.
- All 19 findings have concrete implementation and per-item manual steps.
- Integration preserves earlier R1/R2/R4/R5/R6/R10/R16/R17/R21 and lyrics behavior.
- Code review and Ponytail review performed after implementation; actionable findings resolved by coding agents.
- Useful automated checks recorded with their real scope; no browser validation claimed.
- Final branch clean and local; original user checkout untouched.

## Progress

- Created integration and three coding worktrees from `4a25575`.
- Dispatched three independent Luna xhigh coding assignments with exact finding ownership and explicit exclusions.
- Read every remaining acceptance criterion and prepared this review map while workers run.
- Integrated backend fixes and their focused regression corrections, then merged the content and panel branches. Luna completed extension cancellation at `3791353`.
- Initial integrated checks: contracts passed; extension compile/build passed; extension suite had 204 passes and one expired-date fixture failure. Backend focused failures were corrected in `deea02e` and the affected suites passed. These are intermediate checks, not final validation.
- Coordinator code and Ponytail reviews found remaining session/cancellation races, asynchronous pause ownership defects, partial transcript controls without behavior, legacy identity fallbacks, and gaps in dispatch-site regression coverage.
- Assigned bounded correction batches on `codex/smoothening-panel-review`, `codex/smoothening-content-review`, and `codex/smoothening-backend-review`, each based on `3791353` in its existing isolated worktree. Workers retain their original file boundaries and Luna xhigh settings. No progress inspections.

## Validation And Review

All 19 included fixes are implemented, integrated and reviewed at `69c883b`. See `docs/smoothening-review-2026-09-08.md` for the coordinator's complete finding/disposition record and `docs/smoothening-manual-checklist.md` for per-fix user checks.

- Luna xhigh agents completed the coding and correction assignments without progress inspections. Coordinator work was integration, source review, documentation and permitted automated validation.
- Root harness passed at `375cfc8`: documentation/contracts, 439 backend tests/3,107 assertions, 218 extension tests, compile and build.
- After the final extension-only content-recovery guard, integration validation at `69c883b` passed 219 extension tests in 30 files, compile and production build. Backend/contracts were unchanged.
- Final documentation lint and whitespace checks precede the delivery commit. No browser validation, live providers, Postgres/Redis services, real-data migrations, dependency upgrades or pushes occurred.
- Existing installed dependencies were copied into isolated worktrees; the backend used a dummy test-only environment with SQLite in memory.
- No actionable code/Ponytail finding remains open. Manual/browser and runtime concurrency validation belong to the user and are not represented as completed.
- The original checkout was not switched or edited by this delivery. The branch remains local in `C:/transcribed-subtitle-smoothening`.
