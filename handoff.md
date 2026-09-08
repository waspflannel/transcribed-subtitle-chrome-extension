# Smoothening Audit Handoff

Updated: 2026-09-08. This is a verified repository-state checkpoint, not a claim that the audit is finished.

## User Goal And Constraints

- Fix every included item in `docs/ux-audit-smoothening-2026-09-06.md`.
- Make one individual commit per finding, with its code and documentation. Do not squash the work into one large commit. Commits are authorized; pushes are not.
- For each item, document What Changed, How To Test, expected results, and remaining limits. The user plans one large manual testing session later.
- Latest instruction: do not run test suites or browser tests; focus on code changes. Do not run builds, install dependencies, or exercise real services as a workaround. Regression test source can be added without running it. Mark new changes UNTESTED. Metadata/diff/documentation-only checks are allowed.
- Exclude password recovery, email-verification UX, password hints/rules, and R24. G5 includes only account/billing navigation and truthful access copy; U4 includes only expired-connection visibility. Our earlier password additions were withdrawn. Preserve pre-existing authentication/security behavior; do not disable verification enforcement or remove existing routes.
- Never modify, switch, reset, merge into, or commit in `C:\transcribed-subtitle-extension`. It is the actively edited `codex/lyrics-editing-and-full-replacement` worktree.
- No paid generation, production requests, real-data migrations, dependency upgrades, destructive resets, coauthor trailers, or history rewriting.
- The latest user request pauses implementation for this handoff. Resume the remaining work when the next agent is instructed to continue.

## Where To Work

All audit worktrees are under `C:\Users\jaden\AppData\Local\Temp\opencode` and share Git history. The original lyrics worktree is not an audit workspace.

| Purpose | Directory Name | Branch | Checkpoint |
| --- | --- | --- | --- |
| Main audit integration | `smoothening-ux-audit` | `audit/smoothening-fixes` | `743fe96` before the documentation-only handoff commit |
| Panel/background worker | `smoothening-panel-fixes` | `audit/smoothening-panel-fixes` | `6603447`; clean |
| Content/overlay worker | `smoothening-overlay-fixes` | `audit/smoothening-overlay-fixes` | `0411bb2`; dirty R11 work, listed below |
| Backend worker | `smoothening-backend-fixes` | `audit/smoothening-backend-fixes` | `0d17c64`; clean |

Main absolute path: `C:\Users\jaden\AppData\Local\Temp\opencode\smoothening-ux-audit`.

The worker branches all started at `743fe96`. Their later commits have NOT been merged or cherry-picked into the main audit branch. The main audit document therefore does not yet contain those branches' finding-specific updates. Each worker has its own copy of the same document.

The three worker tool calls reported usage-limit failures, but they DID save the work listed here. Do not equate a failed agent call with an empty worktree. The coordinator verified Git status, commit logs and the R11 working diff after the interruptions; the new worker implementations have not received a full coordinator correctness review.

## Read First

1. This file and the actual `git worktree list`, `git status`, and commit logs. Branches may advance after this checkpoint.
2. `AGENTS.md` and the smallest relevant architecture, frontend, reliability, security, and review docs. For Laravel, follow `docs/references/boost-skill-routing.md`.
3. `docs/ux-audit-smoothening-2026-09-06.md`, the system of record for findings, original evidence, acceptance, and manual steps.
4. `docs/exec-plans/active/2026-09-07-resolve-smoothening-ux-audit.md`, the overall plan.
5. In the panel worktree: `docs/exec-plans/active/2026-09-08-panel-audit-ownership-and-recovery-fixes.md`.
6. In the backend worktree: `docs/exec-plans/active/2026-09-08-backend-smoothening-audit-fixes.md`.

The original report is based on `smoothening` at `4969225d80731fc3f5ad95d2a9a3f87b7d685487`. Its source line references describe that baseline, not today's edited files. This baseline has a Chrome side panel and no lyrics editor; do not import assumptions from the other branch.

## Main Branch Commits

These changes are already individually committed on the main audit branch.

| Finding | Commit | Implementation |
| --- | --- | --- |
| Documentation baseline | `31a91f1` | Audit and execution scope, no application fix bundled in |
| G3 | `3f3bea5` | Native GET Refresh status links, snapshot labels, actionable checkout-return copy |
| G4 | `2adf8d6` | Canonical video identity, complete owned deletion count, explicit scope/consequences |
| G2 | `a80f1c1` | Remove misleading Retry; native Open video action with explicit settings-review instructions |
| G5, non-password only | `81f9764` | Account and billing link, honest preference/access labels, useful billing-denial directions |
| U4, expiry only | `743fe96` | Filter explicit and Sanctum-age-expired connections before limiting visible tokens |

G2 is resolved by the audit's allowed navigation-only solution. G3 is resolved by its explicit manual-refresh acceptance. Do not add a new retry protocol or automatic dashboard polling merely to remove historical "partial" wording. G5/U4 have excluded portions, not forgotten implementation work.

Register/reset/verification views and password enforcement were restored to baseline. Do not reintroduce the removed links, password hints, or account-switch verification UI.

## Worker Commits Not Yet Integrated

Commit order within each branch matters. These are implementation checkpoints, not tested or fully reviewed fixes.

### Panel Branch

| Finding | Commit | Saved Implementation |
| --- | --- | --- |
| R1 | `ee9258e` | Initialize lexical polling state before startup; actual-entrypoint regression source |
| R21 | `0b0e85d` | Keep API timeout through body consumption/validation; response-stream regression source |
| R2 | `6603447` | Preserve submitting state, reconcile exact returned job, guard operation-specific delayed writes |

Files include `entrypoints/background.ts`, `entrypoints/sidepanel/main.ts`, `utils/api.ts`, `utils/backend-subtitle-state.ts`, API/state/entrypoint regression tests, the panel plan, and finding-local audit sections. Extension paths are relative to `app/extension`.

Remaining panel assignment: **R3, R7, R8, R9, R12, R15, U1, U3**.

### Overlay Branch

| Finding | Commit | Saved Implementation |
| --- | --- | --- |
| R4 | `0d214a7` | Inject across YouTube documents, activate supported-video behavior through existing URL checks |
| R6 | `e80f732` | Epoch/request/video/track guards for delayed hydration and enrichment success/failure |
| R5 | `d94ada5` | Live-player/binding recovery, detached-host reattachment, fullscreen/player-relative rail |
| R10 | `0411bb2` | Content-aware transcript invalidation, latest-cue resolution, merge concurrent word-card metadata |

Files include `entrypoints/content.ts`, `entrypoints/background.ts`, `entrypoints/sidepanel/transcript-view.ts`, `utils/overlay.ts`, and finding-local audit sections. These four commits do not add regression test files; review/add the smallest useful regression source before calling the work complete, without executing it.

Remaining overlay assignment: **R11 (dirty work), R13, R14, R22, R23, U5**.

### Backend Branch

| Finding | Commit | Saved Implementation |
| --- | --- | --- |
| R16 | `7f44ae9` | Account-before-job lock helper, transactional terminal settlement/deletion, captured-run cleanup |
| R17 | `0d17c64` | Immutable expected-run/stage checks through pipeline continuations and cost writes |

Files include `WebSubtitleJobController`, billing entitlement/ledger services, subtitle pipeline/artifact/failure/job/cost services, new `SubtitleJobLock`, regression feature tests, the backend plan, and finding-local audit sections.

Remaining backend assignment: **R18, R19, R20, U2**. **G1 cancellation** was reserved until backend run/credit integrity and extension operation ownership are ready. G1 is still unimplemented.

## Preserve The R11 Working Changes

The overlay worktree has five unstaged files beyond `0411bb2`:

```text
app/extension/entrypoints/background.ts
app/extension/entrypoints/content.ts
app/extension/entrypoints/sidepanel/main.ts
app/extension/utils/messages.ts
docs/ux-audit-smoothening-2026-09-06.md
```

The draft adds `panel.getActiveCue { tabId, youtubeVideoId, trackId }` and `background.getActiveCue { youtubeVideoId, trackId }`. Content returns the current cue; background validates the target tab's video and echoes tab identity. The panel pulls a snapshot when rendering a ready track and rejects outdated responses. Content moves cue broadcasts into overlay updates, deduplicated by video/track/cue identity, to cover seeks as well as natural cue changes.

This is NOT a finished or committed R11 fix. Its audit section already says implemented/untested, which must not be mistaken for review completion. Inspect actual code, complete the missing pieces and regression source, then make a separate R11 commit.

R11/R7 integration points:

- R7 still needs sender/displayed-tab identity on unsolicited cue notices, exact-tab seek targeting, preserved window scope, and destination video validation in content.
- The R11 draft increments `cueSnapshotRequest` for every active-cue notice. Apply R7 tab/video/track filtering BEFORE that increment and before highlighting. A foreign tab must not invalidate the displayed tab's pull.
- Preserve R1's early polling-variable initialization when integrating the overlay's `sidepanel/main.ts` changes.
- R7 will edit transcript seeking; R10 already edits transcript data invalidation. Preserve both rather than taking one whole-file version.
- R2 changes background operation ownership; R10 changes enrichment merging; R11 adds message handling. Integrate focused hunks, never replace background.ts wholesale.

## Full Remaining Queue

After reviewing/integrating the nine worker commits, finish these items individually:

| Finding | Work Still Needed |
| --- | --- |
| R3 | Recover status polling after transient/auth interruptions and recovered jobs; retain identity/partial data |
| R7 | Tab-owned cue notices and exact-tab seek/play actions, including duplicate-video tabs/windows |
| R8 | Session-owned cached and pending state; late account A responses cannot affect account B |
| R9 | Serialized preference patches and claimed generation submission before awaited preparation |
| R11 | Finish and commit the existing cue snapshot/seek-notification draft |
| R12 | Separate account action outcomes/busy state from ordinary snapshot sequencing |
| R13 | Stable study focus, pause ownership, held-cue lifetime, silent-gap feedback |
| R14 | Revealable partial layers and actionable local binding retry without regeneration |
| R15 | Documented full local reset, session/request invalidation, no immediate track resurrection |
| R18 | Compensate queue-publication failures for submission and promotion |
| R19 | Locked compatibility/admission, submission-time FIFO, revalidated stalled heartbeat |
| R20 | Discover old/old-version owned operational history without reusing incompatible tracks |
| R22 | Negative inverse source time and viewport-aware player selection |
| R23 | Native editable detection and reachable constrained word-card placement |
| U1 | Describe queued work as waiting, with actual queue state propagation |
| U2 | Distinguish estimates from charged minutes |
| U3 | Keep same-session last-known history during refresh errors |
| U5 | Readable partial Watch transcript without pretending incomplete enrichment is ready |
| G1 | Explicit owner-scoped queued/running cancellation in Watch/History, after run safety fixes |

Excluded: **R24 and password/verification parts of G5/U4**. Read each report acceptance before choosing the smallest correct change. Do not silently count dependencies fixed by a sibling commit as verified; document shared fixes explicitly.

## Safe Continuation

1. Verify the worktrees and preserve R11's working changes. Do not clean, reset, or delete child branches/worktrees.
2. Review the saved commits before integration. Finish R11 in its existing overlay worktree, or deliberately leave it there while integrating the committed prefix. Do not bundle its draft into another finding's commit.
3. Cherry-pick reviewed worker commits onto `audit/smoothening-fixes` in the main audit worktree, retaining one commit per item. Resolve shared-file/doc conflicts deliberately. No cherry-pick or merge has been performed yet.
4. Continue remaining fixes on top of the integrated state, or rebase only with explicit user authorization. New isolated branches from the current integrated tip are safer than replaying stale worker bases. If continuing existing workers, do not forget their divergence.
5. Update each item's own existing audit section with implementation status, What Changed, numbered How To Test and expected outcomes. This limits conflicts between agents. Keep historical evidence distinct from current untested implementation.
6. Follow the repository's small-change rules and use apply_patch for manual edits. Inspect `git status`, `git diff`, staged diff and `git log --oneline -10` before each commit; stage only the intended item. Do not amend without permission.
7. Keep the overall plan and this handoff current. Do not claim all tasks done until all included IDs are accounted for and review/integration is complete.

Example integration commands AFTER review, run only in the main audit worktree. These have not been executed and may require conflict resolution:

```powershell
git cherry-pick ee9258e 0b0e85d 6603447
git cherry-pick 7f44ae9 0d17c64
git cherry-pick 0d214a7 e80f732 d94ada5 0411bb2
```

If R11 is completed later, cherry-pick its new hash separately. Do not use `git add .` while resolving conflicts or working with another agent's files.

## Validation State

- Early foundation work was tested before the user changed the testing instruction. Historical results are preserved in the audit and plans, but are not proof about new worker code or future integration.
- No application/browser bug was reproduced during the original audit. Source-supported risks were not represented as observed runtime failures.
- New worker commits and the dirty R11 implementation are UNTESTED and not fully coordinator-reviewed. Some include regression source; source existing does not mean it passes.
- The main audit worktree had existing lockfile dependencies and an ignored test-only backend `.env` using SQLite in memory. It is not a persistent interactive development setup. Child worktrees do not inherit ignored dependencies or environment files automatically. Do not copy secrets or environments from the lyrics checkout.
- PostgreSQL locking, provider/queue failures and real Chrome/YouTube journeys still lack runtime evidence. Do not claim SQLite or static inspection establishes concurrency correctness.
- Allowed documentation-only command: `.\scripts\agent\check.ps1 -SkipAppChecks`. Do not run the full harness, suites, builds or browser until the user authorizes testing again.

## Prompt For The Next Agent

```text
Continue the smoothening UX audit from:
C:\Users\jaden\AppData\Local\Temp\opencode\smoothening-ux-audit\handoff.md

Read the handoff, audit report, repo instructions and actual Git state first.
Use audit/smoothening-fixes in the dedicated audit worktree. Never touch the
concurrent C:\transcribed-subtitle-extension lyrics branch.

Review/integrate the saved per-finding worker commits and preserve/finish
the dirty R11 work in smoothening-overlay-fixes. Finish every remaining
included audit item, one individual commit per finding, with What Changed
and How To Test documentation. Exclude all password/verification work and
R24; preserve pre-existing auth/security. No test suites, browser tests,
builds, production/paid requests, dependency upgrades or pushes. Mark code
untested and leave manual testing instructions for my later testing session.
Do not squash, reset worktrees, amend existing commits or lose worker edits.
Continue until all included items are accounted for, reviewed and integrated.
```
