# Plan: Reversible audio acquisition experiments

Status: complete — acquisition measurements finished; no live feature enabled
Owner: agent
Work mode: standard
Created: 2026-09-12
Last updated: 2026-09-12

## Goal

Measure three acquisition options against the current downloader, preserving accepted HEAD `a088f94` on `codex/first-subtitle-latency`. Experiments are opt-in standalone scripts on `codex/audio-acquisition-experiments`; no production pipeline changes.

## Scope

- In scope: direct FFmpeg full download without second yt-dlp startup; remote17s opening extraction with concurrent full download; metadata prefetched at least10s before a simulated click, alone and combined with remote opening. Same nine fresh videos and three repeats; decoded audio validation and sanitized timings.
- Out of scope: provider calls, live cache/database changes, automatic panel traffic, production/UI integration. Prefetch is a timing prototype only.

## Acceptance Criteria

- [x] Measure all three options versus control, rotating non-prefetch order.
- [x] Validate public/non-live metadata and allowed HTTPS media URLs; compare decoded mono16kHz opening waveform and duration.
- [x] Delete temporary metadata/audio, never commit signed URLs or headers.
- [x] Save results and limitations; verify backend health. Commit/push evidence with this plan.

## Relevant Context

- Product docs: `docs/SECURITY.md`
- Architecture docs: `app/backend/app/Services/Audio/YouTubeAudioSource.php`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `2026-09-12-first-subtitle-latency-experiment.md`
- Known risks: signed URL expiry, transport/container timing differences, warm CDN effects, extra concurrent traffic. Click-time prefetch savings only apply when resolution finishes before clicking.

## Implementation Steps

- [x] Inspect current state and preserve baseline.
- [x] Implement opt-in acquisition comparison harness with output validation.
- [x] Review against golden principles and record evidence.
- [x] Verify no normal app changes or temporary media remain.

## Validation Plan

Historical commands (the standalone harness was removed after user acceptance on September 13; source remains in commit `43363fd`):

```powershell
python scripts/experiments/audio-acquisition.py --output app/backend/storage/app/acquisition-experiments/new-run.json
.\scripts\agent\check.ps1 -SkipAppChecks
```

Evidence to capture:

- Tests: syntax, metadata/duration checks, all 72 waveform comparisons; docs harness.
- Logs: ignored `app/backend/storage/app/acquisition-experiments/` sanitized JSONs.
- Metrics: [report](../evidence/2026-09-13-audio-acquisition-experiments.md), [all 72 measurements](../evidence/2026-09-13-audio-acquisition-experiments.csv).

Smoke result: initial stdlib HTTP transport timed out and was stopped; FFmpeg direct transport succeeded. First-video FFmpeg smoke produced identical decoded opening samples for control/direct/opening/prefetch. Full experiment uses FFmpeg; no production behavior changed.

Preliminary direct remote decoding differed on Japanese155/161. Removing seek did not resolve155. Copying the compressed opening first, then decoding locally, restored exact sample equality on155; final matched pass tests this version. Preliminary timing rows are not used for final averages (a diagnostic overlapped part of that pass). Final pass runs by itself, with all six modes including prefetch plus direct full download.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-12 | Preserve pushed clean HEAD a088f94; use a separate experiment branch. | User requests reversibility. No pending baseline changes required another commit. |
| 2026-09-12 | Git and Ponytail skills; stdlib Python and installed tools. | Context7 verified yt-dlp metadata reuse and time-range support. No application edits or new dependency needed for acquisition measurements. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-12 | Final isolated pass completed: twelve cases, six modes, all exact PCM matches. | Final JSON and numeric report. |

## Completion Notes

- What changed: standalone script only, committed as43363fd. Existing code remains identical to a088f94.
- Results: mean click-to-audio control3.982s, direct full2.998s, opening-first2.807s. Successful prefetch: current downloader1.477s, direct full0.438s, opening-first0.245s. Nine matched videos in means; three repeats reported separately.
- Validation: all72 final opening waveforms identical, full-file durations valid, no temporary case directories remain, backend health200. No AI calls or user data writes.
- Recommendation: direct full plus bounded metadata prefetch is the strongest first integration candidate; opening-first saved only another0.19s here and requires more pipeline changes. Actual subtitle latency remains unmeasured for these paths.
- Residual risk: M4A-only local sample, upstream caches uncontrolled, simulated successful prefetch, no expiry/real-panel tests. No automatic prefetch or new acquisition path enabled.
- Rollback: return to `codex/first-subtitle-latency` at a088f94. No config rollback or worker restart needed. Keep provider choices and existing subtitle quality requirements.

