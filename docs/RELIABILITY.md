# Reliability

## Reliability Expectations

- Define startup, shutdown, retry, timeout, and idempotency behavior before production use.
- Make failure modes observable through logs, metrics, traces, or explicit error states.
- Prefer deterministic checks over manual inspection.

## Startup And Runtime

- Document the expected app start command when a stack exists.
- Add a startup smoke check to `scripts/agent/check.ps1`.
- Track startup targets and performance budgets here.

## Failure Handling

For each critical workflow, define:

- Expected failures.
- User-visible behavior.
- Retry or rollback behavior.
- Signals emitted for debugging.

Phase 05 subtitle generation remains synchronous. Expected backend failures include unsupported YouTube URLs, videos over 60 minutes, non-public or unavailable videos, audio acquisition command failures, missing provider configuration, provider timeouts, malformed WebVTT transcription output, and unusable cue timing or empty cue text. User-visible API responses use stable error codes; raw audio cleanup runs in `finally` after successful transcription, provider failure, and thrown exceptions. Automatic retry is intentionally absent in this proof slice; users can submit the generation request again after fixing configuration or choosing a supported public video. Structured logs identify the failed stage without dumping raw audio paths, cue text, or full transcripts.

Extension playback sync is local and browser-native. It attaches generated WebVTT as a hidden `TextTrack`, listens for `cuechange`, clears the overlay when no cue is active, and logs diagnostics instead of trying to auto-correct track drift.

Provider queueing and failover are intentionally deferred for this proof. Revisit framework or SDK-native queueing before adding asynchronous transcription, but keep product-owned job state and raw audio cleanup explicit. Revisit provider/model failover only after the product supports more than one provider.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
