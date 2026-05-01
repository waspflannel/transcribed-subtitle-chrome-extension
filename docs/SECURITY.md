# Security

## Security Baseline

- Keep secrets out of the repository.
- Document environment variables in `.env.example` when they are introduced.
- Validate and sanitize external inputs.
- Use least-privilege credentials and scoped tokens.
- Keep authentication and authorization boundaries explicit.

## Threat Model

Fill this before handling real user data:

- Assets:
- Actors:
- Trust boundaries:
- Sensitive operations:
- Abuse cases:
- Audit signals:

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
- Phase 04 transcription uses backend-held OpenAI credentials and returns stable public errors for acquisition and transcription failures.
