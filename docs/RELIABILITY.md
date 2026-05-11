# Reliability

## Reliability Expectations

- Define startup, shutdown, retry, timeout, and idempotency behavior before production use.
- Make failure modes observable through logs, metrics, traces, or explicit error states.
- Prefer deterministic checks over manual inspection.

## Startup And Runtime

- Document the expected app start command when a stack exists.
- Add a startup smoke check to `scripts/agent/check.ps1`.
- Track startup targets and performance budgets here.

## Failure Handling

For each critical workflow, define:

- Expected failures.
- User-visible behavior.
- Retry or rollback behavior.
- Signals emitted for debugging.

Subtitle generation remains synchronous. Expected backend failures include unsupported YouTube URLs, videos over 60 minutes, non-public or unavailable videos, audio acquisition command failures, invalid language catalog codes, missing ElevenLabs/OpenAI configuration, provider timeouts, malformed Scribe word output, and unusable cue timing or empty cue text. User-visible API responses use stable error codes; raw audio cleanup runs in `finally` after successful transcription, provider failure, and thrown exceptions. Automatic retry is intentionally absent in this proof slice; users can submit the generation request again after fixing configuration or choosing a supported public video. Structured logs identify the failed stage without dumping raw audio paths, cue text, prompts, or full transcripts.

Extension playback sync is local and browser-native. It attaches generated WebVTT as a hidden `TextTrack`, listens for `cuechange`, clears the overlay when no cue is active, and logs diagnostics instead of trying to auto-correct track drift.

Provider queueing and failover are intentionally deferred for this proof. ElevenLabs is the only transcription provider; OpenAI is used only for romanization and word-card generation. Revisit framework or SDK-native queueing before adding asynchronous transcription, but keep product-owned job state and raw audio cleanup explicit.

Default generation persists a subtitle-focused track even if romanization fails. Full word-card mode fails if batch enrichment fails. Same-language source/target requests skip translation enrichment and persist transcript text as the translated text. On-click token enrichment caches successful metadata by token/context/language/model and patches the stored track for the remaining 30-day track lifetime.

Supported languages are product-tuned first, but both Supported and Experimental catalog languages can vary in transcription and translation quality by language, dialect, audio quality, and provider coverage.

Phase 07 release hardening keeps the synchronous request path. Compatible completed tracks are reused, incomplete compatible jobs are reused for retry instead of creating duplicate rows, and Laravel route throttling enforces both per-install and per-IP limits. Public failures map to stable popup and overlay messages.

Generated tracks expire after 30 days. The scheduled `subtitles:prune-expired` command deletes expired tracks and their now-empty expired jobs daily; extension requests also ignore expired tracks and regenerate through the existing compatible job row.

The popup local clear-state action removes local extension settings and anonymous install ID, clears in-memory tab subtitle state, and republishes default settings/no-track state to the active YouTube tab. It does not delete backend tracks because the first release has no user account or ownership model.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
