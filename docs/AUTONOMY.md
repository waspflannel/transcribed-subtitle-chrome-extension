# Autonomy

Use this file to track how much of the development loop agents can safely complete.

## Current Level

Level 1: scaffolded harness. Agents can inspect docs and run baseline checks.

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
