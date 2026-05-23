# Observability

## Goal

Make application state legible to agents and humans through inspectable signals.

## Current State

The backend records persisted subtitle job, artifact, and track rows, acquires YouTube audio into temporary storage, deletes raw audio after processing succeeds or fails, requests ElevenLabs Scribe word timestamps, normalizes words and provider-detected language codes into timed segments and WebVTT cue drafts, tokenizes cues through OpenAI/Laravel AI structured output with structural/source-order validation, fails visibly when tokenization cannot produce usable tokens, optionally romanizes cues, optionally translates cues, optionally enriches existing tokens, returns stable public status/error states with request IDs, throttles by install ID and IP, and prunes expired generated subtitles.

Billing state is inspectable through local user subscription fields, idempotent Stripe webhook event rows, and append-only usage ledger rows for monthly grants, reservations, debits, refunds, and support adjustments. `php artisan billing:usage-report --json` summarizes public minutes and provider-cost telemetry by plan.

The SaaS website uses first-party Laravel structured logs for beta funnel analytics. `analytics.marketing_page_view`, `analytics.signup_completed`, `analytics.checkout_started`, `analytics.extension_connected`, `analytics.subtitle_generation_started`, `analytics.first_generation_started`, and `analytics.retention_generation_started` capture route/page, hashed user/install identifiers, plan, language pair, feature flags, and public timing/usage scalars only. These analytics logs intentionally exclude transcripts, prompts, generated subtitle text, YouTube URLs, provider payloads, bearer tokens, raw install IDs, and raw audio paths.

## Logging

- Prefer structured logs.
- Include request or operation identifiers when workflows span boundaries.
- Log enough context to explain failures without leaking secrets.
- For subtitle processing, use public job IDs in logs rather than install IDs, raw transcript text, audio paths, prompts, or provider secrets.
- Audio acquisition and transcription emit stage-specific start, completed, and failed log events with public job ID, video ID, requested source language, detected source language when available, target language, duration, byte count, provider identity, model name, adapter name, segment count, and stable error code where available.
- Track generation emits cue count, track duration, audio duration, expiration, reuse, and duration mismatch events without logging cue text or full transcript payloads.
- Tokenization, romanization, cue translation, full-card enrichment, and clicked-token enrichment emit started/completed/failed events with provider identity, model, cue/token counts where applicable, and stored dialect value where applicable without logging prompts, full transcripts, translations, token boundaries, romanizations, or token payloads.
- Queue wait, transcription, cue-batch processing, continuation, finalization, and total completed-track duration emit sanitized timing logs: `backend.subtitle_queue_wait_observed`, `backend.subtitle_stage_timing`, and `backend.subtitle_completed_track_timing`.
- Generation optimization emits sanitized internal events for provider cost estimates, generation admission rejections, account AI batch concurrency delays, and performance budget checks: `provider.cost_estimated`, `backend.generation_concurrency_rejected`, `queue.concurrency_delayed`, `performance.budget_checked`, and `performance.budget_exceeded`. Concurrency context distinguishes real tier cap hits (`limit_reached`) from limiter lock contention (`lock_timeout`) and includes queue family, limiter type, tier, configured limiter cache store when applicable, active count when observed, limit, and release delay.
- Billing events are persisted in `billing_usage_events` rather than subtitle trace rows so support can audit grants, reservations, debits, refunds, adjustments, and provider-cost totals without reading transcripts or provider payloads.
- Subtitle runtime tracing persists sanitized `subtitle_job_events` rows and emits `backend.subtitle_trace_event` logs with stable event names for job creation/reset/completion/failure, stale run skips, queue processing/processed/failed, batch lifecycle, stage start/completion/slow warnings, and artifact read/write/delete.
- Each generation has a `run_id`; queued subtitle work carries the run ID and stale queued payloads no-op before provider calls or artifact writes, with `job.stale_run_skipped` trace evidence.
- Local diagnostics are available through `php artisan subtitles:runtime-check`, `php artisan subtitles:runtime`, `php artisan subtitles:trace <public-job-id>`, `php artisan subtitles:slow`, and `php artisan subtitles:metrics`; all support `--json`. Runtime output reports generation and batch queue family depths separately plus resolved worker-group configuration.
- Queue worker auto-start attempts emit `backend.subtitle_worker_auto_start_checked`, `backend.subtitle_worker_auto_started`, `backend.subtitle_worker_auto_start_skipped`, and `backend.subtitle_worker_auto_start_failed` logs with worker-group names, queue names, target/running/started counts, PIDs when available, and no install IDs or transcript content.
- Tokenization validation retries emit `backend.tokenization_batch_retried` with model, source language, cue count, and reason only; no transcript or token payloads are logged.
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

## Traces

Subtitle workflow traces are persisted in `subtitle_job_events` and mirrored into structured logs. Trace context is scalar and sanitized only; it must not include transcripts, cue text, token text, prompts, translations, romanization, raw provider payloads, raw audio paths, provider secrets, or install IDs.

## Future Harness Targets

- Local log query command.
- Metrics query command.
- Trace inspection notes.
- Per-worktree ephemeral observability stack when the app needs it.
