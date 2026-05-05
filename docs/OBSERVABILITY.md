# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

Phase 06 has the first translated learning-overlay path. The backend records persisted subtitle job and track rows, acquires YouTube audio into temporary storage, deletes raw audio after processing succeeds or fails, requests OpenAI WebVTT transcription, parses it into validated cue drafts, enriches cues through Laravel AI structured output, stores translated/tokenized cues, and returns stable public errors for validation, acquisition, transcription, enrichment, and cue-generation failures.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition and transcription emit stage-specific start, completed, and failed log events with public job ID, video ID, duration, byte count, Laravel AI provider identity, model name, adapter name, segment count, and stable error code where available.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Enrichment emits started/completed/failed events with provider identity, model, cue count, token count, and stored dialect value without logging prompts, full transcripts, translations, or token payloads.
- Extension WebVTT binding emits structured console diagnostics for video/track duration mismatch, WebVTT track load failures, and missing page video elements.

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
