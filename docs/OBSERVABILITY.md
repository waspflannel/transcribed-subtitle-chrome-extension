# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

Phase 01 has scaffold validation output only. Runtime product observability starts when job processing and provider calls are introduced.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.

## Metrics

Define metrics for:

- Startup time.
- Critical workflow latency.
- Error rates.
- Queue or background task health, if applicable.

## Traces

Add traces for workflows that cross services, queues, storage, or external APIs.

## Future Harness Targets

- Local log query command.
- Metrics query command.
- Trace inspection notes.
- Per-worktree ephemeral observability stack when the app needs it.
