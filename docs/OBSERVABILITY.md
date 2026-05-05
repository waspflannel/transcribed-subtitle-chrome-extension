# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

Phase 07 has the first release-readiness path. The backend records persisted subtitle job and track rows, acquires YouTube audio into temporary storage, deletes raw audio after processing succeeds or fails, requests OpenAI WebVTT transcription, parses it into validated cue drafts, enriches cues through Laravel AI structured output, stores translated/tokenized cues, returns stable public errors with request IDs, throttles by install ID and IP, and prunes expired generated subtitles.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition and transcription emit stage-specific start, completed, and failed log events with public job ID, video ID, duration, byte count, Laravel AI provider identity, model name, adapter name, segment count, and stable error code where available.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Enrichment emits started/completed/failed events with provider identity, model, cue count, token count, and stored dialect value without logging prompts, full transcripts, translations, or token payloads.
- Extension WebVTT binding emits structured console diagnostics for video/track duration mismatch, WebVTT track load failures, and missing page video elements.
- Proxy-facing API failures emit `backend.proxy_invalid_install_id`, `backend.proxy_rate_limited`, and `backend.proxy_internal_error` with request IDs and without raw install IDs.
- Subtitle job creation and incomplete-job retry emit `backend.subtitle_job_created` and `backend.subtitle_job_reused_for_retry`.
- Expiration cleanup emits `backend.expired_subtitles_pruned` with deleted track and job counts.
- Extension generation emits `extension.subtitle_generation_started`, `extension.subtitle_generation_completed`, `extension.subtitle_generation_failed`, and `extension.local_state_cleared` without subtitles or token payloads.

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
