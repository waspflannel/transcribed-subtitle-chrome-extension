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

Subtitle generation remains synchronous. Expected backend failures include unsupported YouTube URLs, videos over 60 minutes, non-public or unavailable videos, audio acquisition command failures, invalid language catalog codes, missing ElevenLabs/OpenAI configuration, provider timeouts, malformed Scribe word output, and unusable cue timing or empty cue text. User-visible API responses use stable error codes; raw audio cleanup runs in `finally` after successful transcription, provider failure, and thrown exceptions. Cross-request automatic retry is intentionally absent in this proof slice; users can submit the generation request again after fixing configuration or choosing a supported public video. Structured logs identify the failed stage without dumping raw audio paths, cue text, prompts, or full transcripts.

Extension playback sync is local and browser-native. It attaches generated WebVTT as a hidden `TextTrack`, listens for `cuechange`, clears the overlay when no cue is active, and logs diagnostics instead of trying to auto-correct track drift.

Provider failover is intentionally deferred for this proof. ElevenLabs is the only transcription provider; OpenAI is used for cue tokenization, optional romanization, full-track word-card enrichment, and clicked-token word-card generation. The backend is synchronous and carries no queue infrastructure; add asynchronous processing only after real runtime evidence shows the request path is insufficient.

Default generation tokenizes every transcript with a narrow structured-output tokenizer agent. ElevenLabs Scribe word output is converted directly into timed segments and WebVTT, with provider-created character spacing collapsed for no-space scripts before display or tokenization. The tokenization prompt includes previous/current/next cue text; token boundary decisions live in the agent, while backend validation only checks cue ID/index, sequential token indexes, non-empty lexical token text, and source-order boundary safety. Failed cues retry once with `OPENAI_TOKENIZATION_RETRY_MODEL`; valid first-pass cues are not retried. If retry still fails, generation fails with a stable public error instead of storing tokenless cues. Romanization is optional per request; when enabled for non-Latin-script tracks, invalid romanization output fails generation instead of silently dropping pronunciation metadata. Full word-card mode enriches tokenized cues and must preserve token count, indexes, and text. Same-language source/target requests skip translation enrichment and persist transcript text as the translated text. On-click token enrichment caches successful metadata by token/context/language/model/cache-version and patches the stored track for the remaining 30-day track lifetime.

The language catalog is limited to the WER-ranked transcription set used in the popup. The tier is a transcription accuracy signal only; translation card quality can still vary by language pair, dialect, audio quality, and provider coverage.

Phase 07 release hardening keeps the synchronous request path. Compatible completed tracks are reused, incomplete compatible jobs are reused for retry instead of creating duplicate rows, and Laravel route throttling enforces both per-install and per-IP limits. Public failures map to stable popup and overlay messages.

Generated tracks expire after 30 days. The scheduled `subtitles:prune-expired` command deletes expired tracks and their now-empty expired jobs daily; extension requests also ignore expired tracks and regenerate through the existing compatible job row.

The popup local clear-state action removes local extension settings and anonymous install ID, clears in-memory tab subtitle state, and republishes default settings/no-track state to the active YouTube tab. It does not delete backend tracks because the first release has no user account or ownership model.

## Future Harness Targets

- Local app startup per worktree.
- Health check command.
- Critical journey timing checks.
- Build failure remediation notes.
