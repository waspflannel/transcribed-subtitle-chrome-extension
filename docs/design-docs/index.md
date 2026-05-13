# Design Docs Index

Use this directory for durable decisions and design history.

## Core Files

- `core-beliefs.md`: Agent-first operating principles for this project.
- `../product-specs/index.md`: Current product workflow and first-release scope.
- `../../ARCHITECTURE.md`: Current runtime architecture and validation targets.
- `../exec-plans/completed/2026-05-11-many-to-many-language-refactor.md`: Refactor record for selectable source and target languages.

## Decision Records

Add short decision files here when choices affect future agents:

```text
YYYY-MM-DD-short-title.md
```

Each decision should include context, decision, consequences, validation expectations, and links to affected plans or specs.

## Current Durable Decisions

- The product is YouTube-only for the first release.
- Supported videos are public YouTube videos only.
- Backend audio acquisition is the first implementation path.
- Backend stack is Laravel.
- Extension stack is WXT and TypeScript.
- Laravel AI SDK is the preferred AI provider identity and enrichment primitive layer.
- Users select the subtitle/source language for transcription, or Auto detect.
- Users select the translation/target language for word cards.
- ElevenLabs Scribe is the transcription provider for the current proof.
- OpenAI/Laravel AI structured output is used for mandatory cue tokenization, optional non-Latin-script romanization, and target-language word-card enrichment.
- Token boundary intelligence belongs in the tokenizer agent; backend token validation is limited to output shape and source-order safety.
- Laravel Boost is required development tooling after the Laravel app is scaffolded.
- Contracts are schema-first and shared across Laravel and TypeScript.
- Completed tracks are retained for 30 days.
- Raw audio is deleted immediately after processing.
