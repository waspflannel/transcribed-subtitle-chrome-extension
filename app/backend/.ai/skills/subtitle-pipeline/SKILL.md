---
name: subtitle-pipeline
description: Project-specific rules for subtitle acquisition, transcription, enrichment, and backend-only AI provider usage.
origin: project
---

# Subtitle Pipeline

Use this skill whenever backend work touches subtitle jobs, YouTube audio acquisition, transcription, translation, Arabic learning data, generated tracks, or AI provider integrations.

## Hard Rules

- YouTube is the only supported audio source for this project until product requirements change.
- Extension code must never call AI providers directly; all provider secrets stay in Laravel environment/config.
- Raw audio must be written only to controlled backend temporary storage and deleted after success or failure.
- Logs must not include provider keys, raw audio paths, prompts, full transcripts, segment payloads, or generated learning content by default.
- Provider responses must be normalized before storage or extension exposure.
- Before adding AI infrastructure, check whether the current Laravel/OpenAI tooling already provides a simpler native primitive.

## Current Provider Choices

- Use the narrow OpenAI WebVTT transcription adapter for speech-to-text because the current sync path needs `response_format=vtt`.
- Use `config/ai.php` for provider keys, custom base URLs, and model defaults.
- Prefer job-level stage logs for current transcription observability.

## Deferred Provider Features

Reconsider current Laravel/OpenAI SDK options before implementing any custom equivalent:

- Agents and prompting for translation, token analysis, romanization, glosses, dialect labels, and confidence.
- Structured output for typed generated subtitle enrichment.
- Conversation context only if the product adds user learning history, preferences, or tutoring.
- Provider queueing only if it can preserve product-owned raw audio cleanup and subtitle job state.
- Files, vector stores, embeddings, and reranking for glossary retrieval or prior-subtitle search.
- Provider tools, web search, and web fetch only when explicitly required by a product feature.
- Provider/model failover only after more than one provider is supported.
- Provider transcription events only if job-level logs stop being sufficient.
