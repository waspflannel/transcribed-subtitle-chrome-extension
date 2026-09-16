# Security

Vocabulary hints were removed on 2026-09-14. New transcription requests contain no user-provided keyterms. Job and transcript processing versions prevent reuse of old hinted output for new generations; saved tracks remain owner-scoped. Public-audio transcript caches distinguish ingestion mode and contain no user-provided vocabulary.

## Security Baseline

- Pasted lyrics are validated before framework trimming or queueing: at most 25,000 raw Unicode code points, at least one Unicode letter, no C0/C1 controls except tab/LF/CR, and no HTTP(S)/www link-only paste. Script joiners and combining marks remain valid. The panel mirrors the checks; the backend is authoritative.
- Replacement POST has a five-per-minute authenticated-account limit across devices/IPs alongside existing install/IP limits. Polling and cancellation use their existing limits. Alignment receives JSON data with explicit untrusted-text instructions and no tools; known ordered cue references and complete in-range pasted-part consumption are enforced in code before analysis. Prompt instructions and junk checks do not guarantee semantic prompt-injection resistance.

- Keep secrets out of the repository.
- Document environment variables in `.env.example` when they are introduced.
- Validate and sanitize external inputs.
- Use least-privilege credentials and scoped tokens.
- Keep authentication and authorization boundaries explicit.

## Threat Model

- Assets:
  - Backend provider credentials and AI configuration.
  - Stripe API keys, webhook signing secret, customer IDs, subscription IDs, billing plan state, and usage ledger rows.
  - SaaS user accounts, password reset tokens, email verification state, and scoped Sanctum extension API tokens.
  - Anonymous extension install IDs.
  - YouTube video URLs and IDs submitted by the extension.
  - Temporary raw audio files during processing.
  - Provider request/response data, including language detection output, transcripts, translations, and token metadata.
  - Persisted generated subtitle tracks retained for 30 days.
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
  - Extension bearer tokens stored in browser extension storage and attached by the background worker.
  - Laravel-to-YouTube audio acquisition process.
  - Laravel-to-AI-provider transcription and enrichment calls.
  - Postgres persistence for generated tracks, subtitle jobs, artifacts, failed jobs, cache rows, and Laravel batch metadata in the runtime profile.
  - Sanitized subtitle runtime trace rows for queue, batch, timing, and failure diagnostics.
  - SQLite persistence only inside PHPUnit's isolated in-memory test profile.
- Sensitive operations:
  - Validating supported public YouTube watch or Shorts URLs and 60 minute duration limits.
  - Writing and deleting temporary audio files.
  - Sending video-derived audio/text to configured AI services.
  - Persisting generated WebVTT and cue/token learning data.
  - Applying install-ID and IP rate limits.
  - Issuing, expiring, and revoking scoped Sanctum extension API tokens for authenticated users. Email verification is not currently a gate.
  - Applying server-side generation tier, queue priority, account generation concurrency, account AI batch concurrency, and cost telemetry without trusting client-provided entitlements.
  - Verifying Stripe webhook signatures before mutating subscription or usage state.
  - Enforcing active billing, current-period minute balance, feature gates, and concurrency at generation admission. Already-admitted jobs retain their reservation and may run after entitlement changes; corrections and uncached cards recheck entitlement.
  - Returning public errors and request IDs without exposing internals.
- Abuse cases:
  - Repeated generation requests to exhaust provider quota or local CPU/disk.
  - Forged install IDs to bypass per-install throttles.
  - Private, live, playlist, malformed, or over-long YouTube inputs.
  - Provider failures returning malformed WebVTT or malformed enrichment JSON.
  - Log leakage of raw audio paths, prompts, transcripts, translations, or provider secrets.
  - Diagnostic trace leakage of generated cue/token content or anonymous install IDs.
  - Public clients spoofing paid-tier queue priority before authenticated entitlements exist.
  - Forged or replayed Stripe webhooks changing subscription state without signature verification or idempotency.
  - Users starting more provider work than their active plan, remaining minutes, or concurrency allowance permits.
  - Missing, expired, revoked, or wrong-user extension tokens attempting to access jobs, tracks, or enrichment.
  - Stale generated tracks retained beyond the 30-day window.
- Audit signals:
  - `backend.proxy_invalid_install_id`
  - `extension.account_login_completed`
  - `extension.account_logout_completed`
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
  - `queue.concurrency_delayed`
  - `backend.generation_queue_full_rejected`
  - `performance.budget_checked`
  - `performance.budget_exceeded`
  - `backend.queue_job_processing`
  - `backend.queue_job_processed`
  - `backend.queue_job_failed`
  - `backend.track_generation_completed`
  - `backend.track_reused`
  - `backend.expired_subtitles_pruned`
  - `billing.monthly_grant`
  - `billing.usage_reserved`
  - `billing.usage_debited`
  - `billing.usage_refunded`
  - `billing.support_adjusted`
  - `stripe.webhook_processed`

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
- Runtime trace rows must stay scalar and sanitized; do not persist transcripts, cue text, token text, prompts, translations, romanization, raw provider payloads, raw audio paths, provider secrets, or install IDs.
- Generation tier is server-side configuration until account auth exists; do not accept tier or entitlement from anonymous extension payloads.
- Cost telemetry stores configured unit-price estimates and safe billing units only. It must not store raw usage payloads, provider responses, prompts, transcripts, translations, or token text.
- Stripe webhook handlers must verify `Stripe-Signature`, record event IDs idempotently, and store only subscription/customer identifiers plus safe scalar billing metadata.
- Stripe customer and checkout creation use stable idempotency keys. Webhook mutations must match the current subscription identity and handle equal-second events by documented event-type precedence.
- Usage ledger rows store public generated-video minute units and provider cost estimates separately; they must not store transcripts, prompts, raw provider payloads, card data, or Stripe secrets.
- Extension-facing requests must be validated against canonical contracts before product endpoints are exposed.
- The original anonymous API hardening required `X-Extension-Install-Id`, install/IP throttles, and stable public error objects.
- SaaS Phase 03 `/v1/*` subtitle and learning-token routes require both `X-Extension-Install-Id` and a scoped Sanctum bearer token. Install ID remains a device/abuse signal; authenticated `user_id` is the ownership boundary.
- Tiered generation admission and AI batch concurrency use authenticated `user_id` as the owner and may log only hashed user IDs. Runtime trace rows must not store raw user IDs or install IDs.
- Extension login requires an authenticated email/password account; email verification is not enforced. It stores only the scoped Sanctum token plus safe account summary, and deletes the active token on logout. Production login requests must use HTTPS.
- Password reset rotates the remember token, revokes all scoped Sanctum tokens and database sessions, and marks the account so legacy web sessions without a password fingerprint are rejected. Protected web/Fortify routes compare the session password fingerprint with the current password. A cache miss for clicked-token enrichment requires active billing before the provider call; already-stored metadata remains readable to its owner.
- Stored Scribe chunk artifacts use a field allowlist (`language_code`, word text/type/timing) rather than raw provider payloads. Running job callbacks recheck job/run state before persistence.
- Full pasted lyrics and their aligned cues are sent to configured OpenAI/Luna for alignment and parallel analysis.
- Quick Fix and on-demand cards use the saved generation provider/model.
- Quick fix replacement text is sent to the AI provider by the owner-scoped backend to refresh the edited cue. Active entitlement is checked before provider work and publication. Replacement text and generated learning data remain excluded from logs and diagnostics.
- Lyrics and work state are excluded from logs, traces, analytics, API responses, extension storage, URLs, and runtime error payloads.
- Encrypted database columns are the only persisted private copies, and they are cleared on completion, failure, expiry, or deletion.
  - Laravel responses set CSP, frame, MIME, referrer, permissions, and cross-origin isolation headers. HSTS is sent only for secure production requests; trusted-proxy and session-cookie configuration remain an operator-owned hosting decision.
- SaaS website analytics are first-party structured logs only for beta. Analytics events must not include transcripts, prompts, generated subtitle text, YouTube URLs, provider payloads, bearer tokens, raw install IDs, raw audio paths, or account emails.
- Transcription uses backend-only ElevenLabs Scribe word timestamps, normalized into local WebVTT. OpenAI WebVTT transcription is historical.
- Analysis uses backend-only Laravel AI SDK structured output with the saved OpenAI/Cerebras selection, validates generation metadata before storage, and returns stable public errors.
- Phase 07 adds request IDs to extension-facing API errors, logs invalid install IDs and rate limits without raw install IDs, configures final install/IP throttle defaults, and schedules expired generated subtitle cleanup.
- Production hosting uses `php artisan ops:production-check` and the production runbook to verify `APP_DEBUG=false`, HTTPS `APP_URL`, environment-only backend secrets, disabled billing test switcher, exact extension API host permissions, and sanitized logs before paid beta traffic.

## Remediation controls (2026-09-15)

- Provider adapters discard raw exception causes before ordinary reporting and failed-job storage. Only safe class, status, allowlisted quota code and validated request ID remain. Exception arguments are disabled at bootstrap so stack traces cannot retain prompts or credentials.
- Registration and reset requests have configurable hourly IP and global bounds, in addition to login and reset-broker address throttles. Default registration limits are 5/IP and 100/global; reset limits are 10/IP and 200/global.
- Shared actual-call permits cover account/provider concurrency and request rate, including retries and synchronous interactive requests. Cache hits avoid paid work. R07's September 16 policy additionally consumes the full reserved minutes on voluntary cancellation/deletion after paid work begins; it adds no separate daily allowance.
- Account deletion retention is unchanged. No new persistent abuse identity is retained after deletion. Cancellation before paid generation admission and ordinary failed generations restore their unsettled reservation. The early-cancellation exception is not advertised; Generate warns that cancellation does not refund minutes.
