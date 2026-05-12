# Project Guardrails

Created: 2026-04-28

> A great engineer finds the simplest solution to the hardest problems.

These guardrails apply to the YouTube AI Language Subtitle Extension. Use them when implementing phases, reviewing changes, or deciding whether a new abstraction is justified.

## Product Guardrails

- Build the first product path only: public YouTube video -> generated subtitle track -> synchronized language-to-language overlay.
- Keep Netflix, other platforms, live captioning, accounts, vocabulary review, and subtitle editing out of the first release.
- The user must explicitly start AI subtitle generation.
- Extension code must never call OpenAI or any AI provider directly.
- Raw audio is temporary processing data and must be deleted after processing succeeds or fails.
- Completed tracks are retained for 30 days.
- Failures must be visible to the user and diagnosable in logs.

## Architecture Guardrails

- Keep the runtime shape simple:

```text
WXT extension
  -> Laravel proxy-facing API routes
    -> Laravel services
      -> providers/storage
```

- Preserve the extension/proxy/backend boundary in code, even while the proxy and backend live in one Laravel app.
- Treat `packages/contracts` as the product boundary. Laravel models, provider responses, and UI state are not API contracts.
- Validate data at every external boundary:
  - extension input from YouTube
  - extension-to-backend API requests
  - provider responses
  - stored/generated subtitle tracks
- After boundary validation, trust the typed value inside the app instead of revalidating it in every handler.
- Prefer one boring path before adding fallback paths.
- Do not introduce distributed services, Redis, Postgres, WebSockets, queues, or object storage until a phase has evidence that SQLite, synchronous requests, or local files are insufficient.
- Do not keep old product paths as hidden compatibility layers after the visible workflow changes. Remove stale routes, schemas, states, persistence fields, tests, and documentation together.
- Keep provider integrations behind small interfaces. The app should depend on our transcript/track contracts, not provider-native shapes.

## Laravel Guardrails

- Apply Laravel Boost skill routing from `docs/references/boost-skill-routing.md` for backend phases.
- Use Laravel conventions before custom architecture:
  - Form Requests or equivalent validation for API inputs.
  - Controllers as thin HTTP adapters.
  - Services for product workflow.
  - Migrations and Eloquent for persistence.
  - Scheduler for cleanup.
  - Tests for generation behavior, validation, persistence, and provider failures.
- Keep AI provider calls backend-only and hidden behind narrow services.
- Use Laravel AI SDK provider identity and primitives before custom AI integration.
- If Laravel AI SDK cannot expose a required provider option, add a Laravel-side adapter only for that gap.
- Keep AI agents/prompts narrow:
  - translate finalized cues
  - return structured learning metadata
  - do not own generation state, storage, retries, rate limits, or UI decisions
- Keep controllers small. If a controller starts coordinating multiple steps, move that workflow into an application service.
- Keep Eloquent models focused on persistence relationships and casts. Do not put product workflow, provider calls, or generated API response construction in models.
- Do not put provider calls or audio filesystem work inside Eloquent models.
- Persist job progress/status only when the product exposes progress, the backend operates asynchronously, or operations need it for real diagnostics. A synchronous completed-response path should not carry a fake status machine.
- Do not log secrets, raw audio, full prompts, or full transcripts by default.

## TypeScript And WXT Guardrails

- Use WXT entrypoints for extension structure:
  - content script for YouTube page integration and overlay mounting
  - background service worker for lifecycle, storage, and backend API calls
  - popup for command/status/settings UI
- Keep content scripts focused on browser integration:
  - detect YouTube watch pages
  - parse video ID
  - mount/update/unmount overlay
- Do not put AI logic, provider assumptions, or backend orchestration in the extension.
- Derive TypeScript API types from shared schemas or keep them mechanically checked against those schemas.
- Keep state explicit:
  - install ID
  - current page/video state
  - track metadata
  - user settings
- Keep popup/content/background messages small and concrete. Validate the message boundary once, then trust the typed message inside the handler.
- Do not split extension entrypoint logic into tiny wrappers for single use. Prefer direct code in the handler when it stays readable; extract only repeated or meaningfully named behavior.
- Avoid fragile YouTube DOM coupling. Prefer URL state and cleanup on navigation.
- Do not add YouTube fallback detection, DOM scoring, polling, or mutation observation until a real failure shows the direct path is insufficient.
- Overlay UI must be isolated from YouTube styling with Shadow DOM or an equivalent boundary.
- Avoid DOM churn during playback. Keep render code direct first, and add diffing or caching only when profiling or visible behavior shows it is needed.

## Simplicity Guardrails

- Start with the smallest end-to-end workflow that proves the next risk.
- Prefer a clear service function over a new abstraction until there are at least two real call sites or a real boundary.
- Inline one-use helpers when the body is simpler than the name. Keep helpers that express product vocabulary, isolate a boundary, or remove real complexity at the call site.
- Prefer direct object construction and named helper functions over clever normalization layers.
- Prefer built-in APIs and shared project utilities before custom local helpers.
- Avoid generic infrastructure around one endpoint, one provider, one storage location, or one current UI action. Add the abstraction when the second real use case arrives.
- Prefer one synchronous generation request before async delivery mechanisms.
- Prefer preset overlay positions before drag/resize.
- Prefer SQLite before production database infrastructure.
- Prefer one transcription candidate before a provider comparison framework.
- Prefer one enrichment agent before a multi-agent system.
- Prefer readable code over clever generic helpers.
- Do not build extension points for hypothetical platforms, providers, or review systems.
- Do not keep scaffold tests, placeholder assertions, or smoke tests that no longer prove intentional product behavior.
- Every new abstraction should answer: what complexity does this remove today?
- Every persisted field should answer: who reads this, and what product or diagnostic decision does it support today?

## Review Guardrails

- Block changes that bypass canonical contracts.
- Block changes that expose provider keys to the extension.
- Block changes that keep raw audio after processing.
- Block changes that make YouTube captions the primary source of truth.
- Challenge abstractions that exist only for imagined future use.
- Challenge one-use helper functions, enums, status fields, and request wrappers that make direct code harder to read.
- Challenge stale code left behind by simplification work. A refactor is not finished until tests, docs, schemas, and persistence reflect the new shape.
- Require validation evidence for each phase before moving on.

## When To Update This File

Update these guardrails only when a durable project decision changes. Do not add one-off preferences or phase-specific notes here; put those in the relevant execution plan.
