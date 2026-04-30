# Project Guardrails

Created: 2026-04-28

> A great engineer finds the simplest solution to the hardest problems.

These guardrails apply to the YouTube AI Subtitle Learning Extension. Use them when implementing phases, reviewing changes, or deciding whether a new abstraction is justified.

## Product Guardrails

- Build the first product path only: public YouTube video -> generated subtitle track -> synchronized Arabic learning overlay.
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
      -> Laravel queued jobs
        -> providers/storage
```

- Preserve the extension/proxy/backend boundary in code, even while the proxy and backend live in one Laravel app.
- Treat `packages/contracts` as the product boundary. Laravel models, queue payloads, provider responses, and UI state are not API contracts.
- Validate data at every external boundary:
  - extension input from YouTube
  - extension-to-backend API requests
  - provider responses
  - stored/generated subtitle tracks
- After boundary validation, trust the typed value inside the app instead of revalidating it in every handler.
- Prefer one boring path before adding fallback paths.
- Do not introduce distributed services, Redis, Postgres, WebSockets, or object storage until a phase has evidence that SQLite, queues, polling, or local files are insufficient.
- Keep provider integrations behind small interfaces. The app should depend on our transcript/track contracts, not provider-native shapes.

## Laravel Guardrails

- Apply Laravel Boost skill routing from `docs/references/boost-skill-routing.md` for backend phases.
- Use Laravel conventions before custom architecture:
  - Form Requests or equivalent validation for API inputs.
  - Controllers as thin HTTP adapters.
  - Services for product workflow.
  - Jobs for long-running subtitle processing.
  - Migrations and Eloquent for persistence.
  - Scheduler for cleanup.
  - Tests for job state, validation, persistence, and provider failures.
- Use Laravel AI SDK before custom AI integration.
- If Laravel AI SDK cannot expose timestamped transcription output, add a Laravel-side transcription adapter only for that gap.
- Keep AI agents/prompts narrow:
  - translate finalized cues
  - return structured learning metadata
  - do not own job state, storage, retries, rate limits, or UI decisions
- Keep controllers small. If a controller starts coordinating multiple steps, move that workflow into an application service.
- Do not put provider calls, audio filesystem work, or queue orchestration inside Eloquent models.
- Do not log secrets, raw audio, full prompts, or full transcripts by default.

## TypeScript And WXT Guardrails

- Use WXT entrypoints for extension structure:
  - content script for YouTube page integration and overlay mounting
  - background service worker for lifecycle, storage, and backend API calls
  - popup for command/status/settings UI
- Keep content scripts focused on browser integration:
  - detect YouTube watch pages
  - parse video ID
  - find the active video element
  - mount/update/unmount overlay
  - read playback time
- Do not put AI logic, provider assumptions, or backend orchestration in the extension.
- Derive TypeScript API types from shared schemas or keep them mechanically checked against those schemas.
- Keep state explicit:
  - install ID
  - current page/video state
  - job status
  - track metadata
  - user settings
- Avoid fragile YouTube DOM coupling. Prefer URL state, stable YouTube player selectors, the actual `HTMLVideoElement`, and cleanup on navigation.
- Do not add YouTube fallback detection, DOM scoring, polling, or mutation observation until a real failure shows the direct path is insufficient.
- Overlay UI must be isolated from YouTube styling with Shadow DOM or an equivalent boundary.
- Avoid DOM churn during playback. Keep render code direct first, and add diffing or caching only when profiling or visible behavior shows it is needed.

## Simplicity Guardrails

- Start with the smallest end-to-end workflow that proves the next risk.
- Prefer a clear service function over a new abstraction until there are at least two real call sites or a real boundary.
- Prefer direct object construction and named helper functions over clever normalization layers.
- Prefer built-in APIs and shared project utilities before custom local helpers.
- Prefer polling before WebSockets.
- Prefer preset overlay positions before drag/resize.
- Prefer SQLite before production database infrastructure.
- Prefer one transcription candidate before a provider comparison framework.
- Prefer one enrichment agent before a multi-agent system.
- Prefer readable code over clever generic helpers.
- Do not build extension points for hypothetical platforms, providers, or review systems.
- Do not keep scaffold tests, placeholder assertions, or smoke tests that no longer prove intentional product behavior.
- Every new abstraction should answer: what complexity does this remove today?

## Review Guardrails

- Block changes that bypass canonical contracts.
- Block changes that expose provider keys to the extension.
- Block changes that keep raw audio after processing.
- Block changes that make YouTube captions the primary source of truth.
- Challenge abstractions that exist only for imagined future use.
- Require validation evidence for each phase before moving on.

## When To Update This File

Update these guardrails only when a durable project decision changes. Do not add one-off preferences or phase-specific notes here; put those in the relevant execution plan.
