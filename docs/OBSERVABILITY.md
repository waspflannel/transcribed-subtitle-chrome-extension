# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

Phase 05 has the first source-subtitle sync path. The backend records persisted subtitle job and track rows, acquires YouTube audio into temporary storage, deletes raw audio after processing succeeds or fails, converts timestamped transcription into validated source-only cues, and returns stable public errors for validation, acquisition, transcription, and cue-generation failures.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition and transcription emit stage-specific start, completed, and failed log events with public job ID, video ID, duration, byte count, provider name, SDK name, segment count, and stable error code where available.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Extension playback sync emits structured console diagnostics for video/track duration mismatch, missing significant cue gaps, and missing page video elements.
- Laravel AI SDK transcription events are a future observability hook if stage-level job logs are not enough. Do not add SDK event listeners unless they provide concrete debugging value, and never log raw audio paths, prompts, full transcripts, or segment payloads.

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
