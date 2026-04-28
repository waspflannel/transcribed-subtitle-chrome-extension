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

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
