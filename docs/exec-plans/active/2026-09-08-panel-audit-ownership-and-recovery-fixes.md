# Plan: Panel audit ownership and recovery fixes

Status: active
Owner: panel audit worker
Created: 2026-09-08
Last updated: 2026-09-08

## Goal And Scope

Implement R1, R2, R3, R7, R8, R9, R12, R15, R21, U1, U3 with one commit per finding in the isolated `audit/smoothening-panel-fixes` child worktree. Preserve password exclusions and concurrent work. Do not edit content.ts or shared overall plans.

## Acceptance Criteria

- Initialization, exact-operation polling/recovery, session isolation/reset, serialized preferences/submission, tab routing, action feedback, body timeouts, queue copy, and stale history meet their audit acceptance.
- Each assigned audit section records changes, numbered manual checks, and untested limits.
- Keep regression source, but run no tests, compile, builds, browser checks, dependency installs, or paid/production actions.
- Inspect each commit and leave a clean child worktree.

## Implementation Steps

1. Read actual panel/background/storage/API callers and relevant project docs.
2. Fix startup and body timeout, then operation ownership, recovery, account ownership, preferences/reset, tab targeting, action feedback and copy/history in dependency order.
3. Add focused regression source and per-ID documentation; commit each ID independently.
4. Run only `git diff --check` and `scripts/agent/check.ps1 -SkipAppChecks`; report content protocol integration needs.

## Decisions And Evidence

- Use existing entrypoints and helpers, not a new state framework.
- Content worker owns R10 renderer changes and R11 cue snapshot. R7 changes transcript targeting only; content must validate expected video before applying seeks.
- R1 moves lexical initialization and adds an actual-entrypoint import regression. No application checks executed.

## Completion Notes

Pending remaining findings. All new regression source is untested at user request.
