# Plan: Reduce subtitle analysis API usage and latency

Status: completed
Owner: agent
Created: 2026-09-09
Last updated: 2026-09-09

## Goal

Reduce analysis latency and unnecessary API requests without changing the selected Luna models or subtitle validation rules.

## Scope

- Set all OpenAI agents to medium reasoning as requested.
- Bound malformed-output recovery to one split for analysis, tokenization, and enrichment.
- Recognize output-token exhaustion and provider quota/spend exhaustion as terminal failures, including SDK-wrapped 429s.
- Poll status every five seconds and partial tracks every ten seconds; preserve cancellation, session checks, and partial delivery.
- Preserve all pre-existing worktree changes. No live provider experiments, dependency changes, or publication.

## Acceptance Criteria

- [x] Actual mocked HTTP requests carry medium reasoning.
- [x] A malformed batch cannot recursively fan out beyond one pair of smaller requests.
- [x] Token-limit and quota failures do not trigger additional provider calls; true rate limits remain retryable.
- [x] Partial cues still appear immediately on entering analysis and refresh at the reduced cadence.
- [x] Relevant tests and the full agent check pass; local workers reload the validated configuration.

## Relevant Context

- docs/RELIABILITY.md and docs/OBSERVABILITY.md
- docs/references/project-guardrails.md
- Existing trace: job cbfa3ac5-e94e-49a6-8436-e6e4d185120c used four calls for two analysis batches; 17,629 of 21,828 output tokens were reasoning tokens; analysis took 74 seconds.
- The installed Laravel AI SDK preserves the original RequestException under RateLimitedException and exposes FinishReason::Length on response steps.

## Decisions

- Use existing splitInvalidBatches controls rather than adding a retry framework.
- Fail token-limit responses before validating partial JSON instead of treating them as missing cues.
- Centralize quota classification in the exception factory used by both generation and lyrics correction.
- Keep provider-specific error details out of public error contracts and raw response bodies out of logs.
- Applied ponytail and backend-required AI SDK, Laravel, security, and subtitle-pipeline guidance. Checked Laravel SDK docs through Context7 (Boost MCP unavailable) and official OpenAI reasoning/error docs.

## Validation

- Focused regression tests: 62 backend tests and 7 background-entrypoint tests passed.
- Full scripts/agent/check.ps1 passed: documentation lint, contract checks, 452 backend tests (3,227 assertions), 224 extension tests, TypeScript compilation, and production extension build.
- PHP formatter and git diff --check passed.
- Local runtime restarted only after confirming no active jobs or queue backlog. Existing runtime profile values already matched the launcher; no profile setting changes were needed.
- Fresh configuration reports reasoning.effort=medium and service_tier=fast. Strict runtime check passes. All 32 managed processes are running (31 workers and one backend).
- Evidence: app/backend/storage/logs/subtitle-api-fix-check.log and subtitle-api-fix-runtime-restart.log (ignored local logs).
- API validation used mocks, not billed generation runs. Live latency and language-quality comparisons remain unmeasured.

## Implementation Assessment

Used the existing split switch and exception factory, without new dependencies, public contract fields, or retry frameworks. Quota classification covers generation and lyrics callers. Incomplete generation responses now stop instead of recursively resubmitting. Failed or unchanged partial-track refreshes stay on the ten-second cadence. Existing entitlement, cancellation, and session ownership checks remain in place.

## Reference Documentation

- https://developers.openai.com/api/docs/guides/reasoning#controlling-costs
- https://developers.openai.com/api/docs/guides/error-codes#api-errors
- Laravel AI SDK response steps and HTTP exception wrapping were checked against the installed vendor code and Context7 /laravel/ai.

## Remaining Acceptance

Reload the built extension to pick up background polling changes. A real-video run can measure the resulting latency and confirm output quality at medium reasoning.

## Progress

- 2026-09-09: Verified regression from local traces and inspected shared callers. Implementing bounded recovery and polling changes.

- 2026-09-09: Implementation, full validation, and local runtime reload completed.
