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

Phase 04 subtitle generation remains synchronous. Expected failures include unsupported YouTube URLs, videos over 60 minutes, non-public or unavailable videos, audio acquisition command failures, missing provider configuration, provider timeouts, and malformed timestamped transcription output. User-visible API responses use stable error codes; raw audio cleanup runs in `finally` after successful transcription, provider failure, and thrown exceptions. Automatic retry is intentionally absent in this proof slice; users can submit the generation request again after fixing configuration or choosing a supported public video. Structured logs identify the failed stage without dumping raw audio paths or full transcripts.

Laravel AI SDK queueing and provider failover are intentionally deferred for this proof. Revisit SDK-native queueing before adding asynchronous transcription, but keep product-owned job state and raw audio cleanup explicit. Revisit SDK provider/model failover only after the product supports more than one provider.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
