# Smoothening Audit Handoff

Updated: 2026-09-08, after branch consolidation. All audit code and documentation are now on `smoothening-fixes` in the main repository. This is not a claim that the audit is finished.

## User Goal And Constraints

- Fix every included item in `docs/ux-audit-smoothening-2026-09-06.md`.
- Make one individual commit per finding, with its code and documentation. Do not squash the work into one large commit. Commits are authorized; pushes are not.
- For each item, document What Changed, How To Test, expected results, and remaining limits. The user plans one large manual testing session later.
- Latest instruction: do not run test suites or browser tests; focus on code changes. Do not run builds, install dependencies, or exercise real services as a workaround. Regression test source can be added without running it. Mark new changes UNTESTED. Metadata/diff/documentation-only checks are allowed.
- Exclude password recovery, email-verification UX, password hints/rules, and R24. G5 includes only account/billing navigation and truthful access copy; U4 includes only expired-connection visibility. Our earlier password additions were withdrawn. Preserve pre-existing authentication/security behavior; do not disable verification enforcement or remove existing routes.
- Never mix audit fixes into `codex/lyrics-editing-and-full-replacement` or disturb its ongoing work. `C:\transcribed-subtitle-extension` is the sole retained worktree and was left on that lyrics branch. Switch it to `smoothening-fixes` only after coordinating with the user and ensuring concurrent lyrics work has stopped and its changes are safely saved.
- No paid generation, production requests, real-data migrations, dependency upgrades, destructive resets, coauthor trailers, or history rewriting.
- The user requested a handoff, then consolidation into one `smoothening-fixes` branch and removal of the audit worktrees/temporary branches. No additional audit fixes were completed during consolidation; R11 was preserved as unfinished work.

## Repository And Branches

Repository: `C:\transcribed-subtitle-extension`. Retained local branches:

| Branch | Purpose |
| --- | --- |
| `codex/lyrics-editing-and-full-replacement` | Concurrent lyrics work; left checked out and unchanged by consolidation |
| `smoothening-fixes` | All audit foundation, worker commits, R11 draft, audit report and this handoff |
| `main` | Existing main branch; unchanged |

The four temporary audit worktrees are retired. Their former paths under `C:\Users\jaden\AppData\Local\Temp\opencode` are historical locations, not continuation instructions. The old `audit/smoothening-*` branches and redundant `smoothening` baseline branch are retired after confirming their tips are ancestors of `smoothening-fixes`. Remote branches were not changed.

Consolidation preserved original per-finding commit hashes using normal merges, not squash, cherry-pick or rebase:

| Merge | Commit |
| --- | --- |
| Panel commits | `2e5959c` |
| Backend commits | `b606412` |
| Overlay commits and R11 draft | `dc5979a` |

All finding-local updates are now in this branch's audit report. The worker calls had reported usage-limit failures but did save nine commits and an R11 draft. The coordinator inspected the integration diffs; the newer fixes still need full correctness review and remain untested.

While the lyrics branch is checked out, inspect this handoff without switching via `git show smoothening-fixes:handoff.md`. The working-directory copy of a similarly named handoff on another branch is not this audit handoff.

## Read First

1. This file and the actual `git worktree list`, `git status`, and commit logs. Coordinate before switching the sole worktree away from ongoing lyrics work.
2. `AGENTS.md` and the smallest relevant architecture, frontend, reliability, security, and review docs. For Laravel, follow `docs/references/boost-skill-routing.md`.
3. `docs/ux-audit-smoothening-2026-09-06.md`, the system of record for findings, original evidence, acceptance, and manual steps.
4. `docs/exec-plans/active/2026-09-07-resolve-smoothening-ux-audit.md`, the overall plan.
5. `docs/exec-plans/active/2026-09-08-panel-audit-ownership-and-recovery-fixes.md`, now on this branch.
6. `docs/exec-plans/active/2026-09-08-backend-smoothening-audit-fixes.md`, now on this branch.

The original report is based on `smoothening` at `4969225d80731fc3f5ad95d2a9a3f87b7d685487`. Its source line references describe that baseline, not today's edited files. This baseline has a Chrome side panel and no lyrics editor; do not import assumptions from the other branch.

## Main Branch Commits

These changes are individually committed and retained on `smoothening-fixes`.

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

## Integrated Worker Commits

These commits are all ancestors of `smoothening-fixes`. Do not cherry-pick them again. They are implementation checkpoints, not tested or fully reviewed fixes.

### Panel Commits

| Finding | Commit | Saved Implementation |
| --- | --- | --- |
| R1 | `ee9258e` | Initialize lexical polling state before startup; actual-entrypoint regression source |
| R21 | `0b0e85d` | Keep API timeout through body consumption/validation; response-stream regression source |
| R2 | `6603447` | Preserve submitting state, reconcile exact returned job, guard operation-specific delayed writes |

Files include `entrypoints/background.ts`, `entrypoints/sidepanel/main.ts`, `utils/api.ts`, `utils/backend-subtitle-state.ts`, API/state/entrypoint regression tests, the panel plan, and finding-local audit sections. Extension paths are relative to `app/extension`.

Remaining panel assignment: **R3, R7, R8, R9, R12, R15, U1, U3**.

### Overlay Commits

| Finding | Commit | Saved Implementation |
| --- | --- | --- |
| R4 | `0d214a7` | Inject across YouTube documents, activate supported-video behavior through existing URL checks |
| R6 | `e80f732` | Epoch/request/video/track guards for delayed hydration and enrichment success/failure |
| R5 | `d94ada5` | Live-player/binding recovery, detached-host reattachment, fullscreen/player-relative rail |
| R10 | `0411bb2` | Content-aware transcript invalidation, latest-cue resolution, merge concurrent word-card metadata |

Files include `entrypoints/content.ts`, `entrypoints/background.ts`, `entrypoints/sidepanel/transcript-view.ts`, `utils/overlay.ts`, and finding-local audit sections. These four commits do not add regression test files; review/add the smallest useful regression source before calling the work complete, without executing it.

Remaining overlay assignment: **R11 (saved draft), R13, R14, R22, R23, U5**.

### Backend Commits

| Finding | Commit | Saved Implementation |
| --- | --- | --- |
| R16 | `7f44ae9` | Account-before-job lock helper, transactional terminal settlement/deletion, captured-run cleanup |
| R17 | `0d17c64` | Immutable expected-run/stage checks through pipeline continuations and cost writes |

Files include `WebSubtitleJobController`, billing entitlement/ledger services, subtitle pipeline/artifact/failure/job/cost services, new `SubtitleJobLock`, regression feature tests, the backend plan, and finding-local audit sections.

Remaining backend assignment: **R18, R19, R20, U2**. **G1 cancellation** was reserved until backend run/credit integrity and extension operation ownership are ready. G1 is still unimplemented.

## R11 Draft Checkpoint

The former overlay worktree's five unstaged files were preserved in **`eb6d384`**, `wip: preserve active cue synchronization draft (R11)`, then merged onto `smoothening-fixes`:

```text
app/extension/entrypoints/background.ts
app/extension/entrypoints/content.ts
app/extension/entrypoints/sidepanel/main.ts
app/extension/utils/messages.ts
docs/ux-audit-smoothening-2026-09-06.md
```

The draft adds `panel.getActiveCue { tabId, youtubeVideoId, trackId }` and `background.getActiveCue { youtubeVideoId, trackId }`. Content returns the current cue; background validates the target tab's video and echoes tab identity. The panel pulls a snapshot when rendering a ready track and rejects outdated responses. Content moves cue broadcasts into overlay updates, deduplicated by video/track/cue identity, to cover seeks as well as natural cue changes.

This is a committed draft, NOT a finished R11 fix. Its audit section explicitly says INCOMPLETE / UNTESTED. Inspect actual code, complete the missing pieces and regression source, then make a separate R11 completion commit; do not amend the preservation commit without permission.

R11/R7 integration points:

- R7 still needs sender/displayed-tab identity on unsolicited cue notices, exact-tab seek targeting, preserved window scope, and destination video validation in content.
- The R11 draft increments `cueSnapshotRequest` for every active-cue notice. Apply R7 tab/video/track filtering BEFORE that increment and before highlighting. A foreign tab must not invalidate the displayed tab's pull.
- R1's early polling-variable initialization was preserved by the merge; keep it intact during further `sidepanel/main.ts` edits.
- R7 will edit transcript seeking; R10 already edits transcript data invalidation. Preserve both rather than taking one whole-file version.
- R2 changes background operation ownership; R10 changes enrichment merging; R11 adds message handling. Integrate focused hunks, never replace background.ts wholesale.

## Full Remaining Queue

The nine worker commits are integrated but still need correctness review. Finish these remaining items individually:

| Finding | Work Still Needed |
| --- | --- |
| R3 | Recover status polling after transient/auth interruptions and recovered jobs; retain identity/partial data |
| R7 | Tab-owned cue notices and exact-tab seek/play actions, including duplicate-video tabs/windows |
| R8 | Session-owned cached and pending state; late account A responses cannot affect account B |
| R9 | Serialized preference patches and claimed generation submission before awaited preparation |
| R11 | Complete the saved cue snapshot/seek-notification draft in a follow-up commit |
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

1. Inspect Git state in `C:\transcribed-subtitle-extension`. Do not switch branches while the user or another agent is editing lyrics. Arrange a safe handover first; do not create replacement worktrees without asking.
2. Once safe, use `git switch smoothening-fixes`. Verify the branch before editing. Do not merge audit work into lyrics or main.
3. Review the integrated worker code, particularly R16/R17 locking and the shared background/content/panel changes. R11 remains incomplete and R7 remains pending. No integration commands are needed; all old worker commits are already present.
4. Complete remaining findings on top of the consolidated branch, one finding per commit. Finish the R11 draft in a follow-up commit rather than silently counting its checkpoint as completion.
5. Update each item's audit section with implementation status, What Changed, numbered How To Test and expected outcomes. Keep historical evidence distinct from current untested implementation.
6. Use apply_patch for manual edits. Inspect `git status`, `git diff`, staged diff and `git log --oneline -10` before each commit; stage only the intended item. No `git add .`, amend, squash, or rebase without permission.
7. Keep the overall plan and this handoff current. Do not claim all tasks done until all included IDs are accounted for and review is complete.

## Validation State

- Early foundation work was tested before the user changed the testing instruction. Historical results are preserved in the audit and plans, but are not proof about new worker code or future integration.
- No application/browser bug was reproduced during the original audit. Source-supported risks were not represented as observed runtime failures.
- New worker commits and the saved R11 draft are UNTESTED and not fully correctness-reviewed. Some include regression source; source existing does not mean it passes. Merges preserve the work, not prove it works.
- The retired audit worktree's ignored files were generated dependencies/caches/build artifacts and an agent-authored test-only `.env` using SQLite in memory. They are not source changes and are not transferred to the main checkout. Its environment and dependencies remain untouched; do not assume they are suitable for audit runtime work or commit secrets.
- PostgreSQL locking, provider/queue failures and real Chrome/YouTube journeys still lack runtime evidence. Do not claim SQLite or static inspection establishes concurrency correctness.
- Allowed documentation-only command: `.\scripts\agent\check.ps1 -SkipAppChecks`. Do not run the full harness, suites, builds or browser until the user authorizes testing again.

## Prompt For The Next Agent

```text
Continue the smoothening UX audit from:
the smoothening-fixes branch in C:\transcribed-subtitle-extension.
Read its handoff using git show smoothening-fixes:handoff.md if another
branch is currently checked out.

Read the handoff, audit report, repo instructions and actual Git state first.
Do not interrupt ongoing lyrics work. Coordinate a safe branch switch with
the user before editing; keep audit changes only on smoothening-fixes.

All worker commits are already merged; do not cherry-pick them again.
Review the integrated code and finish the R11 draft saved in eb6d384.
Finish every remaining included audit item, one individual commit per
finding, with What Changed
and How To Test documentation. Exclude all password/verification work and
R24; preserve pre-existing auth/security. No test suites, browser tests,
builds, production/paid requests, dependency upgrades or pushes. Mark code
untested and leave manual testing instructions for my later testing session.
Do not squash, reset, amend existing commits or recreate retired worktrees.
Continue until all included items are accounted for and reviewed.
```
