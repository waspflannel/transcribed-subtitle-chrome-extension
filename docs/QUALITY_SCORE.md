# Quality Score

Update this file when meaningful product, architecture, reliability, security, or workflow changes land.

## Summary

| Area | Grade | Notes | Next Action |
| --- | --- | --- | --- |
| Product | A | The extension can trigger or reuse backend-generated WebVTT tracks, render the active translated cue through browser `TextTrack` timing, and show Arabic token hover/pinned learning details with romanization/gloss settings. Live public-video proof completed for `Kax_tVLW7TU` and `y1wyPIAHhGQ`. | Address cue timing quality and release-readiness criteria in Phase 07. |
| Architecture | A- | Laravel, WXT, and contracts package share one schema-first subtitle generation endpoint. Backend WebVTT validation, storage, reuse, Laravel AI/OpenAI structured enrichment, and extension native track sync follow the documented single-path direction. | Keep release hardening focused on proof, abuse controls, and operational readiness. |
| Tests | A- | Harness runs contracts, Laravel feature/unit tests, WXT tests, compile, and build. Phase 06 covers structured enrichment validation, provider failure mapping, cue storage, TextTrack cue matching, and overlay token rendering/settings behavior. | Add browser screenshot smoke coverage when the extension smoke harness exists. |
| Observability | B+ | Subtitle processing emits structured acquisition, transcription, enrichment, track generation, reuse, split-retry, and duration mismatch logs without raw audio paths, prompts, full transcripts, translations, or token payloads. Extension WebVTT binding emits structured console diagnostics for track load failures and duration mismatch. | Turn live latency observations into release diagnostics during Phase 07. |
| Security | B- | Extension-facing `/v1/*` routes validate payloads, require anonymous install IDs, throttle by install ID and IP, keep provider secrets in Laravel, and delete raw audio after success or failure. A full threat model is still needed before release. | Fill the threat model before release hardening. |
| Agent Harness | A- | Scaffold exists, `check.ps1` runs stack-specific checks, and repeated simplicity/readability feedback is now promoted through golden principles, review guidance, the plan template, and future phase plans. | Add CI once a remote branch workflow exists. |

## Known Gaps

- CI checks are not configured.
- Automated browser screenshot smoke proof remains missing; live provider proof has been captured manually through backend logs.
- Architecture linting is not configured.
- WXT template dependencies currently report moderate npm audit advisories in dev/build tooling; `npm audit fix --force` proposes a breaking change and is tracked as `TD-002`.

## Cleanup Queue

Move recurring cleanup into `docs/exec-plans/tech-debt-tracker.md`.
