# Generated Contracts

Created: 2026-04-28

Canonical contracts live in `packages/contracts`.

## Source Files

- OpenAPI: `packages/contracts/openapi.json`
- JSON Schema: `packages/contracts/schemas/*.schema.json`
- TypeScript declarations: `packages/contracts/dist/index.d.ts`

## Validation Path

Run from the repository root:

```powershell
Push-Location .\packages\contracts
npm run check
Pop-Location
```

The contract check:

- validates `openapi.json`
- compiles every JSON Schema
- validates fixture payloads
- regenerates TypeScript declarations

## Consumer Rules

- Laravel treats these schemas as the extension-facing boundary. Future request validation and API resources must conform to these shapes.
- The WXT extension imports generated contract types through `app/extension/utils/contracts.ts`.
- Provider-native objects, Eloquent models, queue payloads, and UI state are internal and must not become API contracts.
