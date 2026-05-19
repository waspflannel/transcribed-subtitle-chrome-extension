# Plan: Async AI subtitle pipeline optimization

Status: completed
Owner: agent
Created: 2026-05-14
Last updated: 2026-05-15

## Goal

Move subtitle generation off the blocking create request and optimize the OpenAI-backed tokenization, romanization, translation, and full-card stages with queue-backed cue batch parallelism.

The product path should stay narrow: the backend still acquires YouTube audio, transcribes once with ElevenLabs Scribe, normalizes draft cues, then dispatches OpenAI/Laravel AI cue batches on a dedicated `subtitle-ai` queue. Translation can run alongside tokenization after transcription; romanization waits for tokenizer boundaries; final tracks keep the existing response shape once completed.

## Scope

- In scope:
  - Async `POST /v1/subtitle-jobs` status response and single-job polling endpoint.
  - Laravel database queue tables, job batch tables, failed-job table, queued jobs, and intermediate artifact storage.
  - Parallel cue-batch tokenization, translation, romanization, and optional full-card enrichment.
  - Backend/contract/extension tests, generated contract types, and durable docs.
- Out of scope:
  - ElevenLabs realtime/webhook transcription.
  - Redis, Horizon, WebSockets, user accounts, provider failover, or new AI providers.
  - Changing final generated track shape or extension overlay rendering behavior.

## Acceptance Criteria

- [x] New subtitle requests return a queued/running job without waiting for transcription or OpenAI stages.
- [x] Compatible completed tracks are still reused and returned immediately with `status: completed` and `track`.
- [x] `GET /v1/subtitle-jobs/{jobId}` returns running, completed, failed, and not-found states scoped to the extension install ID.
- [x] Tokenization and translation batch jobs can run in parallel after transcription.
- [x] Romanization batch jobs wait for tokenization and preserve tokenizer boundaries.
- [x] Full enrichment batch jobs wait for merged tokenization/translation/romanization output.
- [x] Failed provider/batch work marks the job failed with a stable public error and no leaked transcript/token payloads.
- [x] Extension generation polls backend status and no longer uses the local estimated-progress timeline.
- [x] Contracts, generated TypeScript, docs, and tests describe the async status contract.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`, `docs/SECURITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Build standards: `how_to_build.txt`
- Related plans: `docs/exec-plans/completed/2026-05-14-simplify-laravel-ai-provider-orchestration.md`, `docs/exec-plans/completed/2026-05-12-simple-ai-tokenization-pipeline-refactor.md`, `docs/exec-plans/completed/2026-05-11-clean-elevenlabs-scribe-rewrite.md`
- Known risks:
  - Database queue workers must be running in local/production environments or jobs will remain queued.
  - Provider rate limits can offset parallelism gains; default worker count stays conservative.
  - Intermediate artifacts contain transcript-derived data and must not be logged or retained beyond processing/track retention.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement backend async job status contract and routes.
- [x] Implement queue migrations, artifacts, and queued pipeline.
- [x] Update extension polling workflow.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\backend; php artisan test --compact; Pop-Location
Push-Location .\app\extension; npm test; npm run compile; Pop-Location
Push-Location .\packages\contracts; npm run check; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Tests:
  - `Push-Location .\app\backend; php artisan test --compact; Pop-Location` - 108 passed, 603 assertions.
  - `Push-Location .\app\extension; npm test; npm run compile; Pop-Location` - 9 files / 41 tests passed, TypeScript compile passed.
  - `Push-Location .\packages\contracts; npm run check; Pop-Location` - schemas, fixtures, OpenAPI, and generated TypeScript passed.
  - `.\scripts\agent\check.ps1` - repository harness passed.
- Screenshots or video: not expected; workflow state only.
- Logs: stage logs remain payload-free and include async/batch lifecycle where useful.
- Metrics or traces: local timing evidence that motivated the queue path.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-05-14 | Use Laravel database queues and batches first. | The repo already defaults to the database queue driver and the plan needs current speed gains without adding Redis/Horizon infrastructure. |
| 2026-05-14 | Keep ElevenLabs transcription sequential inside the first queued job. | Local logs show OpenAI tokenization/romanization/translation dominate wall time more than Scribe transcription. |
| 2026-05-14 | Store intermediate artifacts in a job-scoped table instead of serialized queue payloads. | Queued jobs should carry identifiers, not transcript/token payloads, and artifacts need cleanup with the job lifecycle. |
| 2026-05-14 | Loaded backend skills: ai-sdk-development, laravel-best-practices, laravel-patterns, laravel-security, laravel-specialist, subtitle-pipeline. | The change touches Laravel queues, AI provider orchestration, API contracts, raw audio cleanup, transcript artifacts, and logs. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-14 | Plan created. |  |
| 2026-05-14 | Plan refined from user-provided async optimization plan and repository guardrails. | `AGENTS.md`, `ARCHITECTURE.md`, `docs/*`, `how_to_build.txt`, backend skills, Context7 Laravel queue docs. |
| 2026-05-15 | Implemented async backend pipeline, polling extension flow, contract changes, and docs updates. | Backend tests, extension tests/compile/build, contract check, and `scripts/agent/check.ps1` passed. |

## Completion Notes

- What changed: `POST /v1/subtitle-jobs` now returns status immediately unless a compatible track is cached; `GET /v1/subtitle-jobs/{jobId}` polls status; database queue/batch/failed-job tables and `subtitle_job_artifacts` support queued processing; tokenization/translation/romanization/enrichment run as cue-batch jobs; extension polling replaced estimated progress.
- Validation results: backend 108 tests / 603 assertions passed; contracts check passed; extension tests, compile, and build passed; full agent harness passed.
- Simplicity/readability review: transcription remains sequential and provider-specific; queued jobs carry only job IDs and batch indexes; artifact cleanup happens on finalization or job deletion; old processing versions were bumped to avoid cache mixing.
- Residual risk: real latency and provider rate-limit behavior still need public-video timing data with multiple `subtitle-ai` workers.
- Follow-up debt: tune batch size and worker count after timing logs; consider queue health checks before production use.
