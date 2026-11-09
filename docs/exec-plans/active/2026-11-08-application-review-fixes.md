# Application review fixes

Created: 2026-11-08
Status: In progress

Deliver five ordered PRs: correctness and latency; generated validation and partial tracks; simplification; evaluation; accuracy features. Preserve 0b8ca567. No paid calls or live generation.

## Decisions

- Local guidance and planning files were removed from Git in 75ab4d14. Read and copy them from the main worktree. Force-add the requested architecture and execution evidence for this delivery.
- Use Laravel best practices and subtitle-pipeline guidance. Boost commands are unavailable in this checkout. Context7 Laravel 13 docs confirm timestamp behavior.
- Keep Codex/Claude concurrency at 3, as documented. No evidence justifies 15.
- Poll jobs at one second, subject to cross-tab pacing. Retry transient failures at five seconds, at most six consecutive failures. Contract and non-retryable HTTP failures stop immediately.
- Job status accepts sinceRevision; unchanged partial payloads are omitted. Terminal tracks are always returned.
- Claude prompt readers hold a shared file lock. Disconnect and account replacement require exclusive ownership and reject while busy.

## Progress

- PR 1 implemented. Full scripts/agent/check.ps1 passed: 727 backend tests passed (4 skipped), 428 extension tests passed, contracts, compile/build and release/runtime/desktop checks passed. CI and merge pending.
- PRs 2-5 have not started.

- Regression coverage: terminal contract errors, six-failure retry bound, overlap selection, ads, saved source language, revision omission, Claude runtime locking and unchanged-progress heartbeats.
- Runtime harness exposed a PowerShell 5.1 nested-array issue in PID record reading. Assign parsed JSON before returning it; existing process ownership tests pass.
