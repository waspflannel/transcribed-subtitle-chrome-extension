# Contracts

This package is the canonical boundary between the WXT extension and Laravel backend.

## Contents

- `openapi.json` defines the first API surface.
- `schemas/*.schema.json` defines the payload contracts.
- `dist/index.d.ts` is generated from JSON Schema for TypeScript consumers.
- The extension-facing API stays intentionally small: configure provider keys on a private backend, request subtitles for a YouTube video, and receive either the completed generated track or a stable API error.

## Commands

```powershell
npm install
npm run check
```

`npm run check` validates the OpenAPI document, compiles each JSON Schema, validates fixture payloads, and regenerates TypeScript declarations.

## Backend And Extension Wiring

- Laravel should validate extension-facing requests and responses against these schema shapes before exposing real endpoints.
- The extension should consume generated TypeScript declarations from `dist/index.d.ts` once runtime API calls are introduced.
- Provider responses, Eloquent models, and UI state must not replace these contracts.

Job status accepts optional nonnegative integer `sinceRevision`. Responses omit `partialTrack` when its revision equals the supplied value. Callers retain their previous partial track. Status and completed tracks are always returned.

## Status streams and generated validation

GET `/v1/subtitle-jobs/{jobId}/events` accepts the same installation header as polling. Each `update` SSE event contains the existing JobResponse JSON. Streams last at most 20 seconds and close on terminal status. Disable proxy buffering. The extension reconnects and falls back to polling on transport failure. Contract errors remain terminal. Persisted operations recover after worker suspension; streams are never the durable job owner.

Partial tracks are assembled from draft and analysis artifacts on read. Reads do not lock the job or store a preview. Revision, contiguous readyThroughMs and sinceRevision behavior stay the same.

Contracts build generates standalone validators beside the types. The extension runs these without eval or runtime Ajv imports. Schema changes must rebuild both artifacts. No new environment settings are required.
