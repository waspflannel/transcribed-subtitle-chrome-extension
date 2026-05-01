# Quality Score

Update this file when meaningful product, architecture, reliability, security, or workflow changes land.

## Summary

| Area | Grade | Notes | Next Action |
| --- | --- | --- | --- |
| Product | A- | The extension can now trigger the backend mock subtitle flow: create or reuse a job, receive a completed mock track in the same response, and render a ready overlay state. Real audio/transcription behavior is deferred to Phase 04. | Start Phase 04 audio acquisition and transcription proof. |
| Architecture | A- | Laravel, WXT, and contracts package now share one schema-first subtitle generation endpoint where successful responses carry the generated track. Backend persistence, service, and HTTP layers follow the documented direction. | Keep Phase 04 provider/audio work behind narrow Laravel services. |
| Tests | A- | Harness runs contracts, Laravel feature tests, WXT tests, compile, and build. Phase 03 added API validation, duplicate job coalescing, expired job regeneration, completed track responses, and extension API client tests. | Add real provider/audio failure tests in Phase 04. |
| Observability | C+ | Runtime observability begins with persisted job/track rows and stable public API errors. Provider tracing and richer processing diagnostics are still future work. | Expand logs around audio acquisition and transcription provider calls in Phase 04. |
| Security | C+ | Extension-facing `/v1/*` routes validate payloads, require anonymous install IDs, throttle by install ID and IP, and keep provider secrets out of the extension. A full threat model is still needed before real provider/user data work. | Fill `docs/SECURITY.md` before real provider calls or user data. |
| Agent Harness | A- | Scaffold exists, `check.ps1` runs stack-specific checks, and repeated simplicity/readability feedback is now promoted through golden principles, review guidance, the plan template, and future phase plans. | Add CI once a remote branch workflow exists. |

## Known Gaps

- CI checks are not configured.
- Runtime observability is minimal and needs provider/audio coverage.
- Architecture linting is not configured.
- WXT template dependencies currently report moderate npm audit advisories in dev/build tooling; `npm audit fix --force` proposes a breaking change and is tracked as `TD-002`.

## Cleanup Queue

Move recurring cleanup into `docs/exec-plans/tech-debt-tracker.md`.
