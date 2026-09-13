# Second chunk experiment

Measured September 13, 2026 UTC (September 12 Toronto). Branch `codex/first-subtitle-latency`, implementation `7470aeb`. Compare with the immediately preceding first-subtitle experiment, not the original main baseline.

## Change and method

Keep the first 15-second chunk and existing analysis limits; bound the second audio chunk to 20 seconds. Remaining audio shares the remaining upload slots, with at most eight chunks. Existing earliest-index dispatch and contiguous transcript publication already prioritize the handoff, so no queue changes were added.

Twelve completed live fresh runs: nine matched video/provider/settings combinations and three repeats (Spanish 152, Punjabi 154, Japanese 156). Same eight Pro generation and twenty Pro batch workers, one video at a time. Transcript-cache reads and writes disabled; all twelve verified without cache hits. Separate schema `latency_bench_20260913b` and Redis/cache prefixes preserve earlier evidence and public data. Cached behavior is unchanged and was not rerun.

This is a small sequential comparison, not randomized A/B or proof of causal speedup. Upstream caches and provider variability remain uncontrolled. No provider routing, model, prompt, or quality requirement changed. These are backend event timings and approximately 500ms coverage samples, not browser playback or linguistic QA.

## Matched averages

- First annotated subtitles: 12.259s → 11.200s (8.6% sooner).
- Full completion: 20.563s → 19.779s (3.8% sooner).
- Transcription requests per video: 5.11 → 5.89.
- Analysis requests per video: 8.33 → 9.22. Request count is not a measured billing-cost estimate.

Repeats excluded from averages so videos receive equal weight. The nine matched first-ready results improved on 5 videos and regressed on 4.

## All runs

Seconds, previous experiment → second-chunk experiment. Original baseline IDs identify the matched videos; experiment jobs are isolated from public jobs.

| Baseline ID | Language | Provider | Repeat | First ready | Complete | Potential buffer deficit |
| --- | --- | --- | --- | ---: | ---: | ---: |
| 152 | spa | Luna | no | 15.18 → 11.11 | 18.18 → 19.34 | 0.00 → 0.00 |
| 153 | spa | Luna | no | 11.83 → 13.90 | 21.02 → 17.76 | 0.00 → 0.00 |
| 154 | pan | Luna | no | 9.30 → 9.49 | 35.61 → 32.15 | 8.83 → 1.48 |
| 155 | jpn | Luna | no | 14.57 → 11.99 | 19.19 → 18.56 | 0.00 → 0.00 |
| 156 | jpn | Luna | no | 15.96 → 9.89 | 19.86 → 20.00 | 0.00 → 0.00 |
| 157 | spa | Luna | no | 9.97 → 10.79 | 22.15 → 17.87 | 0.00 → 0.00 |
| 158 | rus | Luna | no | 11.60 → 14.49 | 16.55 → 19.54 | 0.00 → 0.00 |
| 161 | jpn | Luna | no | 10.60 → 9.71 | 18.84 → 20.43 | 0.00 → 0.00 |
| 163 | ara | Cerebras | no | 11.31 → 9.43 | 13.67 → 12.36 | 0.00 → 0.00 |
| 152 | spa | Luna | yes | 10.86 → 9.63 | 13.96 → 15.01 | 0.00 → 0.00 |
| 154 | pan | Luna | yes | 9.50 → 8.42 | 29.50 → 34.04 | 6.15 → 0.00 |
| 156 | jpn | Luna | yes | 16.71 → 11.53 | 20.67 → 19.76 | 0.00 → 0.00 |

Potential buffer deficit assumes playback starts at zero when the first positive ready prefix is observed. At each later coverage update, compare elapsed playback with the preceding ready-through timestamp. A positive result means the contiguous ready prefix could run out; it is not an observed visible subtitle gap. Silence and separately available later cues matter. Previous terminal zero after artifact cleanup is excluded; current terminal completion supplies full duration. Approximately 500ms sample granularity limits precision.

## Punjabi handoff

The first run retained 10.4 seconds of opening ready coverage. Its next update arrived 11.884 seconds later, versus 19.234 seconds in the previous experiment: the conservative prefix deficit fell from 8.834s to 1.484s. The next final cue starts at 12.5s, so this sample suggests enough time to show that cue if playback starts at zero; browser playback was not measured.

The first run's second transcription request took 5.921s and handoff analysis took 9.098s. Later transcription still reached 15.906s and the slowest analysis request 9.909s. Shortening the second chunk reduces early waiting but does not eliminate the later provider tail. Timings overlap and must not be summed as sequential stage costs.

The Punjabi repeat's estimated deficit changed from 6.154s to 0.000s; first-ready changed from 9.498s to 8.422s.

## Validation and evidence

- All twelve runs completed with no recorded failure/retry events or error codes. Existing thirteen public jobs and thirty-two transcript-cache rows matched their before snapshots. Benchmark workers stopped afterward; isolated results retained.
- Full harness: 539 backend tests (4318 assertions), 255 extension tests, contracts, compile and build passed. Pint passed. Required Laravel rules reviewer found no correctness blockers.
- Unit tests cover short/long durations, disabled setting, exact coverage, overlap and one/two/eight-upload limits. Integration tests cover both second-chunk settings, opening publication before later extraction, transient second-upload retry and final merge. Source-cue extension in the integration test is not a substitute for live ready-coverage measurement.
- Numeric export: `2026-09-13-second-chunk-experiment.csv` alongside this report. Raw traces: ignored `app/backend/storage/app/latency-benchmark-second/runs-fresh.json`; schema and original experiment evidence retained. No subtitle text or secrets committed.
- Human quality and actual playback review remain open. Additional chunk boundaries can change transcription/segmentation. Set `SUBTITLE_TRANSCRIPTION_CHUNK_SECOND_SECONDS=0` and restart drained workers to restore the previous experiment.
