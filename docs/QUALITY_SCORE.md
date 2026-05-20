# Quality Score

Update this file when meaningful product, architecture, reliability, security, or workflow changes land.

## Summary

| Area | Grade | Notes | Next Action |
| --- | --- | --- | --- |
| Product | A | The extension can trigger or reuse backend-generated WebVTT tracks, select subtitle/source and translation/target languages from the WER-ranked transcription catalog, choose default on-demand or Full word cards mode, optionally translate subtitle cues, render native-timed subtitles, use a tokenizer agent for learner-friendly word cards across scripts, show romanization when requested and present, generate clicked word cards, expose backend Jobs/progress with detected language, show stable failure copy, and clear local extension state. | Run the full public-video matrix and capture browser screenshots before store submission. |
| Architecture | A- | Laravel, WXT, and contracts package share schema-first async subtitle generation, a canonical WER-ranked language catalog, backend job history/polling, and learning-token endpoints. ElevenLabs Scribe owns sequential transcription, catalog-normalized language detection, and normalized timed segments/WebVTT; OpenAI/Laravel AI structured output owns Redis-queued cue-batch tokenization, optional romanization, optional cue translation, and word-card enrichment behind backend services. Postgres is the runtime data store, Redis is the queue store, and SQLite is limited to PHPUnit's test-only in-memory profile. Backend structural/source-order token validation, same-agent split retry for invalid multi-cue tokenization batches, loud single-cue tokenization/romanization/translation failures, artifact storage, reuse, cleanup, rate limits, tier-aware queues, per-install concurrency caps, budget checks, cost estimates, contract-validated backend responses, locked clicked-token merges, and extension native track sync follow the documented single-path direction. | Capture medium and near-limit provider-backed Postgres + Redis timing evidence and provider rate-limit behavior. |
| Tests | A- | Harness runs contracts, Laravel feature/unit tests, WXT tests, compile, and build. Coverage includes language catalog validation, Scribe request/normalization, no-space-script transcript cleanup, detected source language persistence, same-language skip behavior, tokenizer-agent flow, token output validation, optional romanization and translation, tokenization/romanization/translation failure states, async job dispatch/reuse/polling, cancelled batch skips, full-card mode, backend job history, backend response JSON Schema validation, learning-token caching/patching/concurrent merge preservation, language settings, Jobs progress helpers, and clicked-token overlay states. | Add browser screenshot smoke coverage when the extension smoke harness exists. |
| Observability | A | Subtitle processing now persists sanitized runtime trace rows and emits structured logs for job run IDs, queue lifecycle, batch lifecycle, queue wait, stage start/completion/slow warnings, artifacts, failures, completion, pruning, proxy failure, extension generation, duration mismatch, provider cost estimates, concurrency delays, performance budget checks, and worker auto-start lifecycle without raw audio paths, process command/output excerpts, prompts, full transcripts, translations, romanizations, token payloads, or install IDs. Local CLI diagnostics can inspect runtime readiness, active runtime state, per-job traces, slow events, and generation metrics. | Capture medium and near-limit Postgres + Redis worker timing evidence with provider credentials. |
| Security | B | Extension-facing `/v1/*` routes validate payloads, require anonymous install IDs, throttle by install ID and IP, keep provider secrets in Laravel, delete raw audio after success or failure, add request IDs to public errors, hash install IDs in logs, and now document a first-release threat model. | Verify deployment config with `APP_DEBUG=false`, provider secrets in environment only, and scheduled cleanup enabled. |
| Agent Harness | A- | Scaffold exists, `check.ps1` runs stack-specific checks, and repeated simplicity/readability feedback is now promoted through golden principles, review guidance, the plan template, and future phase plans. | Add CI once a remote branch workflow exists. |

## Known Gaps

- CI checks are not configured.
- Automated browser screenshot smoke proof remains missing; live provider proof has been captured manually through backend logs.
- Full Phase 07 public-video matrix and visual QA screenshots are defined but still need a credentialed/manual release run.
- Architecture linting is not configured.
- WXT template dependencies currently report moderate npm audit advisories in dev/build tooling; `npm audit fix --force` proposes a breaking change and is tracked as `TD-002`.

## Cleanup Queue

Move recurring cleanup into `docs/exec-plans/tech-debt-tracker.md`.
