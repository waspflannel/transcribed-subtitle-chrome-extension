# Release Readiness

Created: 2026-05-05

## Goal

Define the first-release hardening checks for the YouTube AI Language Subtitle Extension.

## Critical User States

The popup and overlay must expose these states without stack traces, provider details, local paths, prompts, raw transcripts, translations, or token payload dumps:

- Unsupported page or invalid YouTube video ID.
- Ready to generate for a public YouTube watch page.
- Generation in progress.
- Completed generated track.
- Validation failure.
- Unsupported, private, unavailable, or live video.
- Video over the 60 minute limit.
- Backend audio acquisition failure.
- AI transcription failure.
- AI translation/enrichment failure.
- Rate-limited generation request.
- Expired or missing generated track.
- Unexpected backend failure.
- Local extension state cleared.

## Real Public-Video Acceptance Set

Verify each URL is still public before a release run because YouTube availability, region access, and metadata can change.

| Case | Candidate URL | Purpose | Pass Criteria |
| --- | --- | --- | --- |
| Auto-detected source language | Pick a short public video in any supported non-English language during release testing | Baseline check for Auto detect plus a selected translation language. | Generate with Auto detect -> English or another selected target; Jobs shows requested and detected languages when available. |
| Explicit source language | Pick a short public video where the spoken language is known | Regression check that the selected subtitle language is sent to transcription instead of relying on a fixed source. | Generate with the matching explicit source language and a different target language; track completes and overlay syncs. |
| Same-language track | Pick a short public English video or another known-language video | Regression check that source and target can intentionally match. | Generate with the same source and target; subtitles render and duplicate translation/card enrichment is skipped. |
| Cross-language word cards | Pick a short public non-English video during release testing | Regression check for selectable target-language cards. | Generate one explicit Good or Moderate source/target pair; clicked word cards use the selected target language. |
| Background-noise/music candidate | `https://www.youtube.com/watch?v=YMOrIhZ2mKM` | Stress transcription/enrichment when speech competes with non-speech audio. | Track completes or fails with stable public error; logs identify the failed stage. |
| Long-video candidate | `https://www.youtube.com/watch?v=FOvqnzFDMxI` | Exercise the release duration boundary and long-request behavior. | Videos over 60 minutes return `video_too_long`; videos under 60 minutes remain usable during generation. |

## Manual Visual QA

Capture screenshots before release for:

- Popup unsupported page state.
- Popup ready-to-generate state.
- Popup generation error state.
- Popup generated-track state.
- Popup after local clear-state action.
- Popup Study tab with blur and hover-pause controls.
- Overlay no-track state.
- Overlay loading state.
- Overlay error state.
- Overlay active-cue state with token hover and pinned token detail.
- Overlay scoped blur/reveal states for source words, romanization, and translation.
- Overlay compact, top, and bottom positions.

## Local Validation

The harness must pass before release handoff:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

When provider credentials and YouTube tooling are available, also run the candidate videos above through the local backend and capture structured logs.
