# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

The backend records persisted subtitle job, artifact, and track rows, acquires YouTube audio into temporary storage, prepares Scribe input as 16 kHz mono FLAC, deletes raw and prepared audio after processing succeeds or fails, requests ElevenLabs Scribe word timestamps, normalizes words and provider-detected language codes into timed segments and WebVTT cue drafts, tokenizes cues through each job's saved Laravel AI provider and exact model with structural validation that trusts model wording and segmentation, fails visibly when tokenization cannot produce usable tokens, optionally romanizes cues, optionally translates cues, optionally enriches existing tokens, returns stable public status/error states with request IDs, throttles by install ID and IP, and prunes expired generated subtitles.

## Logging

- `backend.youtube_request_finished` separates metadata extraction and audio download duration by worker PID. It never logs metadata, media URLs or audio paths.

- `backend.openai_response_received` records actual Responses API request settings, returned service tier, HTTP timing, provider processing time when supplied, input/output/reasoning token counts, `response_status`, and `incomplete_reason`. All agents request `high` reasoning. Correlate `worker_pid` and timestamp with stage traces; `request_id` identifies the provider request. Missing fields remain null. No request/response bodies or authorization headers are logged. Fast mode is requested with `service_tier=fast`; the returned tier is the evidence of which tier served the request.

- Generation allows one identical retry of malformed analysis while the run is active. It never splits batches or fabricates fallback tokens. Corrections and cards use one response. `output_token_limit` and `provider_quota_exhausted` are terminal; temporary rate limits keep queue backoff. Cost rows estimate enabled features and do not represent separate requests or actual reasoning-token spend.

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition, audio preparation, and transcription emit stage-specific start, completed, fallback, timing, and failed log events with public job ID where available, video ID where available, requested source language, detected source language when available, target language, duration, byte count, provider identity, model name, adapter name, segment count, prepared MIME type, prepared byte count, and stable error code where available.
- Audio preparation logs include `backend.audio_preparation_started`, `backend.audio_preparation_ffmpeg_completed`, and `backend.audio_preparation_completed`; these events must not include provider keys, raw audio paths, transcript text, cue text, prompts, or audio bytes.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Tokenization, romanization, cue translation, and clicked-token enrichment emit started/completed/failed events with provider identity, model, cue/token counts where applicable, and stored dialect value where applicable without logging prompts, full transcripts, translations, token boundaries, romanizations, or token payloads.
- Queue wait, transcription, cue-batch processing, continuation, finalization, and total completed-track duration emit sanitized timing logs: `backend.subtitle_queue_wait_observed`, `backend.subtitle_stage_timing`, and `backend.subtitle_completed_track_timing`.
- Provider cost estimates and actual request/token timings remain operational diagnostics, not application charges. Worker saturation and provider rate errors retain safe reason codes. There are no plan, minute-ledger, account-concurrency or paid-priority metrics.
- Subtitle runtime tracing persists sanitized `subtitle_job_events` rows and emits `backend.subtitle_trace_event` logs with stable event names for job creation/reset/completion/failure, stale run skips, queue processing/processed/failed, batch lifecycle, stage start/completion/slow warnings, and artifact read/write/delete.
- `transcript.cache_hit` marks skipped acquisition/transcription. `delivery.first_cue_available` records the first stable source prefix. `delivery.first_annotated_cue_available` records the first analyzed playback prefix, including its `ready_through_ms`. Both durations start at job creation; compare these with `job.completed` to measure how much work overlaps playback. `backend.transcription_chunked` records parallel chunk count. Combined analysis has one stage duration under the existing `tokenizing` API stage; translation and romanization cost rows describe features in that call, not additional stages.
- Each generation has a `run_id`; queued subtitle work carries the run ID and stale queued payloads no-op before provider calls or artifact writes, with `job.stale_run_skipped` trace evidence.
- Local diagnostics are available through `php artisan subtitles:runtime-check`, `php artisan subtitles:runtime`, `php artisan subtitles:trace <public-job-id>`, `php artisan subtitles:slow`, and `php artisan subtitles:metrics`; all support `--json`. Runtime output reports generation and batch queue family depths separately plus resolved worker-group configuration.
- Production deployment diagnostics add `php artisan ops:production-check --json` for safe configuration evidence and Supervisor `tse-*` status for worker process heartbeat. The production runbook maps these commands to alert conditions for `/up`, queue depth, failed jobs, slow stages, provider rate limits, scheduler/pruning, disk space, and backup restore evidence.
- Malformed analysis retries emit `backend.analysis_batch_retried` with provider, model, cue count and reason; no content is logged.
- Queue job payloads contain job IDs, batch indexes, and scalar queue timing metadata only. Transcript text, draft cues, and AI batch results live in `subtitle_job_artifacts` and are deleted when the final track is persisted or the job fails.
- Extension WebVTT binding emits structured console diagnostics for video/track duration mismatch, WebVTT track load failures, and missing page video elements.
- Proxy-facing API failures emit `backend.proxy_invalid_install_id`, `backend.proxy_rate_limited`, and `backend.proxy_internal_error` with request IDs and without raw install IDs.
- Subtitle job creation and incomplete-job retry emit `backend.subtitle_job_created` and `backend.subtitle_job_reused_for_retry`.
- Expiration cleanup emits `backend.expired_subtitles_pruned` with deleted track and job counts.
- Expiration cleanup deletes trace rows tied to expired subtitle jobs through the `subtitle_job_events` job relationship.
- Extension generation emits `extension.subtitle_generation_started`, `extension.subtitle_generation_completed`, `extension.subtitle_generation_failed`, and `extension.local_state_cleared` without subtitles or token payloads. Progress shown during generation comes from backend job status polling, not a local estimated timeline.

## Metrics

Define metrics for:

- Startup time.
- Critical workflow latency.
- Error rates.
- Background task health, if applicable.

`php artisan subtitles:metrics --json` groups completed jobs by video-duration bucket, provider/model, processing version and transcript-cache hit, reporting completed count, first source/annotated cue sample counts and p50/p95 latency, p50/p95 generation duration, p95 queue wait, estimated provider cost, and cost per generated minute. The command reads only subtitle job rows and sanitized trace events.

Metrics select events from each job's current `run_id`. Cue batch queue wait starts at Laravel's own queue publication timestamp, including for successors in a chain. That timestamp has second precision; a retried payload retains its original publication time, so its wait can include its earlier attempts and backoff. It is not pure broker residence time across retries.

`backend.transcription_quality` logs job/run IDs, normalized chunk language codes, bounded language probabilities, and the count/mean/minimum of available word log probabilities. No words or provider payloads are logged. These are diagnostic signals, not measured transcription accuracy or automatic retry thresholds. `backend.transcription_started` also records ingestion mode. Combined analysis emits one stage duration; configured per-feature costs remain estimates, with readings produced by the same selected AI model. Vocabulary hints and their surcharge were removed.

## Traces

Subtitle workflow traces are persisted in `subtitle_job_events` and mirrored into structured logs. Trace context is scalar and sanitized only; it must not include transcripts, cue text, token text, prompts, translations, romanization, raw provider payloads, raw audio paths, provider secrets, or install IDs.

## Future Harness Targets

- Local log query command.
- Metrics query command.
- Trace inspection notes.
- Per-worktree ephemeral observability stack when the app needs it.

Analysis progress uses persisted results against the entire current draft batch plan. Early analysis does not jump the overall percentage to 90 while audio is still transcribing. Preview coverage is a conservative video timestamp; a completed later batch cannot fill a gap left by an unfinished earlier batch.

`provider.transcription_chunk_completed` records each successful transcription call, including upload and response validation, with chunk index, input bytes and elapsed milliseconds. It excludes prefix assembly and queue wait. Parallel request durations overlap; do not add them together to estimate total wall time. First-cue metrics are null when historical traces lack the event, rather than being reported as instant delivery.

The first-subtitle experiment records `audio.chunk_prepared` with chunk index, FLAC bytes and extraction duration. Extraction now runs inside each transcription member, before its provider timing begins. `optimizing-audio` therefore no longer includes chunk extraction; `transcribing` includes concurrent extraction and uploads. Compare end-to-end and first-annotated-cue metrics against the preserved baseline, rather than interpreting a shorter optimizing stage as a standalone speedup. Baseline measurements are saved at `docs/exec-plans/evidence/2026-09-12-generation-timing-baseline.csv`.

Opt-in audio acquisition logs `backend.youtube_metadata_reused` with a hit boolean and `backend.youtube_direct_download` with success and duration_ms. Best-effort resolution failures emit `backend.youtube_prefetch_failed`. These logs exclude metadata, signed media URLs, request headers and process output. Prefetch queue payloads contain only installation/video IDs and enqueue time. Use delivery trace events to measure full startup latency separately from acquisition time.

## Lyrics replacement timing

`backend.lyrics_correction_unit_started` records job/attempt/revision, batch index, original enqueue timestamp, and queue wait (including retry delays). `backend.lyrics_correction_unit_finished` records successful unit execution duration; a finished unit may have been discarded by a concurrent cancellation. Match these events by attempt, revision and batch index to compare alignment and analysis waits. Logs exclude lyrics, provider output and private work state. The panel polls active replacement status every two seconds, keeping history refresh at ten seconds, and returns to normal idle polling after a terminal result.
