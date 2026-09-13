# First subtitle experiment: measured results

Measured September 13, 2026 UTC (September 12 Toronto). Implementation: `74ea8bc`, branch `codex/first-subtitle-latency`.

## Result

The nine matched fresh runs improved mean first annotated subtitle availability from 15.288s to 12.259s (19.8%). Seven improved; two regressed. Mean completion changed from 20.168s to 20.563s (2.0% slower). This supports earlier availability, not an overall throughput win or proven uninterrupted playback.

## Method

- Sixteen completed live generations: nine matched fresh runs, three fresh repeats, four matched transcript-cache hits. No recorded failure/retry events or error codes.
- Same videos, selected providers, models, source/target language settings, translation and romanization settings as the historical baseline. Luna remains gpt-5.6-luna; Cerebras remains gpt-oss-120b. Provider rows are separate cohorts, not quality comparisons or routing recommendations.
- Separate Postgres schema `latency_bench_20260913a`, Redis/cache prefixes and fixture accounts. Normal generation service, billing and queue paths; eight Pro generation workers and twenty Pro batch workers. One video at a time.
- Fresh transcript-cache reads/writes disabled, verified zero cache-hit events. Cached phase used copies of original transcripts and verified four hits. Existing 13 jobs and 32 transcript-cache rows matched their before snapshots afterward. Benchmark workers stopped after both phases.
- This does not guarantee cold upstream/CDN/provider caches. Historical baseline versus later runs is not randomized simultaneous A/B. Provider/network variability remains; the repeats demonstrate it.
- First/total timings use job event duration_ms. Coverage sampled about every 500ms. Fresh coverage samples used a pre-generation clock origin; relative intervals within a run are valid, but should not be equated with event elapsed time. Terminal zero coverage after artifact cleanup is excluded.
- Backend availability only: browser rendering/polling, actual playback and linguistic quality were not tested. Fresh transcription cue counts changed; smaller chunks still require quality review.

## All runs

Seconds; arrows show baseline to experiment. IDs refer to the saved baseline, not newly generated public jobs.

| Baseline | Language | Provider | Path | First ready | Complete | Acquisition | Transcription span | Slowest analysis batch |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| 152 | spa | Luna | fresh | 13.53 → 15.18 | 19.26 → 18.18 | 5.16 | 5.03 | 6.49 |
| 153 | spa | Luna | fresh | 15.34 → 11.83 | 16.61 → 21.02 | 4.80 | 7.42 | 7.82 |
| 154 | pan | Luna | fresh | 18.50 → 9.30 | 28.86 → 35.61 | 4.78 | 15.24 | 14.48 |
| 155 | jpn | Luna | fresh | 18.91 → 14.57 | 24.73 → 19.19 | 4.62 | 6.10 | 7.70 |
| 156 | jpn | Luna | fresh | 16.84 → 15.96 | 24.89 → 19.86 | 5.39 | 7.49 | 6.13 |
| 157 | spa | Luna | fresh | 13.85 → 9.97 | 18.67 → 22.15 | 4.40 | 6.12 | 11.04 |
| 158 | rus | Luna | fresh | 14.22 → 11.60 | 16.03 → 16.55 | 4.40 | 4.27 | 7.12 |
| 161 | jpn | Luna | fresh | 16.50 → 10.60 | 19.65 → 18.84 | 4.54 | 5.81 | 7.29 |
| 163 | ara | Cerebras | fresh | 9.90 → 11.31 | 12.81 → 13.67 | 4.99 | 4.73 | 3.17 |
| 152 | spa | Luna | fresh repeat | 13.53 → 10.86 | 19.26 → 13.96 | 4.32 | 4.50 | 4.42 |
| 154 | pan | Luna | fresh repeat | 18.50 → 9.50 | 28.86 → 29.50 | 4.99 | 15.12 | 8.80 |
| 156 | jpn | Luna | fresh repeat | 16.84 → 16.71 | 24.89 → 20.67 | 6.19 | 5.68 | 8.60 |
| 151 | spa | Luna | cached | 8.54 → 3.32 | 11.45 → 10.25 | 0.00 | 0.00 | 9.08 |
| 162 | jpn | Cerebras | cached | 2.93 → 1.82 | 3.98 → 3.38 | 0.00 | 0.00 | 2.15 |
| 159 | jpn | Cerebras | cached | 3.10 → 1.64 | 4.28 → 4.01 | 0.00 | 0.00 | 2.94 |
| 160 | jpn | Luna | cached | 6.36 → 2.79 | 9.20 → 9.70 | 0.00 | 0.00 | 8.68 |

Transcription spans and analysis overlap. Do not sum these columns as sequential wall time. The optimize stage now mostly schedules work; its tiny duration does not mean extraction disappeared. Extraction is measured inside each transcription job.

## Matched cohort averages

Repeats excluded so individual videos are not overweighted.

| Cohort | Count | First before | First after | Total before | Total after |
| --- | ---: | ---: | ---: | ---: | ---: |
| Fresh Luna | 8 | 15.962 | 12.377 | 21.088 | 21.425 |
| Fresh Cerebras | 1 | 9.900 | 11.315 | 12.808 | 13.668 |
| Cached Luna | 2 | 7.451 | 3.056 | 10.323 | 9.978 |
| Cached Cerebras | 2 | 3.016 | 1.735 | 4.129 | 3.697 |

Spanish 152 repeated first-ready at 10.863s versus 15.181s initially. Punjabi repeated at 9.498s versus 9.305s; Japanese 156 repeated at 16.715s versus 15.959s. Avoid treating a single run as a stable speed estimate.

## Remaining bottlenecks and next experiment

1. **Coverage after the opening.** Punjabi 154 first-ready improved from 18.502s to 9.305s, but only covered through video time 10.4s. The next contiguous update arrived 19.234s later; on repeat it arrived 16.554s later. Starting playback at zero immediately would exceed that prefix by about 8.8s / 6.2s. These are conservative buffer deficits, not measured visible stall durations: silence and out-of-order analyzed cues matter. The next final cue begins at 12.5s in the first run, so this is not just a long silent opening.
2. **Later transcription and analysis tails.** That Punjabi run had a 1.522s opening transcription and 1.863s opening analysis, while later transcription requests took 9.902–14.828s and the slowest analysis batch took 14.479s. Total completion rose to 35.610s (repeat 29.497s), versus 28.864s baseline. The traces locate the waiting work; they do not establish that language alone caused provider latency.
3. **Full audio acquisition still blocks startup.** Across nine fresh runs it averaged 4.786s, versus 12.259s first-ready overall. Streaming/range acquisition remains a separate experiment and was not implemented here.

Next test: keep the short opening, shorten the second audio chunk, and prioritize analysis that extends the earliest contiguous ready section. Judge both first-ready and sustained ready coverage. Do not further shrink the first batch until the handoff is reliable. Retain current provider choices. Human playback and subtitle quality review remain required before treating this as an accepted production improvement.

## Evidence and validation

- Numeric export: `2026-09-13-first-subtitle-experiment.csv` alongside this report.
- Raw local traces and coverage: ignored `app/backend/storage/app/latency-benchmark/runs-fresh.json` and `runs-cached.json`. Test schema retained for inspection. No subtitle text or account secrets committed.
- Implementation previously passed 537 backend tests / 4240 assertions, 255 extension tests, contract checks, TypeScript compile and extension build. This measurement pass changes only documentation/evidence; document harness rerun separately.
