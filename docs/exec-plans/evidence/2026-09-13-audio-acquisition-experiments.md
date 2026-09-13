# Audio acquisition experiments

Measured September 13, 2026 UTC (September 12 Toronto). Kept baseline: `a088f94`, `codex/first-subtitle-latency`. Opt-in harness: `43363fd`, `codex/audio-acquisition-experiments`.

## Result

All twelve cases completed all six modes (72 measurements). Each mode produced exactly the same 272,000 decoded opening samples as the current downloader: 17 seconds, mono 16kHz, signed 16-bit PCM. Full files passed duration validation. No AI providers were called; no app config, database, normal workers or extension behavior changed.

These numbers are time to transcription-ready AUDIO, not subtitle latency. No end-to-end generation or real panel prefetch was implemented in this experiment.

## Nine-video matched averages

Exclude the three repeats to weight videos equally. No-prefetch click time combines measured fresh metadata resolution with measured mode processing; warm cases start the simulated click after metadata has been held for at least 10 seconds.

| Mode | Mean click-to-audio | Median | Observed range | Mean after resolution |
| --- | ---: | ---: | ---: | ---: |
| Current downloader | 3.982s | 3.990s | 3.720–4.154s | 1.473s |
| Direct FFmpeg full download | 2.998s | 3.006s | 2.674–3.206s | 0.489s |
| Opening copy + parallel full download | 2.807s | 2.825s | 2.515–2.994s | 0.299s |
| Prefetch + current downloader | 1.477s | 1.440s | 1.341–1.707s | 1.477s |
| Prefetch + direct full download | 0.438s | 0.431s | 0.379–0.541s | 0.438s |
| Prefetch + opening copy | 0.245s | 0.244s | 0.230–0.266s | 0.245s |

Metadata resolution averaged 2.508s. Direct full download saved 0.984s versus control; opening-first saved another 0.190s. Prefetch moves metadata work before clicking; it does not eliminate that work.

## Every case

Seconds to ready opening audio. Prefetch columns assume a completed metadata prefetch. Original baseline IDs identify videos, not new application jobs.

| ID | Language | Repeat | Control | Direct full | Opening first | Prefetch control | Prefetch direct | Prefetch opening |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 152 | spa | no | 3.778 | 2.674 | 2.515 | 1.407 | 0.395 | 0.240 |
| 153 | spa | no | 3.720 | 3.001 | 2.601 | 1.440 | 0.431 | 0.249 |
| 154 | pan | no | 4.064 | 3.002 | 2.962 | 1.426 | 0.428 | 0.230 |
| 155 | jpn | no | 4.127 | 2.880 | 2.684 | 1.683 | 0.444 | 0.231 |
| 156 | jpn | no | 3.910 | 3.006 | 2.783 | 1.461 | 0.451 | 0.254 |
| 157 | spa | no | 4.154 | 3.206 | 2.994 | 1.442 | 0.457 | 0.241 |
| 158 | rus | no | 4.128 | 3.087 | 2.984 | 1.341 | 0.379 | 0.244 |
| 161 | jpn | no | 3.966 | 3.049 | 2.825 | 1.707 | 0.541 | 0.266 |
| 163 | ara | no | 3.990 | 3.074 | 2.920 | 1.387 | 0.416 | 0.252 |
| 152 | spa | yes | 3.786 | 2.781 | 2.643 | 1.435 | 0.495 | 0.234 |
| 154 | pan | yes | 4.220 | 3.338 | 2.964 | 1.466 | 0.417 | 0.223 |
| 156 | jpn | yes | 3.942 | 2.935 | 2.815 | 1.503 | 0.451 | 0.244 |

## What was tested

1. **Remove the second downloader launch:** resolve metadata/audio format with yt-dlp once, then FFmpeg copies the resolved audio stream to a full local file without re-encoding. The normal local 17s FLAC extraction follows. Control launches yt-dlp again with the same info JSON, as the current application does.
2. **Opening first:** FFmpeg copies the opening compressed audio to a local file, then decodes 17s to mono 16kHz FLAC. A separate full-file copy runs concurrently. Ready time is captured before joining the full download; full source availability is recorded separately. This intentionally duplicates some acquisition work. Network bytes for the opening request were not measured, so reduced total bandwidth is not claimed.
3. **Metadata prefetch:** hold successfully resolved metadata for at least 10s before simulating Generate. Test the existing downloader and combinations with both direct paths. This measures a successful, short-lived prefetch reuse, not hit rate, expiry resilience or actual panel behavior. No speculative requests were enabled for users.

All cases use the same existing public-video set, selected M4A/AAC format 140, with durations 118–287s. Resolve metadata separately for each case; reuse its exact selected format across modes. Rotate control/direct/opening ordering through the set. Warm cases follow after metadata ages; no provider/CDN cache clearing. The final pass ran without concurrent diagnostic benchmarks. The initial pass was exploratory and is excluded from the table.

## Audio correctness finding

The first prototype decoded directly from the remote stream. Its output differed on Japanese 155/161. On 155 the reproducible difference affected 2,135 samples between 0.940s and 15.103s; removing input seek did not fix it. Copying the opening compressed audio locally before decoding restored sample equality on the diagnostic case and all twelve final cases. The underlying remote decode difference was not established; the mismatching prototype is not the recommended implementation.

An initial stdlib HTTP transport timed out and was abandoned for installed FFmpeg. Neither failed prototype was installed in the live pipeline. Temporary audio/info JSON was deleted after every case; evidence excludes signed URLs and request headers.

## Recommendation and limits

Direct full download is the smallest candidate for application integration: it retains the existing complete-file handoff and avoids the second yt-dlp process. Opening-first is faster but needs progressive acquisition ownership, failure cleanup and duplicate-prefix handling in the real pipeline. The measured incremental saving should be weighed against that integration work.

Metadata prefetch offers the largest click-time change when it has completed before the click. A real implementation needs bounded lifetime/storage, video/account identity handling, cancellation and an ordinary path for misses/expired links. The test does not establish what proportion of users wait long enough in the panel for a hit. Once integrated, measure first analyzed subtitle AND continued coverage again; do not subtract these acquisition savings from historical subtitle times and present the result as measured.

All final samples were M4A on this Windows host. WebM/Opus codec delay, long videos, production host/network performance, late-video seek/merge behavior and signed-link expiry were not tested. Exact opening waveform agreement supports this acquisition experiment, not a complete subtitle-quality guarantee.

## Historical reproduction and rollback

The standalone harness was removed after successful user acceptance on September 13. Its source remains in commit `43363fd`; the numeric reports remain in this repository. The command below describes the original run.

```powershell
python scripts/experiments/audio-acquisition.py --output app/backend/storage/app/acquisition-experiments/new-run.json
```

Use `--limit 1` for a smoke test or `--baseline-id 155` for one known video. Existing output paths are refused. Script stops on transport/validation errors and records only sanitized error class. Compare waveform fields even if transport succeeds.

Requires installed yt-dlp, FFmpeg and ffprobe. Tested versions: yt-dlp 2026.08.19; FFmpeg N-124279-g0f6ba39122-20260430; Python 3.13.7. Verified CLI/protocol options against [yt-dlp documentation](https://github.com/yt-dlp/yt-dlp/blob/master/README.md) and [FFmpeg documentation](https://ffmpeg.org/ffmpeg-all.html).

Normal application code is identical to a088f94. Switching back to `codex/first-subtitle-latency` removes the experiment harness/docs from the checkout; no environment rollback or worker restart is needed. No production prefetch was activated.

Validation: Python syntax, full live matched harness and `scripts/agent/check.ps1 -SkipAppChecks`. Application tests were not repeated because application code is unchanged; accepted baseline had 539 backend/255extension tests passing. Raw sanitized final JSON remains under ignored backend storage; committed CSV contains all 72 rows.

