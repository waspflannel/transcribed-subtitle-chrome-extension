# Quality Score

Update this file when meaningful product, architecture, reliability, security, or workflow changes land.

## Summary

| Area | Grade | Notes | Next Action |
| --- | --- | --- | --- |
| Product | A | The extension can trigger or reuse backend-generated source tracks and render the active source cue in sync with YouTube playback. | Add translation and Arabic learning metadata in Phase 06. |
| Architecture | A- | Laravel, WXT, and contracts package share one schema-first subtitle generation endpoint. Backend segmentation/validation, storage, reuse, and extension sync follow the documented single-path direction. | Keep Phase 06 enrichment behind backend services and canonical cue contracts. |
| Tests | A- | Harness runs contracts, Laravel feature/unit tests, WXT tests, compile, and build. Phase 05 adds cue segmentation/validation, track reuse, and active-cue selection coverage. | Add enrichment contract and provider-failure tests in Phase 06. |
| Observability | B | Subtitle processing now emits structured stage, track generation, reuse, and duration mismatch logs without raw audio paths or transcripts. Extension sync emits structured console diagnostics for playback mismatch and missing cue gaps. | Add provider/enrichment diagnostics in Phase 06 without logging generated learning content. |
| Security | B- | Extension-facing `/v1/*` routes validate payloads, require anonymous install IDs, throttle by install ID and IP, keep provider secrets in Laravel, and delete raw audio after success or failure. A full threat model is still needed before release. | Fill the threat model before release hardening. |
| Agent Harness | A- | Scaffold exists, `check.ps1` runs stack-specific checks, and repeated simplicity/readability feedback is now promoted through golden principles, review guidance, the plan template, and future phase plans. | Add CI once a remote branch workflow exists. |

## Known Gaps

- CI checks are not configured.
- Real public-video/provider smoke proof is blocked until local `yt-dlp`, `ffmpeg`, and backend OpenAI credentials are available.
- Architecture linting is not configured.
- WXT template dependencies currently report moderate npm audit advisories in dev/build tooling; `npm audit fix --force` proposes a breaking change and is tracked as `TD-002`.

## Cleanup Queue

Move recurring cleanup into `docs/exec-plans/tech-debt-tracker.md`.
