# Autonomy

Use this file to track how much of the development loop agents can safely complete.

## Current Level

When **Brain / Worker** is selected, its [runbook](work-modes/brain-worker.md) narrows the permissions below: agents run non-UI checks only, the user owns browser/desktop UI acceptance, and each chunk requires user acceptance and approval before merge.

Level 7: agents make focused changes, validate locally, reproduce bugs, prove fixes with
screenshots/logs/traces, open reviewable PRs with the evidence template, and handle review
feedback (see the R1-R6 review/follow-up cycle in
[2026-06-21-extension-and-backend-hardening.md (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/completed/2026-06-21-extension-and-backend-hardening.md) for a worked example).

Blocked from Level 8 (recover from CI failures) because no CI is configured yet — see TD-002 in
`docs/exec-plans/tech-debt-tracker.md` and the "CI not configured" gap in `docs/QUALITY_SCORE.md`.
Level 9 (merge with minimal supervision) is not attempted; merges still go through human review.

## Levels

| Level | Capability | Required Harness |
| --- | --- | --- |
| 1 | Inspect repo and docs | `AGENTS.md`, docs map, doctor script |
| 2 | Make focused changes | plans, check script, app structure |
| 3 | Validate changes locally | tests, lint, build, app startup |
| 4 | Reproduce bugs | issue templates, seed data, browser/log access |
| 5 | Prove fixes | screenshots, videos, logs, metrics, traces |
| 6 | Open reviewable PRs | PR evidence template, review script |
| 7 | Handle feedback | review comments, rerun checks, update docs |
| 8 | Recover from CI failures | CI logs, remediation playbooks |
| 9 | Merge with minimal supervision | merge policy, reliable checks, escalation rules |

## Escalation Rule

Agents should escalate when judgment is required, not when a missing script, doc, test, or lint could make the task tractable next time.
