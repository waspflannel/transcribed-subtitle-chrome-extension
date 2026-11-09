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

## Review simplification

The extension background has separate generation, panel, corrections and state modules. The fixed backend instance ID no longer partitions local tracks or operations. Install-session and operation ownership checks still protect asynchronous work.

Provider cost estimates and their database column are removed. Metrics report timing, queue waits and first-cue availability. Stage events and errors remain; redundant queue lifecycle trace rows are removed. Trace inserts use a nested transaction/savepoint so a best-effort failure cannot abort an outer Postgres transaction.

The migration drops inert users, billing_usage_events, stripe_webhook_events, personal_access_tokens and password_reset_tokens. No application code references these tables. Back up before migration: restoring these records requires a database backup. Metadata prefetch and its default-off flag are removed. Acquisition fetches fresh metadata. Legacy queued job reads remain for upgrade recovery; correction queued status is live.

## Saved-output accuracy evaluation

`php artisan subtitles:eval` scores saved tracks or cached transcripts against a JSON gold set. WER/CER and token-boundary scores run offline. Optional translation judging requires `--judge`. No API contract or environment setting changes. See `docs/evals/subtitle-accuracy.md`.
