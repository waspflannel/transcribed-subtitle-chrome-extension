# Quality Score

Update this file when meaningful product, architecture, reliability, security, or workflow changes land.

## Summary

| Area | Grade | Notes | Next Action |
| --- | --- | --- | --- |
| Product | A | The extension can trigger or reuse backend-generated WebVTT tracks, select supported source languages, choose default on-demand or Full word cards mode, render native-timed subtitles, show romanization when present, generate clicked word cards, expose backend Jobs/progress, show stable failure copy, and clear local extension state. | Run the full public-video matrix and capture browser screenshots before store submission. |
| Architecture | A- | Laravel, WXT, and contracts package share schema-first subtitle generation, backend job history, and learning-token endpoints. ElevenLabs Scribe owns transcription; OpenAI is limited to romanization and word-card structured output. Backend WebVTT validation, storage, reuse, cleanup, rate limits, and extension native track sync follow the documented single-path direction. | Keep release hardening focused on proof, abuse controls, and operational readiness. |
| Tests | A- | Harness runs contracts, Laravel feature/unit tests, WXT tests, compile, and build. Coverage includes Scribe request/normalization, transcript-first romanization, full-card mode, backend job history, learning-token caching/patching, source-language settings, Jobs progress helpers, and clicked-token overlay states. | Add browser screenshot smoke coverage when the extension smoke harness exists. |
| Observability | A- | Subtitle processing emits structured acquisition, Scribe transcription, romanization, enrichment, clicked-token, track generation, reuse, retry, pruning, proxy failure, extension generation, and duration mismatch logs without raw audio paths, prompts, full transcripts, translations, or token payloads. | Turn live latency observations into release diagnostics when the public-video matrix is run. |
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
