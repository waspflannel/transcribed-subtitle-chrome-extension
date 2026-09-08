# Plan: Fix full lyrics replacement failures

Status: completed
Owner: agent
Created: 2026-09-07
Last updated: 2026-09-07

## Goal

Fix the replacement review findings without changing the existing track on failure. Preserve the concurrent Watch UI edits. Do not rerun paid provider work or overwrite the user's track during verification.

## Scope and acceptance

- Alignment retries receive safe validation feedback and retain safe reason codes in logs.
- Recognized headings are removed before both prompting and exact text validation; remaining text must be consumed. Reject impossible timing-slot capacity before queueing.
- Require expected track identity at POST and retain source track/run identity in encrypted working state. Recheck identity, expiry, and entitlement before publication.
- Recovered terminal outcomes remain visible until dismissed for that attempt. Poll failures show retry feedback without discarding the active track.
- Recover lost queue deliveries through the existing scheduler once per revision. Revision checks and the existing overlap lock prevent duplicate work; a second stalled running unit fails safely.
- Focused regressions and the full harness pass. No new dependency or persistence table.

## Context and decisions

- Concrete video M8vDwlHigJA maps to job 0ed4a3e2-f3e9-45c4-a07b-acb0d1e1341c. The latest attempt failed at revision zero after alignment, before publication; original rejection details were not retained.
- Applied code-review, Ponytail, Laravel best practices, AI SDK, and subtitle-pipeline guidance. Preserve text privacy, use existing queue and storage paths, and prefer targeted tests over new architecture.
- Context7 Laravel 13 queue docs confirm after-commit dispatch does not atomically couple Redis and database writes. Use the current scheduled watchdog for recovery, with the existing overlap/revision guards.
- New requests require expectedTrackId. Existing active attempts without stored source identity fail safely rather than publishing against an unknown track.
- The Watch notice dismissal behavior supersedes the navigation-based dismissal in the earlier Watch editing plan.

## Validation

- Contract schema and generated types: passed initial check.
- TypeScript: passed initial compile.
- Initial focused backend run: two existing cancellation-test defects corrected (recording provider used instead of production provider; bypassed cancellation cleanup), and repeated-replacement fixture now refreshes expected identity.
- Full harness passed: contract validation/type generation, 407 backend tests (2837 assertions), 172 extension tests, TypeScript, and production extension build. Log: `app/backend/storage/logs/local-runtime/lyrics-fix-full-check.log` (ignored local artifact).
- Added one final regression for track identity changing during a provider call; it passed (5 assertions). Total backend coverage executed: 408 tests.
- Panel regression uses the actual HTML and entrypoint in jsdom with a mocked extension transport; covers recovered failure, navigation, explicit dismissal, newer attempts, and poll-error recovery.
- Pint passed. `git diff --check` passed. Existing user edits were preserved.

## Completion notes

- No new table, dependency, provider workflow, or edit history. Existing revision checks and atomic track publication remain the active path.
- Backend and extension must use the updated request contract together. The extension build is in `app/extension/.output/chrome-mv3`.
- No live provider request was submitted and the user's stored track was not edited. The previous failed attempt has cleared its lyrics; the learner must paste them again to retry. Provider output can still be rejected when it violates exact text/timing constraints; future failures now retain safe diagnostics.

- Local runtime: confirmed zero active jobs/corrections and empty subtitle queues, then gracefully restarted all 31 existing workers with their original commands. All remained running; stderr logs were empty. Backend server and environment settings were preserved.
