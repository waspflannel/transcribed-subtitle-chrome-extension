# Security

Vocabulary hints were removed on 2026-09-14. New transcription requests contain no user-provided keyterms. Job and transcript processing versions prevent reuse of old hinted output for new generations; saved tracks belong to the private instance. Public-audio transcript caches distinguish ingestion mode and contain no user-provided vocabulary.

## Security Baseline

- Pasted lyrics are validated before framework trimming or queueing: at most 25,000 raw Unicode code points, at least one Unicode letter, no C0/C1 controls except tab/LF/CR, and no HTTP(S)/www link-only paste. Script joiners and combining marks remain valid. The panel mirrors the checks; the backend is authoritative.
- Replacement, polling and cancellation retain install/IP limits. Provider concurrency and request-rate controls protect the configured instance and upstream service. Alignment receives JSON data with explicit untrusted-text instructions and no tools; known ordered cue references and complete in-range pasted-part consumption are enforced in code before analysis. Prompt instructions and junk checks do not guarantee semantic prompt-injection resistance.

- Keep secrets out of the repository.
- Document environment variables in `.env.example` when they are introduced.
- Validate and sanitize external inputs.
- Use provider credentials scoped to the intended instance.
- Keep the private-instance network and origin boundary explicit; install IDs are diagnostics and rate-limit signals, not authentication.

## Private-instance boundary

- One instance is one trusted workspace. Anyone who can reach it can manage its keys, jobs and saved generations. There are no accounts or per-user isolation.
- Website routes are read-only and do not start server sessions or share session validation errors. Locale cookies remain encrypted. Laravel origin-only request-forgery protection avoids session CSRF tokens; legacy login/session cookies never resolve a user model.
- `RequirePrivateInstance` allows only configured IP networks (loopback by default), configured/loopback hosts, and same-origin or Chrome-extension origins. Do not expose the service directly to the public internet. Remote users need a trusted private network or a separately managed access boundary.
- Browser requests cannot choose arbitrary provider endpoints. API keys stay in the backend after setup; the extension calls only the instance API. Settings validation rejects unknown providers/fields and control characters.
- Saved-track retention defaults to no expiry. Updating retention changes existing tracks and completed jobs from their generation timestamp; temporary audio, transcript caches, artifacts and failed-job cleanup remain bounded independently.

## Threat Model

- Assets:
  - Backend provider credentials and AI configuration.
  - Anonymous extension install IDs.
  - YouTube video URLs and IDs submitted by the extension.
  - Temporary raw audio files during processing.
  - Provider request/response data, including language detection output, transcripts, translations, and token metadata.
  - Persisted generated subtitle tracks retained until deletion or the optional configured deadline.
  - Pasted lyrics and intermediate correction work state, both encrypted at rest and cleared on completed, failed, expired, or deleted attempts.
- Actors:
  - Language learner using the extension.
  - Malicious or buggy extension/client sending API requests.
  - Public YouTube and `yt-dlp` as external media/metadata sources.
  - AI provider APIs used by the Laravel backend.
  - Local developer/operator running the backend.
- Trust boundaries:
  - YouTube page and URL state entering the content script.
  - Extension messages between popup, background worker, and content script.
  - Extension-to-Laravel `/v1/*` API requests.
  - Laravel-to-YouTube audio acquisition process.
  - Laravel-to-AI-provider transcription and enrichment calls.
  - Postgres persistence for generated tracks, subtitle jobs, artifacts, failed jobs, cache rows, and Laravel batch metadata in the runtime profile.
  - Sanitized subtitle runtime trace rows for queue, batch, timing, and failure diagnostics.
  - SQLite persistence only inside PHPUnit's isolated in-memory test profile.
- Sensitive operations:
  - Validating supported public YouTube watch or Shorts URLs and positive video duration metadata.
  - Writing and deleting temporary audio files.
  - Sending video-derived audio/text to configured AI services.
  - Persisting generated WebVTT and cue/token learning data.
  - Applying install-ID and IP rate limits.
  - Returning public errors and request IDs without exposing internals.
- Abuse cases:
  - Repeated generation requests to exhaust provider quota or local CPU/disk.
  - Forged install IDs to bypass per-install throttles.
  - Private, live, playlist, malformed or unsupported YouTube inputs.
  - Provider failures returning malformed WebVTT or malformed enrichment JSON.
  - Log leakage of raw audio paths, prompts, transcripts, translations, or provider secrets.
  - Diagnostic trace leakage of generated cue/token content or anonymous install IDs.
- Audit signals:
  - `backend.proxy_invalid_install_id`
  - `backend.proxy_rate_limited`
  - `backend.subtitle_job_created`
  - `backend.subtitle_job_reused_for_retry`
  - `backend.audio_acquisition_started`
  - `backend.audio_acquisition_completed`
  - `backend.audio_acquisition_failed`
  - `backend.audio_preparation_started`
  - `backend.audio_preparation_completed`
  - `backend.transcription_started`
  - `backend.transcription_completed`
  - `backend.transcription_failed`
  - `backend.tokenization_started`
  - `backend.tokenization_batch_retried`
  - `backend.tokenization_completed`
  - `backend.romanization_started`
  - `backend.romanization_completed`
  - `backend.translation_started`
  - `backend.translation_completed`
  - `backend.translating_failed`
  - `backend.enrichment_started`
  - `backend.enrichment_completed`
  - `backend.enrichment_failed`
  - `backend.subtitle_queue_wait_observed`
  - `backend.subtitle_stage_timing`
  - `backend.subtitle_completed_track_timing`
  - `backend.subtitle_trace_event`
  - `provider.cost_estimated`
  - `backend.queue_job_processing`
  - `backend.queue_job_processed`
  - `backend.queue_job_failed`
  - `backend.track_generation_completed`
  - `backend.track_reused`
  - `backend.expired_subtitles_pruned`

## Agent Expectations

- Do not invent security assumptions.
- Check dependency and framework docs when implementing security-sensitive behavior.
- Add tests for authorization, validation, and unsafe input handling.

## Laravel Security Skill

When implementation touches Laravel API inputs, install IDs, rate limits, provider keys, raw audio, transcripts, generated tracks, logs, CORS, or deployment hardening, load:

```text
app/backend/.ai/skills/laravel-security/SKILL.md
```

Use `laravel-security` as a required review lens for those changes. Project guardrails still win over generic examples inside the skill.

Project-specific security defaults:


- Provider keys are configured in the Laravel environment or submitted to the private settings API and stored with an encrypted Eloquent cast. GET/PUT responses expose only configured status and model names. Empty key fields preserve keys; explicit null disables them even when an environment key exists. Never log, flash, serialize or return key values. Long-running workers reload settings and forget cached SDK providers when keys/models change.
- Extension code must never call AI providers directly.
- Raw audio is temporary and must be deleted after processing succeeds or fails.
- YouTube audio acquisition writes only to controlled backend temporary storage.
- Logs must not include secrets, raw audio, full prompts, or full transcripts by default.
- Runtime trace rows must stay scalar and sanitized; do not persist transcripts, cue text, token text, prompts, translations, romanization, raw provider payloads, raw audio paths, provider secrets, or install IDs.
- Cost telemetry stores configured unit-price estimates and safe provider usage units only. It must not store raw usage payloads, provider responses, prompts, transcripts, translations, or token text.
- Extension-facing requests must be validated against canonical contracts before product endpoints are exposed.
- Extension API requests require `X-Extension-Install-Id`, install/IP throttles, and stable public error objects.
- Stored Scribe chunk artifacts use a field allowlist (`language_code`, word text/type/timing) rather than raw provider payloads. Running job callbacks recheck job/run state before persistence.
- Full pasted lyrics and their aligned cues are sent to the generation’s saved OpenAI or Cerebras model for alignment and parallel analysis.
- Quick Fix and on-demand cards use the saved generation provider/model.
- Quick fix replacement text is sent to the AI provider by the private instance backend to refresh the edited cue. Required provider credentials and current job/track state are checked before provider work and publication. Replacement text and generated learning data remain excluded from logs and diagnostics.
- Lyrics and work state are excluded from logs, traces, analytics, API responses, extension storage, URLs, and runtime error payloads.
- Encrypted database columns are the only persisted private copies, and they are cleared on completion, failure, expiry, or deletion.
  - Laravel responses set CSP, frame, MIME, referrer, permissions, and cross-origin isolation headers. HSTS is sent only for secure production requests; trusted-proxy and locale-cookie configuration remain an operator-owned hosting decision.
- Transcription uses backend-only ElevenLabs Scribe word timestamps, normalized into local WebVTT. OpenAI WebVTT transcription is historical.
- Analysis uses backend-only Laravel AI SDK structured output with the saved OpenAI/Cerebras selection, validates generation metadata before storage, and returns stable public errors.
- Phase 07 adds request IDs to extension-facing API errors, logs invalid install IDs and rate limits without raw install IDs, configures final install/IP throttle defaults, and schedules expired generated subtitle cleanup.
- Private hosting uses `php artisan ops:production-check` and the operations runbook to verify disabled debug output, a configured encryption key, Postgres, Redis, workers and safe logs. Loopback HTTP is allowed; remote instance URLs require HTTPS. Provider keys can be configured after installation and are checked before generation.

## Remediation controls (2026-09-15)

- Provider adapters discard raw exception causes before ordinary reporting and failed-job storage. Only safe class, status, allowlisted quota code and validated request ID remain. Exception arguments are disabled at bootstrap so stack traces cannot retain prompts or credentials.
- Shared actual-call permits cover provider concurrency and request rate, including retries and synchronous interactive requests. Cached data avoids unnecessary provider work. Cancellation stops unsent work where possible; requests already sent can still incur provider charges.
