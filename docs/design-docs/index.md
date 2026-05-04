# Design Docs Index

Use this directory for durable decisions and design history.

## Core Files

- `core-beliefs.md`: Agent-first operating principles for this project.
- `../../revamped-design-document.md`: Product pivot from caption extraction to YouTube AI subtitle generation.
- `../../detailed-design-document.md`: Current detailed implementation baseline.

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
- OpenAI transcription uses `Lab::OpenAI` plus the Whisper model through a narrow backend HTTP adapter when WebVTT output is required.
- Laravel Boost is required development tooling after the Laravel app is scaffolded.
- Contracts are schema-first and shared across Laravel and TypeScript.
- Completed tracks are retained for 30 days.
- Raw audio is deleted immediately after processing.
