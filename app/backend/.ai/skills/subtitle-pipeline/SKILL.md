---
name: subtitle-pipeline
description: Project-specific rules for subtitle acquisition, transcription, enrichment, and Laravel AI/OpenAI provider usage.
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
- Use Laravel AI SDK provider identity and primitives where they fit; add narrow provider requests only for capabilities the SDK wrapper does not expose.

## Current Provider Choices

- Use ElevenLabs Scribe v2 for speech-to-text and word timestamps.
- Use `Laravel\Ai\Enums\Lab::ElevenLabs` for Scribe provider identity in transcription logs/config.
- Use `Laravel\Ai\Enums\Lab::OpenAI` for structured cue tokenization, the merged tokenize-and-translate batch when translation is requested, optional romanization, full-track word-card enrichment, and clicked-token word-card generation.
- Use narrow Laravel HTTP requests for Scribe while keeping the provider boundary behind `TranscriptionService`.
- Use `config/ai.php` for provider keys, custom base URLs, and model defaults.
- Normalize Scribe responses to the stored field allowlist before chunk artifacts are written, and recheck the job/run state after a provider call before persisting its result.
- Prefer job-level stage logs for current transcription observability.

## Deferred SDK Features

Reconsider current Laravel AI/OpenAI SDK options before implementing any custom equivalent:

- Agents and prompting for token analysis, romanization, glosses, dialect labels, and confidence.
- Structured output for typed generated subtitle enrichment.
- Conversation context only if the product adds user learning history, preferences, or tutoring.
- SDK queueing only if it can preserve product-owned raw audio cleanup and subtitle job state.
- Files, vector stores, embeddings, and reranking for glossary retrieval or prior-subtitle search.
- Provider tools, web search, and web fetch only when explicitly required by a product feature.
- Provider/model failover only after more than one provider is supported.
- SDK transcription events only if job-level logs stop being sufficient.
