# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

The backend records persisted subtitle job and track rows, acquires YouTube audio into temporary storage, deletes raw audio after processing succeeds or fails, requests ElevenLabs Scribe word timestamps, normalizes words and provider-detected language codes into timed segments and WebVTT cue drafts, tokenizes cues through OpenAI/Laravel AI structured output with structural/source-order validation, fails visibly when tokenization cannot produce usable tokens, optionally romanizes cues, optionally translates cues, optionally enriches existing tokens, returns stable public errors with request IDs, throttles by install ID and IP, and prunes expired generated subtitles.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition and transcription emit stage-specific start, completed, and failed log events with public job ID, video ID, requested source language, detected source language when available, target language, duration, byte count, provider identity, model name, adapter name, segment count, and stable error code where available.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Tokenization, romanization, cue translation, full-card enrichment, and clicked-token enrichment emit started/completed/failed events with provider identity, model, cue/token counts where applicable, and stored dialect value where applicable without logging prompts, full transcripts, translations, token boundaries, romanizations, or token payloads.
- Tokenization validation retries emit `backend.tokenization_batch_retried` with model, source language, cue count, and reason only; no transcript or token payloads are logged.
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

Add traces for workflows that cross storage or external APIs.

## Future Harness Targets

- Local log query command.
- Metrics query command.
- Trace inspection notes.
- Per-worktree ephemeral observability stack when the app needs it.
