# Architecture Review Report

Created: 2026-05-20
Branch: `codex/architecture-review-generation-optimization`

## 1. Executive Summary

This codebase is a modular monolith plus browser extension for generating AI subtitles for public YouTube videos. It contains a Laravel backend API and queue pipeline, a WXT TypeScript extension, and a shared JSON Schema/OpenAPI contracts package.

Overall architecture quality is good. The strongest design choice is the explicit boundary between extension, backend API, provider integrations, persistence, and shared contracts. The codebase is intentionally documented, has focused tests, and uses Laravel/WXT conventions well. The weakest design area is the growing async subtitle pipeline: orchestration, artifact shape management, queue jobs, logging, provider validation, and state transitions are spread across several large services and duplicated job classes.

Final Architecture Score: 80/100

Letter Grade: B+

Verdict: The architecture is healthy for the current beta/MVP shape and much stronger than a typical early-stage extension/backend project. It is not fully production-ready because deployment, CI, supervised workers, browser smoke coverage, and some security/observability hardening are still incomplete.

Biggest architectural strength: Clear contract-first product boundary through `packages/contracts`, thin Laravel HTTP adapters, backend-only provider calls, and a documented async generation pipeline.

Biggest architectural weakness: Async generation complexity is accumulating in large service classes, JSON artifact payloads, and repetitive queue job wrappers without stronger typed stage boundaries.

Most urgent recommendation: Sanitize backend failure logging before production because audio acquisition exceptions can carry process command/output context into `SubtitleWorkflowLogger::processingFailed()`.

## 2. Repository and System Overview

| Area | Evidence | Notes |
|---|---|---|
| Backend | `app/backend/composer.json`, `app/backend/artisan` | Laravel 13.x, PHP 8.3+, Eloquent, migrations, scheduler, Redis/database queues |
| Extension | `app/extension/package.json`, `app/extension/wxt.config.ts` | WXT 0.20.x browser extension with TypeScript and Vitest |
| Contracts | `packages/contracts/package.json`, `packages/contracts/openapi.json`, `packages/contracts/schemas/*` | OpenAPI 3.1, JSON Schema, AJV validation, generated TypeScript declarations |
| Runtime storage | `compose.yaml`, `app/backend/config/database.php` | Postgres runtime, Redis queue/cache/concurrency, SQLite only under PHPUnit |
| Queue/runtime config | `app/backend/config/queue.php`, `app/backend/config/subtitles.php` | Tier-aware subtitle queues, per-install concurrency caps, worker auto-start for local use |
| Provider integrations | `app/backend/config/ai.php`, `YouTubeAudioSource`, `ElevenLabsScribeTranscriptionService`, `LaravelAiTranslationAnalysisProvider` | Backend-only `yt-dlp`, ElevenLabs Scribe, OpenAI/Laravel AI structured agents |
| Testing | `app/backend/tests`, `app/extension/tests`, `packages/contracts/scripts/validate.mjs` | PHPUnit feature/unit tests, Vitest utility tests, contract validation |
| Operations | `scripts/agent/check.ps1`, `scripts/runtime/use-postgres-redis.ps1`, runtime console commands | Strong local harness, missing CI and production deployment workflow |

Main directories:

- `app/backend/app/Http`: API routes, controllers, requests, resources, middleware, API error response.
- `app/backend/app/Services`: subtitle workflow, audio, transcription, AI analysis, language catalog, tracing, telemetry.
- `app/backend/app/Jobs`: queue jobs for transcription, cue batch work, continuations, finalization, concurrency middleware.
- `app/backend/database/migrations`: product tables, queues, batches, cache, failed jobs, trace events.
- `app/extension/entrypoints`: WXT background, content script, popup.
- `app/extension/utils`: API client, messages, settings, overlay, WebVTT binding, language helpers.
- `packages/contracts`: canonical schemas, fixtures, generated TypeScript declarations, language catalog.
- `docs`: product intent, architecture, reliability, security, observability, execution plans, quality rules.

Main entrypoints:

- Backend HTTP: `app/backend/routes/api.php`.
- Backend worker jobs: `ProcessSubtitleJob`, `TokenizeSubtitleCueBatch`, `TranslateSubtitleCueBatch`, `RomanizeSubtitleCueBatch`, `EnrichSubtitleCueBatch`, continuation jobs, and `FinalizeSubtitleJob`.
- Backend scheduler: `app/backend/routes/console.php` schedules `subtitles:prune-expired`.
- Extension background: `app/extension/entrypoints/background.ts`.
- Extension content script: `app/extension/entrypoints/content.ts`.
- Extension popup: `app/extension/entrypoints/popup/main.ts`.
- Contracts validation: `packages/contracts/scripts/validate.mjs`.

Important missing or unclear production context:

- There is no repository CI workflow under `.github/` or equivalent.
- There is no production Dockerfile, Kubernetes manifest, process supervisor config, or deployment runbook.
- Account/auth/billing work is planned but absent from the current product path.
- Medium and near-limit provider-backed timing evidence remains tracked in `docs/exec-plans/tech-debt-tracker.md` as `TD-010`.
- Browser extension screenshot/E2E smoke automation remains tracked as `TD-003` and `TD-007`.

## 3. Reconstructed Architecture

The current style is a Laravel API modular monolith with a WXT extension client and a shared contracts package. Internally, the backend follows a layered service architecture rather than strict clean architecture:

```text
WXT popup/content/background
  -> shared contract types
  -> Laravel API requests/resources
  -> application services and queue jobs
  -> provider adapters, artifact store, Eloquent models
  -> Postgres, Redis, ElevenLabs, OpenAI, yt-dlp
```

The architecture is intentional. `ARCHITECTURE.md`, `docs/references/project-guardrails.md`, `docs/SECURITY.md`, `docs/RELIABILITY.md`, and `docs/OBSERVABILITY.md` all describe the same extension-to-backend-to-provider shape that the code mostly implements.

| Component/Module | Location | Responsibility | Depends On | Used By | Architectural Notes |
|---|---|---|---|---|---|
| Canonical contracts | `packages/contracts` | OpenAPI, JSON Schema, fixtures, generated TS types, language catalog | Node scripts, AJV, json-schema-to-typescript | Extension, backend tests, docs | Strong boundary artifact, but backend does not validate responses against schemas at runtime |
| Backend API routes | `app/backend/routes/api.php` | Health and `/v1/*` API surface | Controllers, install ID middleware, throttles | Extension API client | Small and clear |
| Request validation | `CreateSubtitleJobRequest`, `EnrichLearningTokenRequest` | Validate payloads and install ID helpers | Laravel FormRequest, `LanguageCatalog` | Controllers | Good boundary validation |
| HTTP adapters | `SubtitleJobController`, `LearningTokenController` | List, create, poll jobs, enrich clicked token | Eloquent, resources, services | API routes | Store/show are thin; history mapping in controller is moderately heavy |
| Subtitle job orchestration | `SubtitleJobService` | Coalesce compatible jobs, reset stale jobs, dispatch initial work, auto-start local workers | Eloquent, DB transactions, queue, logger, tracer | `SubtitleJobController` | Good placement for use-case orchestration |
| Generation pipeline | `SubtitleGenerationPipeline` | Transcribe, dispatch analysis batches, merge results, final track persistence | Audio/transcription/provider/artifacts/batches/telemetry/cost/failure services | Queue jobs | Clear workflow but becoming a central state-machine class |
| Cue batch processing | `SubtitleCueBatchProcessor` | Tokenize, translate, romanize, enrich cue batches | AI provider, artifacts, failure handler, telemetry | Batch queue jobs | Good extraction from pipeline |
| Artifact store | `SubtitleJobArtifactStore` | Store/read transcript, draft, batch, merged, enriched cue JSON payloads | Eloquent, `CueEnrichmentResult`, tracer | Pipeline, batch processor | Useful queue handoff boundary, but payloads are array-shaped and weakly typed |
| Provider adapters | `YouTubeAudioSource`, `ElevenLabsScribeTranscriptionService`, `LaravelAiTranslationAnalysisProvider` | External side effects and AI structured output | Process, HTTP, Laravel AI agents | Pipeline, token enrichment | Backend-only provider boundary is strong; AI provider class is too broad |
| AI agents | `app/backend/app/Ai/Agents/*` | Prompt instructions and structured output schemas | Laravel AI | Provider adapter | Narrow agent prompts are a good fit |
| Runtime telemetry | `SubtitleRuntimeTracer`, `SubtitlePipelineTelemetry`, `SubtitleWorkflowLogger`, `SubtitleProviderCostRecorder` | Logs, trace rows, budget/cost events, queue lifecycle | Eloquent, Laravel Queue events, Log | Pipeline, jobs, provider services | Strong operational story, but logger sanitization has a gap |
| Persistence model | `SubtitleJob`, `SubtitleTrack`, `SubtitleJobArtifact`, `SubtitleJobEvent` | Eloquent persistence relationships and casts | Database | Services/resources/commands | Models are appropriately thin |
| Runtime commands | `app/backend/app/Console/Commands/*` | Runtime check, trace, slow events, metrics, pruning | Eloquent, DB/Redis, queue config | Operators/agents | Good local diagnostics |
| Extension background | `app/extension/entrypoints/background.ts` | Message router, backend API calls, polling, local tab state, track cache | WXT browser APIs, API client, storage helpers | Popup/content | Handles real workflow but is large and multi-responsibility |
| Extension content script | `app/extension/entrypoints/content.ts` | YouTube page integration, overlay lifecycle, WebVTT binding, clicked-token requests | Overlay, WebVTT utilities, browser messages | YouTube pages | Clear browser integration boundary |
| Extension popup | `app/extension/entrypoints/popup/main.ts` | Settings, language pickers, generate action, job history rendering | Background messages, DOM, language utilities | Extension popup UI | Large but direct, no framework complexity |
| Overlay | `app/extension/utils/overlay.ts` | Shadow DOM shell and overlay rendering | Escaping utility, contract types | Content script | Good isolation from YouTube DOM/CSS |

## 4. Major Workflow Analysis

### Workflow: Subtitle Generation

Trigger/input:

- User clicks Generate in `app/extension/entrypoints/popup/main.ts`.
- Popup sends `popup.generateSubtitles` to `app/extension/entrypoints/background.ts`.

Files/classes/functions involved:

- `background.ts`: `generateSubtitlesFromPopup()`, `generateSubtitlesForTab()`, `waitForCompletedSubtitleJob()`.
- `utils/api.ts`: `SubtitleApiClient.createSubtitleJob()` and `getSubtitleJob()`.
- `routes/api.php`: `POST /v1/subtitle-jobs`, `GET /v1/subtitle-jobs/{jobId}`.
- `CreateSubtitleJobRequest`: validates YouTube ID/URL, duration, language codes, generation controls.
- `SubtitleJobController::store()`: delegates to `SubtitleJobService::generate()`.
- `SubtitleJobService`: creates/reuses/resets jobs, dispatches `ProcessSubtitleJob`, calls `SubtitleQueueWorkerBootstrapper`.
- `ProcessSubtitleJob`: calls `SubtitleGenerationPipeline::transcribeSourceAudioAndDispatchAnalysis()`.
- `YouTubeAudioSource`: validates metadata and downloads temporary audio.
- `ElevenLabsScribeTranscriptionService`: sends audio to Scribe.
- `ScribeTranscriptNormalizer`: converts provider word timestamps into segments and WebVTT.
- `SubtitleJobArtifactStore`: stores transcript and draft cues.
- `SubtitleBatchDispatcher`: dispatches analysis, romanization, enrichment, or finalization.
- `SubtitleCueBatchProcessor`: calls `LaravelAiTranslationAnalysisProvider`.
- `TimestampedSubtitleTrackGenerator`: validates final WebVTT/cues and creates `SubtitleTrack`.

Step-by-step flow:

1. The popup obtains active tab state and asks the background worker to generate subtitles.
2. `background.ts` parses the active YouTube URL with `parseYoutubePage()` and builds a `CreateSubtitleJobRequest`.
3. `SubtitleApiClient` posts to `/v1/subtitle-jobs` with `X-Extension-Install-Id`.
4. `RequireExtensionInstallId` validates the anonymous install ID.
5. `CreateSubtitleJobRequest` validates request shape and YouTube URL/video ID consistency.
6. `SubtitleJobService` locks and reuses compatible jobs by install ID, video ID, source language, target language, and processing version. It resets stale `preparing` work or creates a new `SubtitleJob`.
7. `ProcessSubtitleJob` claims the job by `run_id`, acquires audio, transcribes, normalizes, stores artifacts, and dispatches cue batch jobs.
8. Tokenization always runs; translation can run in parallel; romanization can run after tokenization; full enrichment runs after merged cues.
9. `FinalizeSubtitleJob` reads transcript plus merged/enriched cues, writes a `SubtitleTrack`, marks the job completed, deletes artifacts, and emits timing/budget telemetry.
10. The extension polls `GET /v1/subtitle-jobs/{jobId}` until the job is completed or failed.
11. A completed track is sent to the content script and bound to the YouTube video through WebVTT.

Data transformations:

- YouTube URL and video ID become a validated create-job payload.
- `yt-dlp` metadata/download output becomes a `TemporaryAudioFile`.
- ElevenLabs word payload becomes `TimestampedTranscriptSegment[]` and WebVTT.
- Transcript segments become draft cue arrays.
- AI agent outputs become tokenized, translated, romanized, or enriched cue arrays.
- Final cue arrays become persisted `SubtitleTrack.cues` and extension contract `TrackResponse`.

Side effects:

- Temporary audio files are created and deleted.
- Provider calls go to ElevenLabs and OpenAI.
- Jobs, batches, artifacts, tracks, trace events, failed jobs, and cache/concurrency rows are persisted.
- Extension local storage stores settings, install ID, and recent active tracks.

Architectural assessment:

The workflow is coherent and intentionally staged. The main risk is that the workflow state is represented through mutable database rows, string stages, and JSON artifacts rather than typed internal state objects. That is acceptable for the current size, but it will become fragile as account ownership, billing, cancellation, cross-account caching, and more provider routing are added.

### Workflow: Clicked Learning Token Enrichment

Trigger/input:

- User clicks a token in the overlay rendered by `OverlayShell`.

Files/classes/functions involved:

- `content.ts`: `enrichLearningToken()`.
- `background.ts`: `enrichLearningTokenFromContent()`.
- `SubtitleApiClient.enrichLearningToken()`.
- `routes/api.php`: `POST /v1/learning-tokens`.
- `EnrichLearningTokenRequest`.
- `LearningTokenController::store()`.
- `LearningTokenEnrichmentService::enrich()`.
- `LaravelAiTranslationAnalysisProvider::enrichToken()`.
- `LearningTokenCardAgent`.
- `trackWithLearningToken()`.

Step-by-step flow:

1. Overlay click handler checks whether a token already has learning metadata.
2. Content script sends `content.enrichLearningToken` to the background worker.
3. Background verifies that the track is active for the current tab or recoverable from local storage.
4. Background calls `/v1/learning-tokens` with `trackId`, `cueId`, and `tokenIndex`.
5. Backend validates the request and loads a non-expired track whose parent job belongs to the install ID.
6. `LearningTokenEnrichmentService` finds the cue and token inside the track JSON.
7. If metadata exists or source and target language are the same, it returns the existing token.
8. Otherwise it caches by source/detected/target language, token normalized text, cue context, model, and version, then calls the OpenAI card agent.
9. The service merges the token metadata into the stored `cues` JSON and returns the enriched token.
10. Background patches the active track and content re-renders the overlay.

Architectural assessment:

The workflow is well contained and respects the provider boundary. The architectural concern is concurrent JSON mutation: two clicked-token requests against the same track can load the same `cues` array and overwrite each other when saving.

### Workflow: Playback Sync and Overlay Rendering

Trigger/input:

- Content script receives a ready `TrackResponse` or restores one from local storage.

Files/classes/functions involved:

- `content.ts`: `bindGeneratedSubtitles()`, `applySubtitleState()`, `updateOverlay()`.
- `webvtt-track.ts`: `bindWebVttTrackToVideo()`, `offsetTrackTiming()`, `findTrackCue()`.
- `webvtt-track-logger.ts`.
- `overlay.ts`: `OverlayShell`, `renderOverlayContent()`.

Step-by-step flow:

1. Content script parses the current YouTube watch page and validates the track video ID.
2. It finds the primary `<video>` element.
3. It creates a hidden browser `TextTrack` from the generated WebVTT blob.
4. Browser `cuechange` events are mapped back to API cue objects by start/end time tolerance.
5. The overlay renders the active cue into an isolated Shadow DOM host.
6. Manual subtitle timing offset shifts both WebVTT timestamps and cue timings locally.

Architectural assessment:

This is a strong, simple browser-native design. It avoids direct AI logic in the extension and keeps YouTube DOM coupling limited to URL parsing, route-change events, and `document.querySelector('video')`. The missing piece is automated browser smoke coverage against a built extension.

### Workflow: Job History and Progress Recovery

Trigger/input:

- Popup opens or periodically refreshes backend state.

Files/classes/functions involved:

- `popup/main.ts`: `loadPopupState()`, `refreshBackendState()`, `renderJobHistory()`.
- `background.ts`: `getPopupState()`, `listBackendJobHistory()`, `resolveCompletedSubtitleJob()`.
- `backend-subtitle-state.ts`: `stateWithBackendProgress()`.
- `SubtitleJobController::index()`.

Step-by-step flow:

1. Popup asks background for state with or without backend sync.
2. Background lists recent jobs for the install ID.
3. Backend filters compatible current processing versions, recent running/failed jobs, and non-expired completed tracks.
4. Extension converts the most relevant job for the active video into loading, error, or ready state.
5. Completed history items can be resolved with a `GET /v1/subtitle-jobs/{jobId}` call to recover the full track.

Architectural assessment:

The flow gives good resilience after popup reopen or background restart. The risk is that `SubtitleJobController::index()` duplicates some resource shaping logic that overlaps with `SubtitleJobResource`, so future response contract changes need care.

## 5. Boundary Analysis

| Boundary | Current State | Evidence | Risk | Recommendation |
|---|---|---|---|---|
| Presentation/UI | Extension popup/content/overlay are separated from backend and provider logic | `entrypoints/popup/main.ts`, `entrypoints/content.ts`, `utils/overlay.ts` | Large entrypoint files are harder to review as UI grows | Extract only repeated or workflow-level utilities, such as job polling or popup rendering sections |
| Application/use-case logic | Backend use cases live mostly in services | `SubtitleJobService`, `SubtitleGenerationPipeline`, `SubtitleCueBatchProcessor`, `LearningTokenEnrichmentService` | Pipeline class can become a god orchestrator as more stages are added | Split by real stage boundaries only when the next feature increases complexity |
| Domain/business logic | Domain rules are mostly in services and validators, with Eloquent models kept thin | `LearningTokenOutputValidator`, `ScribeTranscriptNormalizer`, `TimestampedSubtitleTrackGenerator`, thin `SubtitleJob` model | Cue/token data is passed as arrays, making internal shape errors possible | Introduce minimal cue/token value objects or typed DTOs at artifact and provider boundaries |
| Infrastructure/persistence | Eloquent models, migrations, artifact store, Postgres/Redis config are clear | `Models/*`, migrations, `SubtitleJobArtifactStore`, `config/database.php`, `config/cache.php` | Persistence details are used directly in application services | Acceptable for Laravel; keep models thin and avoid moving provider logic into models |
| External integrations | Provider calls are backend-only and isolated | `YouTubeAudioSource`, `ElevenLabsScribeTranscriptionService`, `LaravelAiTranslationAnalysisProvider` | AI provider class owns too many stage-specific responsibilities | Keep agents narrow, but split provider result validation/normalization by stage |
| Configuration | Config is centralized in Laravel config files and `.env.example` | `config/subtitles.php`, `config/queue.php`, `config/ai.php`, `.env.example` | Production deployment settings are documented but not enforced by deployment artifacts | Add a deployment/runtime runbook and CI/runtime checks |
| Tests | Backend, extension, and contracts have real test suites | `tests/Feature/SubtitleJobApiTest.php`, `tests/Feature/SubtitleRuntimeTracingTest.php`, `app/extension/tests`, `packages/contracts/scripts/validate.mjs` | No browser extension smoke automation and limited provider-backed evidence | Add built-extension browser smoke and schema validation against live Laravel responses |

## 6. Dependency and Coupling Analysis

Healthy dependency patterns found:

- The extension depends on shared contract types through `app/extension/utils/contracts.ts`; the backend does not import extension code.
- Controllers are mostly thin and depend on request/resource/service classes rather than provider adapters directly.
- Eloquent models are thin persistence records with relationships and casts only.
- Provider calls are backend-only. The extension never calls OpenAI or ElevenLabs.
- Queue workers carry `subtitleJobId` and `runId`, and stale work is skipped before provider calls or artifact writes.
- The runtime tracer sanitizes persisted trace context through `SubtitleRuntimeTracer::sanitizeContext()`.

Unhealthy or risky dependencies found:

- `SubtitleGenerationPipeline` depends on many concrete services and owns stage transitions, artifact handoffs, conditional branching, and final persistence. This is understandable but high-coupling.
- `LaravelAiTranslationAnalysisProvider` depends on all AI agents and owns tokenization retry splitting, prompt input construction, provider exception mapping, output validation, cue merging, and clicked-token validation.
- Queue job classes duplicate the same queue configuration, concurrency middleware, retry policy, failure handling, and timestamp logic.
- `SubtitleWorkflowLogger::processingFailed()` logs raw exception context directly. That bypasses the sanitizer used by `SubtitleRuntimeTracer`.
- The extension API client casts response JSON to TypeScript types without runtime schema validation.
- `LearningTokenEnrichmentService` mutates full `SubtitleTrack.cues` JSON, coupling token patching to the entire track blob.

Circular or risky dependencies:

- I did not find evidence of circular source dependencies in the inspected application code.
- The risk is not circular imports. The risk is behavioral coupling through shared string stages, artifact type constants, processing-version strings, queue names, and array-shaped cue/token payloads.

Over-coupled areas:

- `SubtitleGenerationPipeline` and `SubtitleJobArtifactStore`.
- `LaravelAiTranslationAnalysisProvider` and AI stage-specific validation.
- `background.ts` as the extension's message router, polling coordinator, backend client owner, tab state owner, and local cache coordinator.

Under-abstracted areas:

- Cue/token internal data shape is under-modeled. Arrays are convenient, but the same required fields are checked in multiple places.
- Runtime response validation in the extension is underdeveloped relative to the contract-first posture.

Over-abstracted areas:

- There is little speculative abstraction in current app code. The project has deliberately removed older compatibility paths and generic provider layers.
- The queue job wrappers may look like separate domain classes, but most are thin duplicated wrappers around two processors.

Dependency inversion opportunities:

- Add a narrow sanitizer for failure log context and make loggers depend on sanitized data only.
- Add typed cue/token DTOs at the artifact/provider boundary before introducing heavier architecture.
- Introduce a small shared queue job helper or trait for retry/middleware/failure boilerplate if more jobs are added.

## 7. Domain and Business Logic Analysis

Main domain concepts:

- Subtitle generation job: `SubtitleJob`.
- Generated subtitle track: `SubtitleTrack`.
- Intermediate artifacts: `SubtitleJobArtifact`.
- Runtime trace events: `SubtitleJobEvent`.
- Transcript segments: `TimestampedTranscript` and `TimestampedTranscriptSegment`.
- Cue enrichment result: `CueEnrichmentResult`.
- Learning token metadata: represented through contract-shaped arrays.
- Language catalog: `LanguageCatalog` backed by `packages/contracts/languages.json`.
- Generation tier and processing version: `SubtitleTier` and constants in `SubtitleJobService`.

Where business rules live:

- Request validity: `CreateSubtitleJobRequest`, `EnrichLearningTokenRequest`, `RequireExtensionInstallId`.
- YouTube support and duration: `YouTubeAudioSource`.
- Scribe response normalization and cue segmentation: `ScribeTranscriptNormalizer`.
- Token boundaries and AI output integrity: `LaravelAiTranslationAnalysisProvider` plus `LearningTokenOutputValidator`.
- Final track validity: `TimestampedSubtitleTrackGenerator`.
- Job reuse/reset and current processing versions: `SubtitleJobService`.
- Queue priority and install concurrency: `SubtitleTier`, `SubtitleQueue`, `LimitSubtitleInstallConcurrency`.
- Extension display state: `messages.ts`, `backend-subtitle-state.ts`, `background.ts`, `content.ts`, `overlay.ts`.

Assessment:

Business logic placement is mostly appropriate. Controllers are not doing provider orchestration, and Eloquent models are not fat models. The main weakness is that cue/token structures are not first-class domain objects. They are arrays stored in JSON, passed through jobs, transformed by provider services, and validated late. This keeps the current code direct, but it makes future changes to cue shape, token metadata, billing ownership, or account-level caching riskier.

Processing versions are also heavily encoded as string constants in `SubtitleJobService`. That is acceptable while processing variants are only cache-compatibility keys, but it will become harder to reason about if SaaS tiers, model routing, prompt versions, cache versions, and account entitlements all evolve separately.

## 8. State, Data Flow, and Side Effects

Where state is stored:

- Backend persistent state:
  - `subtitle_jobs`: lifecycle, progress, status, owner install ID, processing version, generation tier, cost estimate.
  - `subtitle_tracks`: final generated WebVTT and cue/token JSON.
  - `subtitle_job_artifacts`: intermediate transcript and cue batch payloads.
  - `subtitle_job_events`: sanitized runtime traces.
  - Laravel `jobs`, `job_batches`, `failed_jobs`, `cache`, and `cache_locks`.
- Redis state:
  - Queue data.
  - Subtitle concurrency locks/counters through the `subtitle_concurrency` cache store.
- Extension local state:
  - Settings and install ID in `utils/settings.ts`.
  - Recent active tracks in `utils/active-tracks.ts`.
  - Per-tab runtime state in `background.ts` `tabSubtitleStates`.

How state changes:

- `SubtitleJobService` creates/reuses/resets job rows inside transactions.
- Queue jobs carry `run_id`, and pipeline methods refuse stale runs.
- Pipeline stage methods update `status`, `stage`, and `progress_percent`.
- Artifact rows are written between async stages and deleted after finalization/failure/reset.
- Token enrichment patches `SubtitleTrack.cues` JSON after clicked-token enrichment.

Validation strategy:

- API requests are validated with Laravel FormRequests.
- Install IDs are validated by middleware before throttles.
- Provider responses are normalized/validated by backend services.
- Final cue/track shape is validated before persistence.
- Contract schemas are validated in `packages/contracts`.
- Extension runtime messages are validated by `isRuntimeMessage()`.

Validation gaps:

- Extension backend responses are trusted with `return body as TResponse` in `SubtitleApiClient`.
- Backend API resources are not mechanically validated against JSON Schema in feature tests.
- `docs/generated/db-schema.md` is stale for generation tier and cost fields.

External side effects:

- `yt-dlp` process execution.
- Temporary file creation/deletion.
- ElevenLabs HTTP upload.
- OpenAI/Laravel AI structured output calls.
- Queue worker process auto-start in local runtime.
- Logs and trace rows.
- Browser extension local storage and YouTube DOM integration.

Hidden or fragile side effects:

- `SubtitleQueueWorkerBootstrapper` starts worker processes from a web request in local mode. This is useful for local UX but should not be part of production process management.
- `LearningTokenEnrichmentService` updates a whole track JSON blob for one token.
- Queue job classes initially set queue names in constructors but are often overridden by dispatch calls. Tests cover key paths, but this pattern is easy to break.

## 9. Testing and Testability

Existing test structure:

- Backend PHPUnit feature tests:
  - `SubtitleJobApiTest`: generation behavior, job reuse, queue routing, failure states, language behavior, async batches, validation.
  - `SubtitleRuntimeTracingTest`: trace rows, queue hooks, concurrency middleware, metrics, pruning.
  - `SubtitleRuntimeProfileTest`: runtime profile behavior.
  - `ContractBoundaryTest`: contract files and API path existence.
  - `HealthTest`, `PruneExpiredSubtitleTracksTest`.
- Backend unit tests:
  - Audio acquisition, transcription normalizer/service, token validation, language catalog, AI agent instructions, workflow logger, tracer, track generator.
- Extension Vitest tests:
  - API client, message validation, settings, language helpers, overlay rendering, WebVTT binding/logger, backend subtitle state.
- Contract package:
  - `npm run check` validates schemas, fixtures, language enum sync, and OpenAPI.

What appears covered:

- Core generation happy path and many failure modes.
- Request validation and install ID requirement.
- Async queue dispatch and stale-run behavior.
- Concurrency middleware and budget/cost telemetry.
- Provider response normalization through fakes.
- WebVTT binding and overlay rendering utilities.
- Runtime message validation.

What appears untested or lightly tested:

- Built extension running inside a real browser/YouTube page.
- Full popup/background/content integration in a browser extension runtime.
- Production Redis/Postgres worker supervision behavior.
- Real provider timing and rate-limit behavior for medium and near-limit videos.
- Runtime schema validation of actual Laravel responses against `packages/contracts`.
- Concurrent clicked-token enrichment writes to the same track.
- Sanitization of direct workflow logs from exception context.

Testability assessment:

The architecture supports unit and feature tests well. Laravel services are injectable enough for fakes, and WXT utility functions are testable outside the browser. The biggest blocker to deeper confidence is not unit testability. It is missing integration infrastructure for browser extension and provider-backed runtime flows.

Recommended test architecture:

- Add backend feature tests that validate actual JSON responses against the canonical schemas using a small PHP JSON Schema validator or a Node contract check invoked against captured fixtures.
- Add one targeted test proving failure logging does not include `command`, `stdout_excerpt`, `stderr_excerpt`, `youtubeUrl`, raw file paths, prompts, or transcripts.
- Add a browser smoke script that loads the built WXT extension, opens a deterministic test page or YouTube-compatible fixture, verifies `#tse-overlay-host`, and captures a screenshot.
- Add a concurrency test for two clicked-token enrichments against different tokens on the same track.

## 10. Production Readiness

Configuration management:

- Strong local configuration exists in `.env.example`, `config/subtitles.php`, `config/queue.php`, `config/cache.php`, and `config/database.php`.
- Provider secrets are backend-only in Laravel config.
- `.env.example` documents OpenAI, ElevenLabs, Postgres, Redis, queue, worker, and cost settings.

Environment handling:

- Runtime defaults prefer Postgres/Redis.
- PHPUnit forces SQLite `:memory:` and array cache.
- `subtitles:runtime-check` can enforce runtime expectations.

Deployment:

- `compose.yaml` starts local Postgres and Redis only.
- There is no production web/worker/scheduler deployment artifact.
- There is no CI workflow invoking `scripts/agent/check.ps1`.

Logging and observability:

- Strong structured logging and trace rows exist through `SubtitleRuntimeTracer`, `SubtitlePipelineTelemetry`, `SubtitleWorkflowLogger`, and runtime commands.
- `subtitles:runtime`, `subtitles:trace`, `subtitles:slow`, and `subtitles:metrics` give good agent/operator visibility.
- The major problem is direct exception context logging in `SubtitleWorkflowLogger::processingFailed()`.

Security boundaries:

- Extension-facing routes require `X-Extension-Install-Id`.
- Routes are throttled per install ID and IP.
- Extension never receives provider keys and never calls AI providers.
- Temporary audio is deleted in `finally` after transcription work.
- Trace rows sanitize unsafe keys.
- Anonymous install ID ownership is the current boundary. That is acceptable for first release but not a substitute for account auth.

Reliability/failure modes:

- Jobs have `run_id` fences.
- Stale queued work is skipped.
- Failed jobs are marked with stable public errors.
- Artifacts are cleaned up on finalization/failure/reset.
- Expired tracks are pruned daily.
- Queue retries are intentionally unlimited for release-based concurrency delays, with exception caps.

Production readiness verdict:

Good local beta readiness. Not production-ready for paid SaaS traffic until CI, production process supervision, logging sanitization, deployment docs, provider-backed timing evidence, and browser smoke coverage are in place.

## 11. Architectural Strengths

1. Contract-first boundary

Evidence: `packages/contracts/openapi.json`, `schemas/*.schema.json`, `scripts/validate.mjs`, `app/extension/utils/contracts.ts`.

Why it matters: Extension/backend changes have a shared source of truth and language enum drift is mechanically checked.

Preserve it.

2. Thin Laravel HTTP adapters

Evidence: `routes/api.php`, `LearningTokenController::store()`, `SubtitleJobController::store()`, FormRequests.

Why it matters: HTTP concerns do not own provider orchestration or queue logic.

Preserve it.

3. Backend-only provider integration

Evidence: `YouTubeAudioSource`, `ElevenLabsScribeTranscriptionService`, `LaravelAiTranslationAnalysisProvider`, no provider calls in `app/extension`.

Why it matters: Secrets, audio, transcripts, and AI calls stay on the server side.

Preserve it.

4. Run ID fence for async work

Evidence: `SubtitleJob.run_id`, job constructors, `loadRunningJob()`, `claimPreparingJob()`, stale-run trace events.

Why it matters: Reset/retry behavior does not let stale queued jobs write artifacts or call providers for the wrong run.

Preserve it.

5. Strong local observability

Evidence: `SubtitleRuntimeTracer`, `SubtitlePipelineTelemetry`, runtime commands, trace events, metrics command.

Why it matters: Async generation failures are diagnosable without dumping transcripts or prompts into trace rows.

Preserve and harden direct log sanitization.

6. Framework-appropriate simplicity

Evidence: Thin Eloquent models, FormRequests, Laravel queues/batches, WXT entrypoints, Shadow DOM overlay.

Why it matters: The app avoids microservices and heavy clean-architecture ceremony while still keeping useful boundaries.

Preserve it.

7. Meaningful tests

Evidence: `SubtitleJobApiTest`, `SubtitleRuntimeTracingTest`, `YouTubeAudioSourceTest`, extension utility tests, contract validation.

Why it matters: Tests cover product behavior, not just scaffolding.

Preserve and expand into browser/runtime smoke coverage.

## 12. Architectural Issues

| # | Issue | Severity | Evidence | Why It Matters | Recommended Fix | Effort | Impact |
|---|---|---|---|---|---|---|---|
| 1 | Failure logging can leak sensitive or noisy provider/process context | High | `YouTubeAudioSource::throwProcessFailure()` puts `command`, `stdout_excerpt`, and `stderr_excerpt` into exception context; `SubtitleWorkflowLogger::processingFailed()` spreads `$exception->context` directly into `Log::warning()` | YouTube URLs, local paths, provider output excerpts, or command details can enter logs despite docs saying logs must avoid sensitive runtime payloads | Add a single log-context sanitizer or allowlist inside `SubtitleWorkflowLogger`; never log command/output excerpts by default | Small | High |
| 2 | Production deployment architecture is incomplete | High | `compose.yaml` only defines local Postgres/Redis; no CI workflow, Dockerfile, worker supervisor config, scheduler deployment, or production runbook was found | Paid or public traffic needs repeatable web, worker, scheduler, queue, migration, and config handling | Add CI for `scripts/agent/check.ps1`, a production deployment plan, supervised queue workers, scheduler setup, and runtime smoke checks | Large | High |
| 3 | Async generation orchestration is concentrated in a large pipeline service | High | `SubtitleGenerationPipeline` owns transcription, artifact writes, batch dispatch, merge logic, romanization branching, enrichment branching, final persistence, and failure boundaries | New stages like cancellation, account entitlements, billing, provider routing, and cross-account caching will make one central state machine hard to change safely | Split only along real workflow stages: transcription stage, analysis continuation, romanization merge, finalization; keep `SubtitleJobService` as the public use case | Medium | High |
| 4 | AI provider orchestration class has too many stage responsibilities | Medium | `LaravelAiTranslationAnalysisProvider` handles tokenization, translation, romanization, full enrichment, clicked-token cards, prompt inputs, retries, exception mapping, and validation | Stage-specific rules become hard to test and review, especially when model routing or prompt versions diverge | Extract stage-specific result validators/input builders while keeping one provider facade if useful | Medium | High |
| 5 | Cue/token domain data is mostly array-shaped JSON | Medium | `CueEnrichmentResult` stores `array $cues`; `SubtitleJobArtifactStore`, `TimestampedSubtitleTrackGenerator`, and provider methods repeatedly inspect array keys | Internal shape drift can fail late in async jobs and makes refactors riskier | Add minimal typed DTOs/value objects for internal cue/token shapes at provider/artifact boundaries | Large | High |
| 6 | Extension API client trusts backend JSON without runtime response validation | Medium | `SubtitleApiClient.request()` returns `body as TResponse`; contracts are TypeScript-only in extension runtime | Bad or stale backend responses can propagate into popup/content state and fail later in UI code | Generate or hand-write small runtime guards for `JobResponse`, `TrackResponse`, history, and token responses at the fetch boundary | Medium | Medium |
| 7 | Clicked-token enrichment can lose concurrent token patches | Medium | `LearningTokenEnrichmentService::enrich()` loads `cues`, modifies one nested token, then `update(['cues' => $cues])` on the whole track JSON | Two simultaneous token enrichments for the same track can overwrite each other's changes | Use a transaction with row lock, reload/merge before save, or move token metadata into a child table if write concurrency grows | Medium | Medium |
| 8 | Queue job wrappers duplicate configuration and failure behavior | Medium | Eight job classes repeat `tries`, `maxExceptions`, `timeout`, `queuedAtMs`, middleware, `failed()`, and `currentTimeMs()` patterns | Retry/middleware/stage changes can be applied inconsistently | Introduce a narrow trait/helper for shared subtitle job configuration and failure handling, or consolidate continuation jobs when behavior is identical | Small | Medium |
| 9 | Browser extension smoke/E2E coverage is absent | Medium | `TD-003` and `TD-007`; tests cover utilities but not loaded extension behavior in a browser | Overlay injection, content-script permissions, popup-background messaging, and YouTube route behavior can regress without test evidence | Add a built-extension smoke harness with screenshot capture and deterministic overlay checks | Large | High |
| 10 | Contract enforcement is stronger in Node than in backend feature responses | Medium | `ContractBoundaryTest` checks files and paths but not actual response bodies against schemas | Backend resources can drift from JSON Schema while tests still pass | Validate representative Laravel API responses against `packages/contracts/schemas` in CI or feature tests | Medium | Medium |
| 11 | Generated database docs are stale | Low | `docs/generated/db-schema.md` omits `generation_tier` and `estimated_provider_cost_microusd` added by `2026_05_20_034043_add_generation_optimization_fields_to_subtitle_jobs_table.php` | Future agents may miss important runtime/cost fields | Update generated docs and add a doc freshness check when migrations change | Small | Medium |
| 12 | Extension entrypoints are becoming large coordinators | Low | `background.ts`, `popup/main.ts`, and `overlay.ts` are among the largest extension files | Onboarding and review will slow as UI/account flows grow | Extract cohesive utilities only when responsibilities repeat, such as job polling/history recovery or popup rendering helpers | Medium | Medium |

## 13. Recommended Target Architecture

Recommended style:

- Keep the current Laravel modular monolith and WXT extension architecture.
- Do not introduce microservices.
- Do not force full clean architecture.
- Move toward a framework-native modular monolith with typed internal workflow boundaries.

Recommended dependency direction:

```text
packages/contracts
  -> extension runtime types and backend contract tests

backend HTTP
  -> application services
  -> domain-ish DTOs/value objects
  -> infrastructure adapters and Eloquent persistence

extension popup/content
  -> background workflow/state
  -> API client
  -> backend API
```

Recommended backend module shape:

```text
app/backend/app/
  Http/
    Controllers/Api/
    Requests/
    Resources/
    Middleware/
    Responses/
  Models/
  Services/
    Subtitles/
      Jobs/
      Stages/
        TranscribeSourceAudio.php
        DispatchCueAnalysis.php
        MergeCueAnalysis.php
        FinalizeSubtitleTrack.php
      Data/
        SubtitleCueData.php
        LearningTokenData.php
        CueBatchResult.php
      Telemetry/
      ArtifactStore.php
    Audio/
    Transcription/
    TranslationAnalysis/
      Providers/
      Validators/
      AgentInputs/
    Languages/
  Ai/Agents/
```

This structure is illustrative, not a rewrite target. The safest first step is to introduce `Data/` objects and log sanitization, then split stage services only when a feature forces another change in `SubtitleGenerationPipeline`.

Recommended extension shape:

```text
app/extension/
  entrypoints/
    background.ts
    content.ts
    popup/
  utils/
    api.ts
    api-response-guards.ts
    backend-subtitle-state.ts
    job-polling.ts
    active-tracks.ts
    overlay.ts
    webvtt-track.ts
```

Recommended state/data flow:

- Keep all AI provider calls backend-only.
- Keep generated tracks as the API output contract.
- Keep artifacts as temporary queue handoff data, but type the cue/token payloads at write/read boundaries.
- Keep install ID ownership only until account auth lands; migrate ownership queries to account ID through `SubtitleJobService` instead of scattering it.
- Treat cost, tier, and budget telemetry as internal fields until billing/auth exists.

Recommended testing strategy:

- Keep current unit/feature/Vitest/contract suites.
- Add schema validation for actual backend responses.
- Add browser smoke coverage for the built extension.
- Add production runtime smoke: `subtitles:runtime-check --json --strict`, worker queue depth, scheduler, and a fake-provider generation path.

Recommended deployment/runtime improvements:

- CI workflow runs contracts, backend tests, extension tests, TypeScript compile, WXT build, docs lint, and diff checks.
- Production deployment defines separate web, worker, scheduler, and migration commands.
- Workers are supervised by platform/process manager, not web-request auto-start.
- Logs route through structured logging with a shared sanitizer.

## 14. Prioritized Refactoring Roadmap

### Immediate Fixes

1. Sanitize failure logging.

- Goal: prevent sensitive command/output/provider context from entering logs.
- Steps: add a sanitizer/allowlist to `SubtitleWorkflowLogger`; update tests around `processingFailed()` and audio acquisition failure context.
- Files/areas affected: `SubtitleWorkflowLogger`, `YouTubeAudioSourceTest`, `SubtitleWorkflowLoggerTest`.
- Risk: Low.
- Expected payoff: High security and operational confidence.

2. Update schema/doc freshness for generation fields.

- Goal: align generated docs with current migrations.
- Steps: update `docs/generated/db-schema.md` with `generation_tier` and `estimated_provider_cost_microusd`; add a lightweight reminder/check if migration docs drift is recurring.
- Files/areas affected: `docs/generated/db-schema.md`, optionally `scripts/agent/doc-gardening.ps1`.
- Risk: Low.
- Expected payoff: Better agent legibility.

3. Add backend contract response validation.

- Goal: prove Laravel responses match canonical schemas.
- Steps: validate representative `POST`, `GET`, history, and learning-token responses against `packages/contracts/schemas`.
- Files/areas affected: `ContractBoundaryTest`, possibly a small test helper.
- Risk: Low to medium.
- Expected payoff: Prevents API drift.

### Short-Term Refactors

1. Reduce queue job wrapper duplication.

- Goal: make queue retry/middleware/failure policy one reviewable pattern.
- Steps: add a trait/helper for `tries`, `maxExceptions`, `queuedAtMs`, and failure forwarding, or centralize stage failure behavior.
- Files/areas affected: `app/backend/app/Jobs/*`.
- Risk: Medium because queue serialization must be preserved.
- Expected payoff: Easier queue policy changes.

2. Add extension response guards.

- Goal: validate untrusted backend JSON at the extension boundary.
- Steps: create small guards for job/track/history/token responses or generate runtime validators from schemas.
- Files/areas affected: `app/extension/utils/api.ts`, new `api-response-guards.ts`, API tests.
- Risk: Medium because guards must match schema exactly.
- Expected payoff: Stronger contract boundary.

3. Lock or merge clicked-token updates.

- Goal: avoid losing concurrent token enrichments.
- Steps: use a transaction and row lock around `SubtitleTrack` cue update, or reload and merge changed tokens before saving.
- Files/areas affected: `LearningTokenEnrichmentService`, feature tests.
- Risk: Medium.
- Expected payoff: Better correctness under real user interaction.

### Medium-Term Architectural Improvements

1. Introduce typed cue/token data objects.

- Goal: reduce array-shape coupling in pipeline/provider/artifact code.
- Steps: start with read/write conversion in `SubtitleJobArtifactStore`; update provider validators to return typed results; keep API resources contract-shaped.
- Files/areas affected: `SubtitleJobArtifactStore`, `LaravelAiTranslationAnalysisProvider`, `TimestampedSubtitleTrackGenerator`, tests.
- Risk: Large refactor risk.
- Expected payoff: High maintainability.

2. Split AI provider stage validation.

- Goal: make tokenization, translation, romanization, enrichment, and clicked-token validation independently reviewable.
- Steps: extract input builders/result validators by stage while preserving one provider facade.
- Files/areas affected: `LaravelAiTranslationAnalysisProvider`, `LearningTokenOutputValidator`, agent tests.
- Risk: Medium.
- Expected payoff: Better maintainability as prompts/models evolve.

3. Split pipeline by real stage boundaries.

- Goal: keep async workflow orchestration readable as SaaS features arrive.
- Steps: extract transcription stage, analysis continuation, romanization merge, and finalization services only when touching those areas.
- Files/areas affected: `SubtitleGenerationPipeline`, jobs, tests.
- Risk: Medium to large.
- Expected payoff: Better evolvability.

### Long-Term Evolution

1. Add account-aware ownership.

- Goal: replace anonymous install ID as the durable owner boundary.
- Steps: introduce authenticated account identity, migrate reuse/cache keys through `SubtitleJobService`, preserve extension install throttling as an abuse signal.
- Files/areas affected: backend auth, extension auth, job service, migrations.
- Risk: Large.
- Expected payoff: Required for SaaS.

2. Add production runtime architecture.

- Goal: make paid usage operable.
- Steps: web/worker/scheduler deployment, CI, alerting, queue depth metrics, provider rate-limit monitoring, backup/retention policy.
- Files/areas affected: deployment docs/scripts, CI, ops config.
- Risk: Large.
- Expected payoff: Very high.

3. Consider normalized token metadata storage if write frequency grows.

- Goal: avoid full-track JSON update contention and enable user vocabulary features.
- Steps: only after account/vocabulary plans are concrete, move per-token enriched metadata into a child table or account-scoped cache.
- Files/areas affected: migrations, token enrichment service, resources.
- Risk: Large.
- Expected payoff: High if vocabulary/account features become central.

## 15. Scoring Breakdown

| Category | Max Points | Score | Reason |
|---|---:|---:|---|
| System Understanding and Conceptual Integrity | 10 | 9 | Purpose, product path, docs, and code align strongly around a clear extension-backed subtitle generation system. |
| Module Boundaries and Separation of Concerns | 15 | 12 | HTTP, extension, contracts, provider adapters, persistence, and telemetry are mostly separated; pipeline/provider/extension entrypoints are growing large. |
| Dependency Direction and Coupling | 15 | 12 | Dependencies generally flow from API to services to infrastructure; array payloads, static config/facades, and large orchestrators create coupling. |
| Data Flow and Workflow Clarity | 10 | 8 | Generation flow is traceable and explicit, with good run fences; JSON artifacts and string stages make some transitions fragile. |
| Domain Modeling and Business Logic Placement | 10 | 8 | Business rules live in appropriate services/validators and models are thin; cue/token domain concepts need stronger internal representation. |
| Testability | 10 | 8 | Strong unit/feature/contract/utility coverage; missing browser smoke, provider-backed runtime evidence, and schema checks against actual responses. |
| Scalability and Evolvability | 10 | 8 | Modular monolith is appropriate and can grow, but account/auth/billing/caching will stress current install-ID and JSON artifact boundaries. |
| Runtime, Deployment, and Operational Readiness | 7 | 5 | Local runtime and diagnostics are strong; CI, production deployment, supervised workers, and release smoke are absent. |
| Security and Reliability Architecture | 7 | 5 | Good validation, throttling, backend-only providers, run IDs, and cleanup; failure log sanitization and anonymous ownership are notable weaknesses. |
| Code Organization, Naming, and Developer Experience | 6 | 5 | Excellent docs/harness and mostly clear names; some generated docs are stale and several files are becoming large coordinators. |
| Total | 100 | 80 | Good architecture with production-readiness and async-complexity risks. |

Final Architecture Score: 80/100

Letter Grade: B+

Verdict: Good architecture with clear intent, strong local validation, and real modular boundaries, but not production-ready until operational gaps and async pipeline complexity are addressed.

## 16. Final Verdict

Is this architecture healthy?

Yes. The architecture is healthy for an early SaaS beta codebase. It has clear ownership boundaries, a contract-first API, backend-only provider integrations, focused tests, and useful observability.

Is it appropriate for the current project size?

Yes. The current modular Laravel monolith plus WXT extension is the right scale. A microservice split or full clean-architecture rewrite would be unnecessary.

Is it appropriate for future growth?

Mostly, but it needs targeted strengthening before account auth, billing, and higher-volume generation arrive. The most important growth risks are production operations, cue/token typing, JSON track mutation, and pipeline/provider class size.

Would I recommend continuing with this architecture?

Yes. Continue with this architecture, but avoid adding more stages directly into the existing large classes without first creating smaller stage/data boundaries.

Single highest-leverage improvement:

Harden failure logging and contract validation first. That protects users and operators immediately, and it reinforces the codebase's strongest architectural principle: validated, inspectable boundaries.
