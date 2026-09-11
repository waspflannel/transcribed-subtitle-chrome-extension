# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

The backend records persisted subtitle job, artifact, and track rows, acquires YouTube audio into temporary storage, prepares Scribe input as 16 kHz mono FLAC, deletes raw and prepared audio after processing succeeds or fails, requests ElevenLabs Scribe word timestamps, normalizes words and provider-detected language codes into timed segments and WebVTT cue drafts, tokenizes cues through each job's saved Laravel AI provider and exact model with structural validation that trusts model wording and segmentation, fails visibly when tokenization cannot produce usable tokens, optionally romanizes cues, optionally translates cues, optionally enriches existing tokens, returns stable public status/error states with request IDs, throttles by install ID and IP, and prunes expired generated subtitles.

Billing state is inspectable through local user subscription fields, idempotent Stripe webhook event rows, and append-only usage ledger rows for monthly grants, reservations, debits, refunds, and support adjustments. `php artisan billing:usage-report --json` summarizes public minutes and provider-cost telemetry by plan. `billing:prune-webhook-events` retains failed rows and prunes only successfully processed records older than 90 days, logging `backend.stripe_webhook_events_pruned` with safe counts.

The SaaS website uses first-party Laravel structured logs for beta funnel analytics. `analytics.marketing_page_view`, `analytics.signup_completed`, `analytics.checkout_started`, `analytics.extension_connected`, `analytics.subtitle_generation_started`, `analytics.first_generation_started`, and `analytics.retention_generation_started` capture route/page, app-key-HMAC user/install identifiers, plan, language pair, feature flags, and public timing/usage scalars only. These analytics logs intentionally exclude transcripts, prompts, generated subtitle text, YouTube URLs, provider payloads, bearer tokens, raw install IDs, and raw audio paths.

## Logging

- `backend.youtube_request_finished` separates metadata extraction and audio download duration by worker PID. It never logs metadata, media URLs or audio paths.

- `backend.openai_response_received` records actual Responses API request settings, returned service tier, HTTP timing, provider processing time when supplied, input/output/reasoning token counts, `response_status`, and `incomplete_reason`. All agents request `medium` reasoning. Correlate `worker_pid` and timestamp with stage traces; `request_id` identifies the provider request. Missing fields remain null. No request/response bodies or authorization headers are logged. Fast mode is requested with `service_tier=fast`; the returned tier is the evidence of which tier served the request.

- Generation allows one identical retry of malformed analysis while the run is active. It never splits batches or fabricates fallback tokens. Corrections and cards use one response. `output_token_limit` and `provider_quota_exhausted` are terminal; temporary rate limits keep queue backoff. Cost rows estimate enabled features and do not represent separate requests or actual reasoning-token spend.

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition, audio preparation, and transcription emit stage-specific start, completed, fallback, timing, and failed log events with public job ID where available, video ID where available, requested source language, detected source language when available, target language, duration, byte count, provider identity, model name, adapter name, segment count, prepared MIME type, prepared byte count, and stable error code where available.
- Audio preparation logs include `backend.audio_preparation_started`, `backend.audio_preparation_ffmpeg_completed`, and `backend.audio_preparation_completed`; these events must not include provider keys, raw audio paths, transcript text, cue text, prompts, or audio bytes.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Tokenization, romanization, cue translation, full-card enrichment, and clicked-token enrichment emit started/completed/failed events with provider identity, model, cue/token counts where applicable, and stored dialect value where applicable without logging prompts, full transcripts, translations, token boundaries, romanizations, or token payloads.
- Queue wait, transcription, cue-batch processing, continuation, finalization, and total completed-track duration emit sanitized timing logs: `backend.subtitle_queue_wait_observed`, `backend.subtitle_stage_timing`, and `backend.subtitle_completed_track_timing`.
- Generation optimization emits sanitized internal events for provider cost estimates, full-queue admission rejections, account AI batch concurrency delays, and performance budget checks: `provider.cost_estimated`, `backend.generation_queue_full_rejected`, `queue.concurrency_delayed`, `performance.budget_checked`, and `performance.budget_exceeded`. Concurrency context distinguishes real tier cap hits (`limit_reached`) from limiter lock contention (`lock_busy`) and includes queue family, limiter type, tier, configured limiter cache store when applicable, active count when observed, limit, and release delay. Submissions accepted beyond the processing concurrency are traced as status `queued`, and FIFO promotion into processing is traced as `job.promoted_from_queue`.
- Billing events are persisted in `billing_usage_events` rather than subtitle trace rows so support can audit grants, reservations, debits, refunds, adjustments, and provider-cost totals without reading transcripts or provider payloads.
- Subtitle runtime tracing persists sanitized `subtitle_job_events` rows and emits `backend.subtitle_trace_event` logs with stable event names for job creation/reset/completion/failure, stale run skips, queue processing/processed/failed, batch lifecycle, stage start/completion/slow warnings, and artifact read/write/delete.
- `transcript.cache_hit` marks skipped acquisition/transcription. `delivery.first_cue_available` records draft preview availability. `backend.transcription_chunked` records parallel chunk count. Combined analysis has one stage duration under the existing `tokenizing` API stage; translation and romanization cost rows describe features in that call, not additional stages.
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

`php artisan subtitles:metrics --json` groups completed jobs by generation tier and video-duration bucket, reporting completed count, p50/p95 generation duration, p95 queue wait, configured budget, budget misses, estimated provider cost, and cost per generated minute. The command reads only subtitle job rows and sanitized trace events.

Metrics select events from each job's current `run_id`. Cue batch queue wait starts at Laravel's own queue publication timestamp, including for successors in a chain. That timestamp has second precision; a retried payload retains its original publication time, so its wait can include its earlier attempts and backoff. It is not pure broker residence time across retries.

`backend.transcription_quality` logs job/run IDs, normalized chunk language codes, bounded language probabilities, and the count/mean/minimum of available word log probabilities. No words, hints or provider payloads are logged. These are diagnostic signals, not measured transcription accuracy or automatic retry thresholds. `backend.transcription_started` also records ingestion mode and hint count. Combined analysis emits one stage duration; configured per-feature costs remain estimates, with readings produced by the same selected AI model. Hinted transcription estimates include the provider's documented 20% surcharge.

## Traces

Subtitle workflow traces are persisted in `subtitle_job_events` and mirrored into structured logs. Trace context is scalar and sanitized only; it must not include transcripts, cue text, token text, prompts, translations, romanization, raw provider payloads, raw audio paths, provider secrets, or install IDs.

## Future Harness Targets

- Local log query command.
- Metrics query command.
- Trace inspection notes.
- Per-worktree ephemeral observability stack when the app needs it.
