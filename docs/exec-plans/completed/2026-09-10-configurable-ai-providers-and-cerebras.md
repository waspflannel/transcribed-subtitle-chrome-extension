# Plan: Configurable AI providers and Cerebras

Status: completed
Owner: agent
Created: 2026-09-10
Last updated: 2026-09-10

## Goal

Select the backend text provider and models through environment configuration, supporting OpenAI and Cerebras on branch `codex/flexible-ai-providers`.

## Scope

All seven structured agents, provider diagnostics, word-card cache identity, estimated costs, readiness and evaluation commands. ElevenLabs transcription and extension contracts remain outside this change.

## Acceptance Criteria

- [x] OpenAI remains the default for existing deployments.
- [x] Cerebras has separate credentials and default/per-task model settings.
- [x] All agents use the selected provider without sending OpenAI-only options to Cerebras.
- [x] Costs, workflow logs and word-card cache keys use the selected identity.
- [x] A synthetic live Cerebras structured-output request succeeds.
- [x] Targeted tests and repository checks pass.

## Decisions

- Applied ponytail, Laravel best practices, AI SDK, security and subtitle-pipeline skills. Reused SDK transport and kept secrets in ignored backend `.env`; no dependencies or extra workflow layers.
- Current Context7 docs describe a newer generic compatible driver which is absent from the installed SDK. Register the installed Groq Chat Completions transport as the `cerebras` driver using `Ai::extend`; it accepts Cerebras strict schemas, preserves Cerebras metadata and uses its own URL/key.
- `AI_PROVIDER` selects all text tasks. Provider-specific default models can be overridden for tokenization, analysis, romanization and enrichment. No automatic failover.
- Existing generated tracks and already-enriched tokens retain normal reuse semantics. A provider switch affects subsequent provider calls, not previously stored output. Word-card cache misses are isolated by provider and model.
- Existing OpenAI cost settings stay valid; Cerebras has separate optional estimate settings, initially zero. These remain configured estimates, not measured token billing.
- Readiness reports use `aiKeyConfigured` and `providers.ai` for the selected text provider.

## Validation

- Context7 Cerebras strict JSON schema and Laravel AI custom provider docs, plus installed SDK source.
- Live synthetic English-to-French CueAnalysisAgent call: Cerebras / qwen-3.8-27b, one structured cue returned. No user transcript sent.
- HTTP tests cover all seven agents, task models, strict schema, key separation, response parsing and rate limits.
- Regression coverage checks Cerebras readiness without an OpenAI key, provider-specific cost metadata/rates, and word-card cache separation.
- `php vendor/bin/pint --dirty --format agent` passed.
- `scripts/agent/check.ps1` passed: documentation/contracts checks, 492 backend tests (3387 assertions), extension tests, TypeScript compilation and production build.
- `git diff --check` passed.

## Completion Notes

Implemented and self-reviewed. No dependencies added. Key remains only in ignored backend `.env`; OpenAI remains the default. Cerebras live smoke passed with qwen-3.8-27b. Existing saved-output reuse and queue-drain requirements are documented in the runbook. No pending implementation work.

User follow-up: changed the Cerebras default, local model setting, and runbook to gpt-oss-120b. Live structured CueAnalysisAgent smoke succeeded with one cue on 2026-09-10.
Follow-up validation: Pint, git diff --check, and the full scripts/agent/check.ps1 passed.
