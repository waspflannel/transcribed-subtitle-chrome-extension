# Detailed Design Document: YouTube AI Subtitle Learning Extension

Created: 2026-04-28

Source baseline: `revamped-design-document.md`

## 1. Status

This is a historical pre-refactor note, not the active implementation baseline.

The current product is a language-to-language YouTube subtitle app where users choose the subtitle/source language or Auto detect and choose the translation/target language for word cards. Current behavior is governed by `docs/product-specs/index.md`, `ARCHITECTURE.md`, and `docs/exec-plans/completed/2026-05-11-many-to-many-language-refactor.md`.

The Arabic-to-English framing below is retained only as old design history and must not be treated as the active product direction.

This file can still explain older tradeoffs, but future implementation should use the current docs above for scope and product decisions.

Backend technology stack is Laravel. The extension remains TypeScript because Chrome extension code runs in the browser.

## 2. Resolved Product Decisions

The following decisions are fixed for the detailed design:

| Area | Decision |
| --- | --- |
| Primary platform | YouTube watch pages only |
| Supported videos | Public YouTube videos only |
| Audio source | Backend-first YouTube audio acquisition |
| Audio fallback | Chrome tab capture may be added later as a fallback, not in the first implementation path |
| Backend acquisition policy | Approved for implementation |
| Maximum video length | 60 minutes for the first public version |
| Language scope | Contracts support multiple source and translation languages from day one |
| First product language path | Arabic speech with English translation |
| Dialect | Detect dialect in backend when available, store it, but hide it from users initially |
| Transcription provider | Provider abstraction, with `whisper-1` as the first candidate to validate |
| Translation and analysis provider | OpenAI through Laravel AI SDK as the first provider target |
| Persistence | Local persistent store first; real database/cache later during release hardening |
| Retention | Completed tracks retained for 30 days; raw audio deleted immediately after processing |
| Deletion controls | Local clear-state in MVP; backend deletion endpoint planned but not required for first release |
| Rate limiting identity | Anonymous extension install ID plus IP-based abuse limits |
| Subtitle editing | Out of scope for first release |
| Word interaction | Hover preview plus click/tap pinned detail |
| Romanization and gloss defaults | Translation visible; romanization and gloss configurable, default on |
| Overlay placement | Preset positions first: bottom, top, compact/collapsed |
| Implementation path | Reach real transcription as early as possible; refine contracts as evidence arrives |
| Backend stack | Laravel |
| Backend AI integration | Laravel AI SDK |
| Laravel development tooling | Laravel Boost |
| Contract strategy | Schema-first contracts shared by Laravel backend and TypeScript extension |

## 3. Product Goal

Build a Chrome extension that lets a user open a public YouTube video, generate AI subtitles from the video's audio, and study the spoken language through a synchronized learning overlay.

The first polished product experience focuses on Arabic learning:

```text
YouTube public video
  -> backend audio acquisition
  -> timestamp-capable AI transcription
  -> generated subtitle cues
  -> English translation
  -> Arabic word analysis
  -> synchronized extension overlay
```

The product should feel like a purpose-built language learning layer on top of YouTube, not a transcript dump or debug tool.

## 4. Non-Goals

The first release will not include:

- Netflix or other video platforms.
- Private, login-gated, age-restricted, or region-restricted YouTube videos.
- Live captioning.
- Chrome tab audio capture as the main path.
- Direct OpenAI or provider calls from the extension.
- User accounts.
- Cloud sync.
- Vocabulary review queues.
- Subtitle editing or correction workflows.
- Mobile, Safari, or Firefox support.
- Replacing YouTube's native subtitle renderer.

These are excluded to keep the first implementation focused on the core value: generated subtitles plus learning data on public YouTube videos.

## 5. System Overview

```text
Chrome Extension
  Popup
    - shows current video
    - starts generation
    - shows job status
    - exposes basic settings

  Content Script
    - detects YouTube watch page changes
    - finds the active video element
    - reads current playback time
    - renders the overlay
    - handles word hover/click interactions

  Background Service Worker
    - coordinates extension messages
    - stores local settings and install identity
    - calls proxy APIs

Proxy Layer
  - validates extension-facing requests
  - enforces extension policy
  - rate limits by install ID and IP
  - forwards valid requests to backend application services
  - normalizes public errors

Laravel Backend
  - owns job lifecycle
  - obtains YouTube audio
  - deletes raw audio after processing
  - uses Laravel queues for long-running work
  - uses Laravel AI SDK for supported AI provider calls
  - normalizes transcript timestamps
  - creates subtitle cues
  - translates cues
  - analyzes Arabic words
  - validates all outputs
  - stores completed tracks for 30 days

Providers
  - OpenAI speech-to-text provider, through Laravel AI SDK when it satisfies timestamp requirements
  - OpenAI text model for translation and analysis, through Laravel AI SDK
```

The extension owns browser behavior and UI. The Laravel backend owns AI, persistence, validation, caching, queues, and provider cost controls. The proxy layer is the public boundary between the extension and backend services.

The first implementation can run proxy and backend services inside one Laravel application. They should remain separate modules in code so they can be split later if deployment or scale requires it.

## 6. User Experience

### 6.1 First-Time Video Flow

1. User opens a public YouTube watch page.
2. Content script detects the video ID and active video element.
3. Popup and overlay show that no generated track is ready.
4. User clicks `Generate AI Subtitles`.
5. Extension sends a job request through the proxy.
6. Proxy validates request, install ID, origin, and rate limits.
7. Backend creates a job or returns an existing compatible job.
8. Backend acquires audio for the public YouTube video.
9. Backend transcribes audio with timestamps.
10. Backend segments or normalizes the transcript into subtitle cues.
11. Backend translates cues.
12. Backend analyzes words for learning metadata.
13. Backend stores the generated track.
14. Extension polls status until the job is ready or failed.
15. Extension loads the track.
16. Overlay renders active cues based on `video.currentTime`.

### 6.2 Repeat Video Flow

1. User opens a video with a compatible generated track.
2. Extension checks local metadata and then backend cache.
3. Backend returns ready track metadata or full track.
4. Overlay can display subtitles without a new generation job.

### 6.3 Failure Flow

The UI must never fail silently. It must show a clear state for:

- unsupported page
- unsupported video type
- video longer than allowed duration
- job already queued
- rate limited
- audio unavailable
- audio acquisition failed
- transcription failed
- segmentation failed
- translation failed
- analysis failed
- validation failed
- processing timeout
- cached track unavailable
- network unavailable

Each failure shown to users should be short and actionable. Detailed diagnostics belong in structured logs and optional debug surfaces.

## 7. UI Design Requirements

### 7.1 Popup

The popup is the command and status surface.

It must show:

- current page support status
- platform and video ID when available
- video title if available without brittle scraping
- current track status
- `Generate AI Subtitles` action when allowed
- current job status and simple progress text
- basic settings:
  - overlay visibility
  - overlay position
  - romanization on/off
  - gloss on/off

The popup must not be the primary learning surface and must not become a diagnostic console.

### 7.2 In-Page Overlay

The overlay is the learning surface.

It must show:

- active source subtitle line
- English translation below it
- word hover preview
- word click/tap pinned detail
- compact controls for:
  - hide/show
  - bottom position
  - top position
  - compact/collapsed mode

Romanization and glosses default on but can be disabled by the user.

The overlay must be isolated from YouTube styling with Shadow DOM or an equivalent isolation boundary.

### 7.3 Word Detail Behavior

Hover preview:

- appears when the pointer rests on a token
- shows compact meaning/gloss and romanization when enabled
- disappears on pointer exit

Click/tap pinned detail:

- opens a stable detail panel for the selected token
- remains open until dismissed, another token is selected, or the cue changes
- shows available fields:
  - token text
  - lemma
  - root
  - part of speech
  - romanization
  - gloss
  - short usage note

If a field is unavailable, the UI omits it instead of showing placeholders like `null`.

## 8. Extension Design

### 8.1 Extension Components

The extension should use these components:

```text
manifest
  - permissions and extension metadata

background service worker
  - lifecycle coordination
  - proxy API calls
  - install ID creation
  - local settings storage

content script
  - YouTube page detection
  - video element detection
  - overlay mounting
  - playback synchronization

popup
  - command surface
  - job status
  - basic settings

shared extension modules
  - contracts
  - message types
  - API client
  - settings model
```

### 8.2 YouTube Detection

The content script must detect YouTube watch pages by URL and page state.

Supported URL shape:

```text
https://www.youtube.com/watch?v={videoId}
```

The video ID must be parsed from the URL query parameter `v`.

The content script must handle YouTube single-page navigation by listening for URL and DOM changes. A full page reload must not be required when the user moves between videos.

### 8.3 Active Video Element

The content script must find the active HTML video element on the watch page.

Requirements:

- tolerate late video element creation
- re-check after YouTube route changes
- avoid binding duplicate listeners to the same element
- clean up listeners when the video changes

### 8.4 Extension Local State

The extension may store:

- anonymous install ID
- user settings
- last known video ID
- last known job ID per video/language/version
- completed track metadata

The extension should not store full tracks locally in the first implementation unless backend latency makes it necessary.

### 8.5 Extension Install ID

The extension creates one random anonymous install ID on first run.

Requirements:

- no user account required
- no email or personal identity
- stable enough for rate limiting
- reset if the user clears extension storage

The install ID is sent to the proxy with job and status requests.

## 9. Playback Synchronization

The generated track uses YouTube video timeline seconds.

Active cue selection:

```text
active cue = cue where startSeconds <= video.currentTime < endSeconds
```

The sync runtime should:

- listen to `timeupdate`
- listen to `play`
- listen to `pause`
- listen to `seeking`
- run a lightweight animation loop while video is playing
- avoid DOM updates when the active cue has not changed

The extension must handle:

- user seeks before track load completes
- video changes without full page reload
- playback speed changes
- paused state
- missing cue at current time
- track duration differing from YouTube duration

If a track appears offset from the video timeline, the first release should log diagnostics. User correction tools are out of scope.

## 10. Proxy Design

The proxy is the only network API surface the extension calls.

Responsibilities:

- validate request shape
- validate expected extension headers or identity signals
- enforce rate limits
- apply IP-based abuse controls
- reject unsupported platforms
- reject malformed video IDs
- reject unsupported language pairs if configured
- forward valid requests to backend
- return stable public error responses

The proxy should not:

- call AI providers directly
- own the job state machine
- store generated tracks
- expose backend internals to the extension

## 11. Backend Design

### 11.1 Backend Responsibilities

The backend owns:

- job creation and lookup
- idempotency by video/language/version
- audio acquisition
- raw audio cleanup
- transcription provider calls
- transcript normalization
- cue segmentation
- translation
- Arabic analysis
- output validation
- local persistent storage
- cache expiration
- track serving
- structured diagnostics

### 11.2 Backend Stack

The backend stack is Laravel.

Required Laravel components:

- Laravel HTTP routes/controllers for the proxy-facing API.
- Laravel Form Request validation or equivalent request validation.
- Laravel queues for subtitle generation jobs.
- Laravel scheduler for expiration cleanup.
- Laravel migrations and Eloquent models for local persistent storage.
- SQLite as the first storage engine.
- Laravel AI SDK for supported AI provider calls.
- Laravel Boost as development tooling for Laravel-aware agent context, documentation lookup, app inspection, logs, routes, config, and database schema.

The backend should be one Laravel application at first, with separate namespaces/modules for proxy-facing API code and backend job orchestration code.

Recommended backend structure:

```text
apps/backend
  app/Http/Controllers/Api
  app/Http/Requests
  app/Jobs
  app/Models
  app/Services/SubtitleJobs
  app/Services/Audio
  app/Services/Transcription
  app/Services/TranslationAnalysis
  app/Services/Tracks
  app/Ai/Agents
  app/Ai/Tools
  database/migrations
  tests
```

The Laravel implementation must still obey the contracts in this document. Framework models, queue payloads, provider responses, and Eloquent records are internal details and must not become extension-facing contracts.

## 12. Audio Acquisition

### 12.1 First Implementation Path

The first implementation uses backend YouTube audio acquisition for public videos.

Flow:

```text
videoId
  -> backend validates public YouTube URL
  -> backend obtains audio
  -> backend normalizes audio if needed
  -> backend sends audio to transcription provider
  -> backend deletes raw audio immediately after processing
```

### 12.2 Constraints

The backend must reject:

- non-YouTube platforms
- missing video IDs
- invalid video IDs
- non-public videos
- videos longer than 60 minutes
- videos whose audio cannot be acquired

### 12.3 Raw Audio Retention

Raw audio is temporary processing data.

Rules:

- raw audio may exist only while a job is processing
- raw audio must be deleted after transcription succeeds or fails
- raw audio path or object ID may be logged
- raw audio content must not be retained for cache purposes

### 12.4 Future Tab Capture Fallback

Chrome tab capture is a future fallback, not part of the first product path.

It may be added if backend acquisition proves unreliable for some supported cases. Adding it later must not change track contracts or overlay synchronization.

## 13. Transcription

### 13.1 Provider Interface

The backend should hide transcription behind an interface:

```text
TranscriptionProvider
  transcribe(input: AudioInput, options: TranscriptionOptions)
    -> TimestampedTranscript
```

The first provider candidate to validate is `whisper-1`.

Laravel AI SDK is the preferred integration path for transcription because it is the first-party Laravel AI package and supports speech-to-text provider calls. The implementation must verify that the SDK exposes the timestamped segment data required by `TimestampedTranscript`.

If Laravel AI SDK does not expose segment timestamps, verbose transcription output, or the provider options needed for subtitle sync, the backend must use a Laravel-side OpenAI transcription adapter for this one provider call. That adapter still lives inside the Laravel backend, uses backend-held secrets, and returns the same internal `TimestampedTranscript` contract.

The implementation should reach real transcription early. A mock provider can exist for tests and local demos, but the project should not spend multiple phases building around mocks before validating real audio and timestamps.

### 13.2 Required Transcription Output

The transcription result must provide timestamped text suitable for subtitle sync.

Minimum accepted result:

```json
{
  "language": "ar",
  "durationSeconds": 123.45,
  "segments": [
    {
      "startSeconds": 0.84,
      "endSeconds": 3.2,
      "text": "ARABIC_TEXT"
    }
  ]
}
```

Word timestamps are useful but not required for the first release.

### 13.3 Transcript Normalization

Backend normalization must:

- trim empty segments
- ensure numeric start/end seconds
- ensure `startSeconds < endSeconds`
- sort segments by start time
- prevent overlapping cue output when possible
- clamp invalid timestamps where safe
- fail validation when timestamps are unusable

## 14. Cue Segmentation

The backend produces readable subtitle cues from transcript segments.

Initial segmentation should stay simple:

- preserve provider segment timing when it is readable
- merge very short adjacent segments when safe
- split overly long text segments when safe
- avoid complex linguistic segmentation in the first version

Recommended cue constraints:

- target duration: 1.5 to 7 seconds
- soft max source characters per cue: 120
- hard max source characters per cue: 180
- no zero-duration cues

These numbers are implementation guardrails, not user-facing promises.

## 15. Translation And Arabic Analysis

### 15.1 Provider Choice

Translation and word analysis use OpenAI through Laravel AI SDK in the first implementation.

Provider calls must never happen from the extension.

### 15.2 Provider Interface

The backend should expose a single enrichment interface:

```text
TranslationAnalysisProvider
  enrich(cues: CueDraft[], options: EnrichmentOptions)
    -> EnrichedCue[]
```

The provider may internally use one or more Laravel AI SDK agents or prompts. The backend API and extension must not depend on the provider's internal prompt structure.

The preferred implementation is a dedicated Laravel AI agent for cue enrichment with structured output matching the validated enriched cue contract. The agent should be kept narrow: translate finalized cues and return learning metadata. It should not own job state, storage, rate limiting, or overlay decisions.

### 15.3 Enrichment Requirements

For each cue, enrichment must return:

- source text
- translation text
- token list when the source language is Arabic

For each Arabic token, enrichment should return:

- text
- lemma when available
- root when available
- part of speech when available
- romanization when available
- gloss when available
- short usage note when useful

The provider output must be validated before storage.

### 15.4 Dialect Handling

The backend may detect dialect when provider output supports it.

Rules:

- store dialect as metadata
- use `unknown` when unavailable
- do not show dialect in normal user UI for the first release
- logs may include dialect value as metadata

## 16. Canonical Data Model

### 16.1 Track

```json
{
  "schemaVersion": 1,
  "trackId": "track_abc123",
  "video": {
    "platform": "youtube",
    "videoId": "dQw4w9WgXcQ",
    "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
    "durationSeconds": 212.4
  },
  "language": {
    "source": "ar",
    "translation": "en",
    "dialect": "unknown"
  },
  "processing": {
    "audioSource": "youtube-backend",
    "transcriber": "whisper-1",
    "transcriberVersion": "provider-managed",
    "transcriptionIntegration": "laravel-ai-sdk",
    "segmentationVersion": "subtitle-segmentation-v1",
    "translationProvider": "openai",
    "translationIntegration": "laravel-ai-sdk",
    "translationVersion": "translation-analysis-v1",
    "analysisProvider": "openai",
    "analysisIntegration": "laravel-ai-sdk",
    "analysisVersion": "arabic-analysis-v1"
  },
  "retention": {
    "createdAt": "2026-04-28T00:00:00Z",
    "expiresAt": "2026-05-28T00:00:00Z",
    "rawAudioRetained": false
  },
  "cues": []
}
```

### 16.2 Cue

```json
{
  "cueId": "cue_000001",
  "startSeconds": 0.84,
  "endSeconds": 3.2,
  "sourceText": "ARABIC_TEXT",
  "translation": "English translation",
  "tokens": []
}
```

### 16.3 Token

```json
{
  "tokenId": "cue_000001_tok_000",
  "text": "ARABIC_TOKEN",
  "lemma": "LEMMA_OR_NULL",
  "root": "ROOT_OR_NULL",
  "partOfSpeech": "noun",
  "romanization": "romanized-token",
  "gloss": "short meaning",
  "usageNote": "short note or null"
}
```

### 16.4 Job

```json
{
  "jobId": "job_abc123",
  "status": "transcribing",
  "progress": {
    "stage": "transcribing",
    "percent": 35,
    "message": "Generating AI subtitles"
  },
  "request": {
    "platform": "youtube",
    "videoId": "dQw4w9WgXcQ",
    "sourceLanguage": "ar",
    "translationLanguage": "en"
  },
  "trackId": null,
  "error": null,
  "createdAt": "2026-04-28T00:00:00Z",
  "updatedAt": "2026-04-28T00:01:00Z"
}
```

## 17. Job Lifecycle

Job states:

```text
created
queued
acquiring_audio
transcribing
segmenting
translating
analyzing
validating
ready
failed
cancelled
expired
```

State rules:

- `created` is short-lived.
- `queued` means the backend accepted work but has not started processing.
- `ready` means a validated track exists.
- `failed` must include a stable error code.
- `expired` means the generated track or job record is no longer available.
- `cancelled` is reserved for future cancellation support.

The first implementation can use polling. Server-sent events or WebSockets are not needed until polling creates a measured problem.

## 18. API Contracts

The extension calls the proxy. The proxy forwards valid requests to backend equivalents.

Contracts are schema-first because the backend is Laravel/PHP and the extension is TypeScript.

Canonical contract files should live outside framework-specific code:

```text
packages/contracts
  openapi.yaml
  schemas/subtitle-job.schema.json
  schemas/subtitle-track.schema.json
  schemas/error.schema.json
```

Laravel request/response validation and TypeScript extension types must be generated from, or checked against, these canonical schemas. Do not maintain separate PHP and TypeScript definitions by hand when a shared schema can define the boundary.

### 18.1 Create Subtitle Job

```text
POST /subtitle-jobs
```

Request:

```json
{
  "platform": "youtube",
  "videoId": "dQw4w9WgXcQ",
  "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
  "sourceLanguage": "ar",
  "translationLanguage": "en"
}
```

Response:

```json
{
  "jobId": "job_abc123",
  "status": "queued",
  "trackId": null
}
```

Behavior:

- If a compatible ready track exists, return a ready job or track reference.
- If a compatible job is already running, return the existing job.
- If the video is unsupported, return a stable error.

### 18.2 Get Job Status

```text
GET /subtitle-jobs/{jobId}
```

Response:

```json
{
  "jobId": "job_abc123",
  "status": "transcribing",
  "progress": {
    "stage": "transcribing",
    "percent": 35,
    "message": "Generating AI subtitles"
  },
  "trackId": null,
  "error": null
}
```

### 18.3 Get Track

```text
GET /subtitle-tracks/{trackId}
```

Response:

```json
{
  "schemaVersion": 1,
  "trackId": "track_abc123",
  "video": {
    "platform": "youtube",
    "videoId": "dQw4w9WgXcQ",
    "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
    "durationSeconds": 212.4
  },
  "language": {
    "source": "ar",
    "translation": "en",
    "dialect": "unknown"
  },
  "processing": {},
  "retention": {},
  "cues": []
}
```

### 18.4 Lookup Track By Video

```text
GET /subtitle-tracks/lookup?platform=youtube&videoId=dQw4w9WgXcQ&sourceLanguage=ar&translationLanguage=en
```

Response when found:

```json
{
  "found": true,
  "trackId": "track_abc123",
  "status": "ready",
  "expiresAt": "2026-05-28T00:00:00Z"
}
```

Response when missing:

```json
{
  "found": false
}
```

### 18.5 Clear Local State

This is extension-local behavior, not a backend API.

The user can clear:

- local settings
- local job references
- local track metadata
- anonymous install ID

Backend deletion is planned for later but not required in the first release.

## 19. Error Model

Public errors must be stable and safe to show.

Recommended shape:

```json
{
  "code": "audio_unavailable",
  "message": "Audio is unavailable for this video.",
  "retryable": false
}
```

Initial error codes:

```text
unsupported_page
unsupported_platform
unsupported_video_type
video_too_long
invalid_video_id
rate_limited
job_not_found
track_not_found
audio_unavailable
audio_acquisition_failed
transcription_failed
segmentation_failed
translation_failed
analysis_failed
validation_failed
processing_timeout
provider_unavailable
network_error
internal_error
```

Internal logs may include more detail. Public responses must not leak provider secrets, stack traces, local file paths, or raw transcript dumps.

## 20. Caching And Storage

### 20.1 Backend Cache Key

Track compatibility is determined by:

- platform
- video ID
- source language
- translation language
- audio source version
- transcription provider
- transcription provider version when known
- segmentation version
- translation provider/version
- analysis provider/version
- schema version

### 20.2 Local Persistent Store

The first backend implementation should use Laravel migrations, Eloquent models, and SQLite local persistent storage rather than pure memory.

The store must support:

- job records
- track records
- cache lookup by compatibility key
- 30-day expiration
- cleanup of expired records

A production database/cache can replace SQLite later behind Laravel repositories when release hardening requires it.

### 20.3 Expiration

Completed tracks expire after 30 days.

When a track expires:

- lookup should return missing or expired
- the extension may offer generation again
- stale local metadata should be ignored after backend says the track is unavailable

## 21. Rate Limits And Cost Controls

The system must enforce:

- max video length: 60 minutes
- job creation rate limit by anonymous install ID
- abuse limits by IP
- duplicate-job coalescing for the same video/language/version
- provider timeout handling
- provider error normalization

Suggested initial limits can be finalized during implementation, but the architecture must include the enforcement points from the start.

## 22. Privacy And Security

Requirements:

- no provider keys in the extension
- all provider calls happen server-side
- user explicitly starts generation
- supported scope is public YouTube videos only
- raw audio deleted immediately after processing
- completed tracks retained for 30 days
- local clear-state control exists in MVP
- logs avoid full transcript dumps by default
- public errors do not expose internals
- extension install ID is anonymous

The UI copy must clearly communicate that video audio/text is processed by backend and AI services when the user starts generation.

## 23. Observability

Use structured event names and stable IDs.

Important events:

```text
extension.youtube_page_detected
extension.video_id_changed
extension.overlay_mounted
extension.generate_clicked
extension.job_status_received
extension.track_loaded
extension.sync_drift_detected

proxy.request_received
proxy.request_rejected
proxy.rate_limited
proxy.forwarded_to_backend

backend.job_created
backend.cache_hit
backend.audio_acquisition_started
backend.audio_acquisition_completed
backend.audio_acquisition_failed
backend.transcription_started
backend.transcription_completed
backend.transcription_failed
backend.segmentation_completed
backend.translation_started
backend.translation_completed
backend.analysis_started
backend.analysis_completed
backend.validation_failed
backend.track_ready
backend.track_expired
```

Each job log should include:

- job ID
- video ID
- source language
- translation language
- stage
- provider name when relevant
- duration/cost metadata when available
- error code when failed

Avoid logging raw audio, full transcripts, and full provider prompts by default.

## 24. Testing Strategy

### 24.1 Contract Tests

Validate:

- request schemas
- job status schemas
- track schemas
- cue timestamps
- token objects
- error responses

### 24.2 Backend Tests

Validate:

- job state transitions
- duplicate job coalescing
- cache lookup
- expiration
- video length rejection
- audio acquisition failure handling
- provider failure handling
- raw audio cleanup path
- track validation before storage
- Laravel AI SDK fakes for translation, analysis, and transcription where supported
- Laravel queue behavior for subtitle processing jobs

### 24.3 Extension Tests

Validate:

- YouTube URL parsing
- route/video ID changes
- video element detection
- message passing
- polling behavior
- active cue selection
- overlay state rendering
- settings persistence

### 24.4 Integration Tests

Validate:

- extension to proxy to backend job creation
- ready-track lookup
- status polling
- track loading
- overlay synchronization against a known test track

### 24.5 Real Provider Proof Tests

As early as practical, test real transcription on a small set of public YouTube videos:

- short clear Arabic speech
- noisy Arabic speech
- dialect-heavy Arabic speech
- music/background-noise case
- long video near the 60-minute limit

The goal is to validate timestamp quality, segmentation quality, cost, latency, and failure behavior.

### 24.6 Laravel Boost Workflow

After the Laravel backend is scaffolded, install Laravel Boost in development mode.

Boost should be used to:

- inspect Laravel application info
- inspect routes
- inspect config
- inspect database schema
- read recent errors/logs
- query current Laravel package documentation

Boost is development tooling. It is not a runtime dependency for the Chrome extension product behavior.

## 25. Implementation Slices

These slices are written so they can later become phased implementation plans.

### Slice 1: Baseline Contracts And Extension Shell

Deliver:

- monorepo structure
- WXT TypeScript extension scaffold
- Laravel backend scaffold
- Laravel AI SDK installed and configured
- Laravel Boost installed as a dev dependency
- canonical job and track contract files
- YouTube video ID detection
- active video element detection
- popup support state
- overlay mount/unmount
- local settings model
- anonymous install ID

Keep this slice thin. Do not build the full overlay learning UI before backend data exists.

### Slice 2: Proxy And Backend Job Skeleton

Deliver:

- Laravel proxy create/status/lookup routes
- backend job state machine
- SQLite local persistent store
- Laravel queue job for subtitle processing
- rate limit hooks
- stable error model
- mock ready track for end-to-end UI sync

The mock track exists only to prove extension-to-backend flow and overlay sync.

### Slice 3: Real Audio Acquisition And Transcription Proof

Deliver:

- backend YouTube audio acquisition for public videos
- 60-minute duration enforcement
- raw audio cleanup
- transcription provider interface
- first real `whisper-1` proof through Laravel AI SDK when timestamp requirements are met
- Laravel-side OpenAI transcription adapter only if Laravel AI SDK cannot expose required timestamped output
- transcript normalization
- failure diagnostics

This slice intentionally reaches real transcription early.

### Slice 4: Cue Generation And Track Validation

Deliver:

- segmentation rules
- validated track schema
- backend track storage
- track lookup by video/language/version
- extension track loading
- synchronized overlay with source text

### Slice 5: Translation And Learning Data

Deliver:

- Laravel AI SDK OpenAI-backed translation/analysis provider
- dedicated cue-enrichment agent with structured output
- validated cue translations
- Arabic token analysis
- stored dialect metadata hidden from UI
- hover preview
- click/tap pinned detail
- romanization/gloss settings

### Slice 6: Release Hardening

Deliver:

- 30-day expiration cleanup
- duplicate job coalescing
- stronger rate limits
- user-facing failure states
- structured logs
- privacy copy
- visual QA on YouTube pages
- public-video acceptance test set

## 26. Acceptance Criteria

The first release is complete when:

- extension detects public YouTube watch pages
- extension extracts the video ID
- extension finds the active video element
- user can start generation from the popup
- proxy validates and forwards the request
- Laravel backend creates or reuses a job
- backend rejects unsupported or too-long videos
- backend obtains audio for public YouTube videos
- backend deletes raw audio after processing
- backend transcribes audio with timestamps
- backend produces validated subtitle cues
- backend translates cues
- backend analyzes Arabic tokens
- backend stores completed tracks for 30 days
- extension can poll job status
- extension can load ready tracks
- overlay shows source subtitle and English translation
- hover preview works for tokens
- click/tap pinned detail works for tokens
- romanization and gloss settings work
- overlay syncs during play, pause, and seek
- completed tracks are reused
- provider keys are not exposed in extension code
- failures are visible and diagnosable
- Laravel AI SDK is used for supported AI provider calls
- Laravel Boost is installed for development-time Laravel context and documentation tooling

## 27. Stack Decision

The backend stack is Laravel.

Selected stack:

```text
Extension
  - WXT
  - TypeScript

Backend
  - Laravel
  - Laravel queues
  - Laravel scheduler
  - Laravel migrations and Eloquent
  - SQLite first
  - Laravel AI SDK
  - Laravel Boost as dev tooling

Contracts
  - OpenAPI / JSON Schema canonical files
  - TypeScript types generated or checked from schemas
  - Laravel request/response validation checked against schemas
```

The stack must preserve:

- extension/proxy/backend separation in code
- backend-only provider calls
- canonical schema-first contracts
- SQLite local persistent store first
- 30-day track retention
- immediate raw audio deletion
- simple polling-based job status
- provider abstraction for transcription
- Laravel AI SDK as the preferred AI provider integration
- OpenAI as first translation/analysis provider target

## 28. Design Guardrails

Keep the implementation simple:

- one public product path first
- one backend audio source first
- one transcription candidate first
- one translation/analysis provider first
- Laravel AI SDK before custom AI integration, unless the SDK cannot satisfy timestamped subtitle contracts
- polling before push updates
- preset overlay positions before drag/resize
- local persistent store before production database complexity
- no subtitle editing in first release
- no user accounts in first release

The system is allowed to be powerful, but each phase should ship the smallest working version of the next product capability.
