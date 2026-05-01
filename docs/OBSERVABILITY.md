# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

Phase 03 introduced the first runtime product path. The backend records persisted subtitle job and track rows, and public API failures use stable error responses.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.

## Metrics

Define metrics for:

- Startup time.
- Critical workflow latency.
- Error rates.
- Background task health, if applicable.

## Traces

Add traces for workflows that cross services, queues, storage, or external APIs.

## Future Harness Targets

- Local log query command.
- Metrics query command.
- Trace inspection notes.
- Per-worktree ephemeral observability stack when the app needs it.
