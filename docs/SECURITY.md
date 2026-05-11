# Security

## Security Baseline

- Keep secrets out of the repository.
- Document environment variables in `.env.example` when they are introduced.
- Validate and sanitize external inputs.
- Use least-privilege credentials and scoped tokens.
- Keep authentication and authorization boundaries explicit.

## Threat Model

- Assets:
  - Backend provider credentials and AI configuration.
  - Anonymous extension install IDs.
  - YouTube video URLs and IDs submitted by the extension.
  - Temporary raw audio files during processing.
  - Provider request/response data, including language detection output, transcripts, translations, and token metadata.
  - Persisted generated subtitle tracks retained for 30 days.
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
  - SQLite persistence for generated tracks.
- Sensitive operations:
  - Validating supported public YouTube watch URLs and 60 minute duration limits.
  - Writing and deleting temporary audio files.
  - Sending video-derived audio/text to configured AI services.
  - Persisting generated WebVTT and cue/token learning data.
  - Applying install-ID and IP rate limits.
  - Returning public errors and request IDs without exposing internals.
- Abuse cases:
  - Repeated generation requests to exhaust provider quota or local CPU/disk.
  - Forged install IDs to bypass per-install throttles.
  - Private, live, playlist, malformed, or over-long YouTube inputs.
  - Provider failures returning malformed WebVTT or malformed enrichment JSON.
  - Log leakage of raw audio paths, prompts, transcripts, translations, or provider secrets.
  - Stale generated tracks retained beyond the 30-day window.
- Audit signals:
  - `backend.proxy_invalid_install_id`
  - `backend.proxy_rate_limited`
  - `backend.subtitle_job_created`
  - `backend.subtitle_job_reused_for_retry`
  - `backend.audio_acquisition_started`
  - `backend.audio_acquisition_completed`
  - `backend.audio_acquisition_failed`
  - `backend.transcription_started`
  - `backend.transcription_completed`
  - `backend.transcription_failed`
  - `backend.enrichment_started`
  - `backend.enrichment_completed`
  - `backend.enrichment_failed`
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

- Provider keys stay only in Laravel environment/config.
- Extension code must never call AI providers directly.
- Raw audio is temporary and must be deleted after processing succeeds or fails.
- YouTube audio acquisition writes only to controlled backend temporary storage.
- Logs must not include secrets, raw audio, full prompts, or full transcripts by default.
- Extension-facing requests must be validated against canonical contracts before product endpoints are exposed.
- Phase 03 `/v1/*` API routes require `X-Extension-Install-Id`, throttle by anonymous install ID and IP, and return stable public error objects.
- Phase 05 transcription uses a backend-only OpenAI WebVTT adapter with backend-held OpenAI credentials and returns stable public errors for acquisition and transcription failures.
- Phase 06 enrichment uses a backend-only Laravel AI SDK OpenAI structured-output agent, validates generated learning metadata before storage, omits missing fields instead of exposing `null`, and returns stable `enrichment_failed` public errors.
- Phase 07 adds request IDs to extension-facing API errors, logs invalid install IDs and rate limits without raw install IDs, configures final install/IP throttle defaults, and schedules expired generated subtitle cleanup.
