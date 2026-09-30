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
