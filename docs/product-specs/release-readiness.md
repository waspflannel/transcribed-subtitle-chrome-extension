# Release Readiness

Created: 2026-05-05

## Goal

Define the first-release hardening checks for the YouTube AI Subtitle Learning Extension.

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
| Clear Arabic learning audio | `https://www.youtube.com/watch?v=D-vIm_bcgtg` | Clear, short Arabic speech for baseline transcription and translation quality. | Track completes, overlay syncs, and no cue contains empty source text. |
| Noisy Arabic/public speech candidate | `https://www.youtube.com/watch?v=Kax_tVLW7TU` | Regression check for a real video that completed during Phase 06 live proof under live provider conditions. | Existing or regenerated track completes; logs contain no transcript or audio path leakage. |
| Dialect-heavy Arabic candidate | `https://www.youtube.com/watch?v=y1wyPIAHhGQ` | Regression check for conversational Arabic that completed during Phase 06 live proof. | Existing or regenerated track completes; popup and overlay show ready state. |
| Many-to-many language pair | Pick a short public non-English video during release testing | Regression check for selectable source and target languages beyond Arabic -> English. | Generate with Auto detect -> English and one explicit Good or Moderate source/target pair; Jobs shows requested and detected languages when available. |
| Background-noise/music candidate | `https://www.youtube.com/watch?v=YMOrIhZ2mKM` | Stress transcription/enrichment when speech competes with non-speech audio. | Track completes or fails with stable public error; logs identify the failed stage. |
| Long-video candidate | `https://www.youtube.com/watch?v=FOvqnzFDMxI` | Exercise the release duration boundary and long-request behavior. | Videos over 60 minutes return `video_too_long`; videos under 60 minutes remain usable during generation. |

## Manual Visual QA

Capture screenshots before release for:

- Popup unsupported page state.
- Popup ready-to-generate state.
- Popup generation error state.
- Popup generated-track state.
- Popup after local clear-state action.
- Overlay no-track state.
- Overlay loading state.
- Overlay error state.
- Overlay active-cue state with token hover and pinned token detail.
- Overlay compact, top, and bottom positions.

## Local Validation

The harness must pass before release handoff:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

When provider credentials and YouTube tooling are available, also run the candidate videos above through the local backend and capture structured logs.
