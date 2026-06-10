# Revamped Design Document: YouTube AI Subtitle Learning Extension

Created: 2026-04-28

## Status

This is a historical pre-refactor note, not the active product baseline.

The current product is a language-to-language YouTube subtitle app where users choose the subtitle/source language or Auto detect and choose the translation/target language for word cards. Current behavior is governed by `docs/product-specs/index.md`, `ARCHITECTURE.md`, and `docs/exec-plans/completed/2026-05-11-many-to-many-language-refactor.md`.

The Arabic-to-English framing below is retained only as old design history and must not be treated as the active product direction.

## Executive Summary

The project will focus on YouTube first and generate its own subtitle track from video audio instead of depending on YouTube's existing captions.

The core product becomes:

```text
YouTube video audio
  -> AI transcription with timestamps
  -> clean Arabic subtitle segments
  -> English translation
  -> Arabic word-level learning analysis
  -> synchronized in-page overlay
```

This is a better fit for the product because many videos have missing, inaccurate, poorly segmented, or auto-generated captions. The new approach accepts a per-video processing delay in exchange for higher quality subtitles, better translations, and a consistent learning UI.

The application target is YouTube only. Netflix and other platforms are out of scope for the final application described by this document.

## Product Goal

Build a Chrome extension that lets a user generate high-quality AI subtitles for a YouTube video, then study the Arabic through an overlay that stays synchronized with playback.

The user should eventually see:

```text
[Arabic subtitle line]
[English translation]

Hover/click Arabic word:
  - meaning
  - lemma/root when available
  - part of speech
  - romanization when enabled
  - short gloss or usage note
```

The user experience should feel like a purpose-built Arabic learning layer on top of YouTube, not a generic transcript viewer.

## Why The Direction Changed

The original approach depended on existing platform captions:

```text
YouTube or Netflix caption text
  -> extension extracts current cue
  -> backend analyzes cue
  -> overlay renders learning data
```

That approach is simpler, but it inherits platform caption problems:

- some videos have no captions
- auto captions can be inaccurate
- caption segmentation can split sentences badly
- translations may be poor or unavailable
- subtitle extraction is brittle across site changes
- Netflix support adds DRM and platform complexity before the core product is proven

The revamped approach uses audio as the source of truth:

```text
video audio
  -> transcription
  -> generated subtitle track
  -> translation and Arabic analysis
  -> overlay
```

This gives the product more control over subtitle quality, timing, segmentation, and learning output.

## Application Scope

### In Scope

- YouTube watch pages only.
- Public YouTube videos only.
- User-triggered subtitle generation.
- Async processing with visible job status.
- Generated subtitle track with start/end timestamps.
- Arabic source subtitles.
- English translation below the Arabic line.
- Word-level hover/click data for Arabic learning.
- Backend-side transcription, translation, analysis, caching, and provider calls.
- Extension-side playback synchronization and overlay rendering.

### Out Of Scope

- Netflix.
- Other video platforms.
- Real-time live captioning.
- DRM-protected media.
- Direct OpenAI/provider calls from the extension.
- Replacing YouTube's native caption renderer.
- Full vocabulary review system.
- User accounts and cloud sync.
- Mobile or Safari support.

## Product Flow

### First-Time Video Flow

1. User opens a YouTube video.
2. Extension detects the YouTube video ID and current playback state.
3. User clicks `Generate AI Subtitles`.
4. Extension sends a subtitle job request through the proxy.
5. Backend creates or finds a processing job for that video.
6. Backend obtains/processes the video audio.
7. Backend transcribes audio into timestamped Arabic subtitle segments.
8. Backend translates each segment into English.
9. Backend performs Arabic word-level analysis.
10. Extension shows progress while processing.
11. When ready, extension loads the generated subtitle track.
12. Overlay displays the active subtitle segment based on `video.currentTime`.

### Repeat Video Flow

1. User opens a YouTube video that already has a completed track.
2. Extension checks backend cache by video identity and processing version.
3. Backend returns the ready track metadata or full track.
4. Overlay can start immediately without reprocessing.

### Failure Flow

The UI must show clear states for:

- unsupported page
- job already queued
- audio unavailable
- transcription failed
- translation failed
- analysis failed
- rate limited
- processing timeout
- cached track unavailable

Failures should not leave the extension in a silent or ambiguous state.

## User Experience Model

The extension should have two user-facing surfaces.

### Popup

The popup is the command surface.

It should show:

- current YouTube video identity
- whether AI subtitles already exist
- a `Generate AI Subtitles` action
- job status and estimated progress when processing
- basic settings, such as romanization and gloss visibility

The popup should not be the main learning interface.

### In-Page Overlay

The overlay is the learning surface.

It should show:

- active Arabic subtitle line
- English translation below it
- loading/processing states when no track is ready
- hover/click interactions for words
- compact controls for hiding, positioning, or collapsing the overlay

The overlay should avoid debug controls in normal mode. Developer diagnostics can exist behind debug mode but should not dominate the product UI.

## Core Architecture

```text
Chrome Extension
  popup
    -> starts jobs and shows status

  content script
    -> detects YouTube video/page state
    -> reads video.currentTime
    -> renders synchronized overlay

  background service worker
    -> coordinates extension lifecycle and messages

  optional offscreen document
    -> future fallback for tab audio capture if needed

Proxy
  -> validates and rate limits extension requests
  -> forwards allowed requests to backend

Backend
  -> owns job lifecycle
  -> obtains/processes YouTube audio
  -> calls transcription provider
  -> segments subtitle track
  -> translates Arabic to English
  -> performs Arabic analysis
  -> validates contracts
  -> caches results

Providers
  -> speech-to-text model
  -> translation model/service
  -> Arabic analysis model/service
```

## Key Design Decisions

### Decision 1: YouTube Only

The revamped application is YouTube only.

This removes Netflix-specific complexity and keeps the final product focused on the core value: high-quality AI-generated YouTube subtitles plus Arabic learning data.

### Decision 2: Generated Subtitles Are The Source Of Truth

The extension should not depend on YouTube captions for the core product.

Existing captions may be useful for comparison, fallback, or future validation, but the main subtitle track should come from our transcription pipeline.

### Decision 3: Async Processing Is Acceptable

The user can wait per video if the output is significantly better.

The UI should make this explicit:

```text
Generating AI subtitles...
This can take a few minutes for longer videos.
```

The product should reward the wait by caching completed tracks.

### Decision 4: Backend Owns Provider Calls

The extension must not call OpenAI or any transcription provider directly.

Reasons:

- protect API keys
- centralize cost controls
- enforce rate limits
- cache work across sessions
- normalize provider failures
- validate all output before UI rendering

### Decision 5: Timestamped Tracks Drive Sync

The backend should produce subtitle cues with absolute video timestamps:

```json
{
  "startSeconds": 42.12,
  "endSeconds": 45.8,
  "sourceText": "أنا أتعلم اللغة العربية",
  "translation": "I am learning Arabic"
}
```

The extension should sync by comparing each cue to the YouTube video element's `currentTime`.

## Audio Acquisition Strategy

This is the largest unresolved implementation choice.

### Preferred Direction: Backend Audio Acquisition

For YouTube, the ideal user experience is:

```text
extension sends YouTube video ID
  -> backend obtains audio
  -> backend processes full video asynchronously
```

This supports a Trancy-like user experience where the user clicks once and waits a few minutes, without needing to play through the full video in the browser.

This path needs explicit legal, policy, and reliability review before production use.

Risks:

- YouTube terms and platform policy
- audio extraction brittleness
- region/login/age-restricted videos
- long videos and large files
- provider upload limits

### Fallback Direction: Chrome Tab Audio Capture

Chrome extensions can capture tab audio with user action and the appropriate extension permissions. In Manifest V3, this may involve `chrome.tabCapture`, a service worker, and an offscreen document.

This is more browser-native, but worse for full-video processing because the user may need to play the audio for it to be captured.

Best use cases:

- short proof of concept
- inaccessible backend audio
- future live or near-live mode

### Recommendation

Implement the system behind an audio-source abstraction:

```text
AudioSourceProvider
  -> YouTubeBackendAudioSource
  -> TabCaptureAudioSource later if needed
```

The first full-product path should target backend acquisition for public YouTube videos, while a short tab-capture proof can de-risk Chrome APIs separately.

## Transcription Strategy

The transcription provider must produce timestamps suitable for subtitle sync.

Useful output formats include:

- segment timestamps
- word timestamps
- SRT
- VTT
- verbose JSON

For the core application pipeline, prefer a timestamp-capable transcription path. `whisper-1` is a practical first candidate because current OpenAI speech-to-text documentation supports subtitle/timestamp-oriented outputs such as SRT, VTT, verbose JSON, and timestamp granularities for Whisper.

Newer models such as `gpt-4o-transcribe` should be evaluated for transcription quality as a future expansion, but the core design must not assume they provide the same subtitle-ready timestamp outputs unless verified.

The core application pipeline should optimize for reliable sync first:

```text
audio
  -> whisper-1 verbose_json or subtitle-format transcription
    -> timestamped Arabic transcript
    -> backend subtitle segmentation
    -> text model translation and Arabic analysis
    -> generated subtitle track
```

This avoids depending on YouTube captions while preserving the timing data the overlay needs.

Whisper's audio translation endpoint should not be the primary translation path. It translates audio directly into English, but it does not produce the controlled product object the app needs: Arabic source line, English translation, cue boundaries, token-level analysis, romanization, glosses, and hoverable word data. It can be useful later as an evaluation baseline, but not as the main architecture.

As an expansion, if `gpt-4o-transcribe` proves materially better for Arabic transcription, add a hybrid pipeline:

```text
same audio
  -> whisper-1
    -> timing source

same audio
  -> gpt-4o-transcribe
    -> higher-quality Arabic transcript source

backend alignment
  -> merge timing with best transcript text
  -> produce final timestamped Arabic cues

final Arabic cues
  -> text model
    -> English translation
    -> Arabic learning analysis
```

This hybrid mode should be treated as an evaluated expansion because it introduces extra cost and alignment complexity. The system must compare transcript quality, timing quality, latency, and cost before making it part of the default product.

For translation and Arabic learning analysis, use a general text model through a backend provider interface rather than a coding-specific model. The translation/analysis model should receive finalized Arabic cues and return structured data validated by the contracts.

The backend should hide the provider choice behind an interface:

```text
TranscriptionProvider
  transcribe(audio) -> TimestampedTranscript

TranslationAnalysisProvider
  enrich(cues) -> TranslatedAnalyzedCues
```

This lets the project compare providers without changing extension code.

## Subtitle Track Model

The backend should return a versioned subtitle track.

Conceptual shape:

```json
{
  "schemaVersion": 1,
  "trackId": "yt:dQw4w9WgXcQ:ar:v1",
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
    "transcriber": "whisper-1",
    "transcriberVersion": "provider-managed",
    "segmentationVersion": "subtitle-segmentation-v1",
    "analysisVersion": "arabic-analysis-v1"
  },
  "cues": [
    {
      "cueId": "cue_000001",
      "startSeconds": 0.84,
      "endSeconds": 3.2,
      "sourceText": "مرحبا بكم",
      "translation": "Welcome",
      "tokens": [
        {
          "tokenId": "cue_000001_tok_000",
          "text": "مرحبا",
          "lemma": "مرحبا",
          "root": null,
          "partOfSpeech": "interjection",
          "romanization": "marhaban",
          "gloss": "hello"
        }
      ]
    }
  ]
}
```

The exact schema should live in canonical contracts before implementation.

## Job Lifecycle Model

Subtitle generation is async.

Recommended states:

```text
created
queued
acquiring_audio
transcribing
segmenting
translating
analyzing
ready
failed
cancelled
expired
```

The extension should poll or subscribe to job status.

The first implementation path can use polling:

```text
GET /subtitle-jobs/{jobId}
```

Future implementation can add server-sent events or WebSockets if polling becomes inefficient.

## Contract Boundary

The extension should not exchange loosely typed JSON with the proxy/backend.

New contracts should include:

- create subtitle job request
- create subtitle job response
- job status response
- generated subtitle track response
- cue model
- token analysis model
- provider/error model
- cache metadata model

Example request:

```json
{
  "platform": "youtube",
  "videoId": "dQw4w9WgXcQ",
  "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
  "sourceLanguage": "ar",
  "translationLanguage": "en"
}
```

Example response:

```json
{
  "jobId": "job_123",
  "status": "queued",
  "trackId": null
}
```

## Extension Responsibilities

The extension should own browser integration and rendering, not AI logic.

Responsibilities:

- detect YouTube watch pages
- extract stable video ID from the URL/page
- find the active video element
- read playback time
- start subtitle generation jobs by calling the proxy
- poll job status
- load completed tracks
- cache lightweight track metadata locally
- render overlay synchronized to playback
- handle hover/click word interactions
- expose user settings

The extension should not:

- hold provider secrets
- run heavy transcription
- perform final Arabic analysis
- trust provider output without backend validation
- scrape YouTube captions as the primary path

## Backend Responsibilities

The backend becomes the core intelligence layer.

Responsibilities:

- create and manage subtitle jobs
- obtain or receive audio
- normalize audio for transcription
- split long audio if needed
- call transcription provider
- normalize timestamped transcript output
- perform subtitle segmentation cleanup
- translate Arabic segments to English
- perform Arabic token analysis
- validate every output against contracts
- cache completed tracks
- expose stable job and track APIs
- log structured diagnostics without leaking sensitive content unnecessarily

## Proxy Responsibilities

The proxy remains the public extension-facing boundary.

Responsibilities:

- accept extension requests
- validate request shape
- enforce origin and extension policy
- rate limit job creation
- reject malformed or abusive requests
- forward valid requests to backend
- normalize public error responses

The extension should continue to talk to the proxy, not directly to backend services.

## Synchronization Model

Once a track is ready, sync is simple:

```text
video.currentTime = 45.0
active cue = cue where startSeconds <= 45.0 < endSeconds
overlay renders active cue
```

The content script should listen to:

- video `timeupdate`
- video `play`
- video `pause`
- video `seeking`
- route/video ID changes

For smoother UI, the runtime can use a lightweight loop while video is playing, but it should avoid unnecessary DOM churn.

Sync problems to handle:

- user seeks before track is loaded
- video changes without full page reload
- generated duration differs from YouTube duration
- intro ads or sponsor segments affect timing
- audio extraction starts with an offset
- video playback speed changes

The generated track should use YouTube video timeline seconds, not raw audio-file-relative seconds, by the time it reaches the extension.

## Caching Strategy

Caching is essential because generation has cost and delay.

### Backend Cache Key

The backend cache key should include:

- platform
- YouTube video ID
- source language
- translation language
- transcription provider
- transcription provider version when known
- segmentation version
- translation version
- Arabic analysis version
- contract/schema version

### Extension Cache

The extension can cache:

- completed track ID
- video ID
- language pair
- user settings
- last known job status

The extension should avoid storing large full tracks locally until there is a clear performance need.

## Observability

The system needs enough diagnostics to explain where a video failed.

Important events:

- YouTube page detected
- video ID changed
- generation requested
- job created
- cache hit
- audio acquisition started/completed/failed
- transcription started/completed/failed
- segmentation completed/failed
- translation completed/failed
- analysis completed/failed
- track served
- overlay mounted
- sync drift detected

Logs should use structured event names and stable IDs.

Raw subtitle text and audio-derived content should be logged sparingly.

## Privacy And Security

This product sends video-derived audio/text to backend/provider services. The UI and privacy policy must be honest about that.

Security requirements:

- no provider keys in extension bundle
- all provider calls happen server-side
- job creation is user-triggered
- rate limits protect provider spend
- cache storage is intentional and documented
- raw audio retention should be minimized
- logs should avoid full transcript dumps by default
- user-facing controls should allow clearing local extension state

Open questions:

- how long completed tracks are retained
- whether raw audio is stored after processing
- whether users can delete generated tracks
- whether private/unlisted videos are supported
- how to represent provider data handling in product copy

## Risks

### YouTube Audio Access

Backend acquisition of YouTube audio may be legally, operationally, or technically risky. This needs explicit review before production.

### Transcription Accuracy

Arabic speech can vary by dialect, speaker, audio quality, and background noise. The system must support confidence, retry, and manual fallback strategies over time.

### Subtitle Timing

Generated timestamps may drift or segment poorly. The product should include diagnostics and eventually correction tools if needed.

### Cost

Long videos can be expensive. The backend must enforce limits, caching, quotas, and maximum duration rules.

### Latency

Users may accept waiting, but only if the UI clearly communicates status and caching makes repeat views fast.

### Provider Lock-In

The provider should be replaceable behind backend interfaces. Contracts should describe our product output, not provider-native output.

## Recommended Implementation Sequence

The existing phases should be rewritten around the new architecture.

Suggested new sequence:

### Phase A: Revamped Contracts And Job Model

- define YouTube-only job contracts
- define generated subtitle track schema
- define cue and token analysis schema
- define stable job states and errors
- update docs and guardrails to remove the old Netflix requirement

### Phase B: YouTube Extension Shell

- detect YouTube video ID
- find active video element
- show generate/status UI
- call proxy job endpoint
- poll job status
- render mock ready track synced to playback

### Phase C: Backend Job Orchestrator With Mock Provider

- create backend job lifecycle
- add in-memory or local persistent job store
- add mock timestamped transcript provider
- return validated generated tracks
- prove extension to proxy to backend to overlay path

### Phase D: Real Transcription Proof

- add audio source abstraction
- implement first YouTube audio acquisition proof
- call timestamp-capable transcription provider
- normalize transcript to internal cues
- add cost and duration limits

### Phase E: Translation And Arabic Analysis

- translate cues to English
- tokenize Arabic cues
- add lemma/root/part-of-speech/gloss output
- validate and cache analysis
- render hover/click UI

### Phase F: Caching, Quality, And Release Hardening

- backend cache by video/version
- retry and failure handling
- observability and diagnostics
- privacy controls
- visual QA
- user acceptance tests on real YouTube videos

## What To Preserve From Existing Work

The pivot does not invalidate everything.

Keep:

- extension/proxy/backend separation
- canonical contracts discipline
- backend-only provider calls
- structured validation
- Chrome extension context separation
- Shadow DOM overlay isolation
- settings storage foundation
- diagnostics mindset

Revise or de-prioritize:

- Netflix support
- existing-caption extraction as primary path
- line-anchored replay against platform captions
- large runtime dev panel
- fixture UI that does not model the new job lifecycle

## Acceptance Criteria For The Final Application

The final application described by this document is successful when:

- user opens a YouTube video
- extension identifies the video
- user starts AI subtitle generation
- backend creates a job
- user can see job status
- backend produces a timestamped Arabic subtitle track
- backend translates cues to English
- backend returns word-level Arabic learning data
- extension renders Arabic and English overlay
- overlay stays synced during play, pause, and seek
- completed track is cached and reused
- no provider secrets are exposed in the extension
- failures are visible and diagnosable

## Open Questions

- Should the application use backend YouTube audio acquisition, tab capture, or both?
- What maximum video length is allowed for the first public version?
- Which transcription provider produces the best Arabic timestamped output for the cost?
- Do we need word-level timestamps or are cue-level timestamps enough?
- How much subtitle segmentation cleanup is needed after transcription?
- How will we handle dialect detection?
- Should the user be allowed to edit/correct generated subtitle lines?
- What privacy retention policy should apply to generated tracks and raw audio?
- What exact UX copy explains that video audio/text is processed by AI services?

## Final Direction

The new product should be built as a YouTube AI subtitle generator with an Arabic learning overlay.

The extension should focus on YouTube integration, playback sync, and UI. The backend should own transcription, translation, analysis, validation, caching, and provider abstraction.

This design is larger than the original caption-enhancement plan, but it better matches the desired product: accurate Arabic subtitles, English translations, and word-level learning support even when YouTube provides no usable captions.
