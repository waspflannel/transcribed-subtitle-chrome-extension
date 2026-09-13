# Generated Contracts

Created: 2026-04-28

Canonical contracts live in `packages/contracts`.

## Source Files

- OpenAPI: `packages/contracts/openapi.json`
- Language catalog: `packages/contracts/languages.json`
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
- validates the language catalog and schema language enums
- validates fixture payloads
- regenerates TypeScript declarations

## Consumer Rules

- Laravel treats these schemas as the extension-facing boundary. Future request validation and API resources must conform to these shapes.
- `languages.json` is the canonical source for selectable languages. `auto` is source-only; target languages must be real catalog languages from the same WER-ranked transcription set.
- The WXT extension imports generated contract types through `app/extension/utils/contracts.ts`.
- Provider-native objects, Eloquent models, and UI state are internal and must not become API contracts.
- Cue token metadata supports optional `root` and `usageNote` fields. Missing learning fields are omitted from responses and UI rather than serialized as `null`.
- Subtitle job requests require explicit `includeRomanization` and `includeTranslation` controls; word cards are enriched only through the clicked-token endpoint.
- Failed subtitle job responses include `errorCode` and `message`; extension UI maps the stable code, not exact backend copy.
- Source dialect is stored on backend tracks for diagnostics and future product use, but it is not part of the extension-facing track response in the first release UI.
- Extension auth contracts cover login, account summary, and logout. Login returns an opaque scoped bearer token plus safe account summary; account and logout requests require that bearer token.
- Public API errors include stable codes for invalid credentials, missing/unauthenticated tokens, unauthorized tokens, unverified email, insecure production transport, billing required, usage exhausted, feature unavailable, concurrency exceeded, validation, unavailable audio, long videos, audio acquisition, transcription, enrichment, rate limiting, missing resources, expired resources, and internal failures. Error responses may include a `requestId` for log correlation.
