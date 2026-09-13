# Plan: First subtitle latency experiment

Status: active — running second-chunk handoff experiment
Owner: agent
Work mode: standard
Created: 2026-09-12
Last updated: 2026-09-13

## Goal

Reduce time to the first usable subtitles while later chunks continue in the background. Keep the user's selected text provider and preserve subtitle quality, stable published cues, retry ownership, and finalization barriers. Compare fresh generations against the saved September 12 baseline.

## Scope

- In scope: a 15-second opening audio chunk with existing overlap; prepare each slice within its own transcription job; at most two cues and a 10-second span in the first analysis batch; per-chunk preparation timing; local runtime activation and baseline preservation.
- Out of scope: streaming acquisition, partial YouTube downloads, provider routing, prompt/output changes, new dependencies. Full audio acquisition still precedes transcription; WebM/Opus keeps whole-file normalization to preserve timing. The user subsequently authorized real provider benchmark runs on September 13.

## Acceptance Criteria

- [x] Opening analysis can complete before a later slice is prepared.
- [x] Every transcription member is registered upfront in one batch; merge still waits for all members.
- [x] Completed and stale deliveries skip extraction and provider calls; transient uploads retain the source; failures and final merge clean the workspace.
- [x] First analysis contains at most two complete cues, retains available neighboring context, and does not shrink later/correction batches.
- [x] Timing records separate per-chunk preparation from transcription requests; baseline survives generation cleanup.
- [x] Full repository checks pass and local workers load the experiment configuration.
- [ ] Compare the user's next video set against the baseline for startup latency, coverage gaps and quality.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Baseline: `docs/exec-plans/evidence/2026-09-12-generation-timing-baseline.csv`
- Known risks: smaller openings can contain only silence or an unfinished cue; model/network startup latency remains; parallel FFmpeg work can contend for CPU; this experiment does not remove the whole-download barrier. Worker replacement must happen with no active generations.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update behavior and observability docs.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: chunk extraction/offsets, progressive preparation and analysis, immutable batch sizing/context, retry/stale/failure behavior, full harness.
- Logs: `app/backend/storage/logs/first-subtitle-baseline-check.log` and experiment checks in the same ignored log directory.
- Metrics: first source/annotated cue latency, full completion, per-chunk preparation and transcription, slowest analysis batch, contiguous ready coverage. Separate cached transcripts and providers. Existing batch analysis durations overlap transcription and must not be added as serial work.
- Baseline includes 13 current runs across 11 videos: fresh Luna (8) mean first-ready 15.962s and total 21.088s; fresh Cerebras (1) 9.900s / 12.808s; cached Luna (2) 7.451s / 10.323s; cached Cerebras (2) 3.016s / 4.129s. Backend availability differs from client rendering/polling latency. The next user video set supplies actual performance acceptance.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-12 | Merge existing branch before starting experiment. | User explicitly requested it; previous branch had no open PR. Full baseline harness passed, including the uncommitted nonlexical-cue fix. Main is `369ab2f`; experiment branch is `codex/first-subtitle-latency`. |
| 2026-09-12 | Prepare slices inside existing transcription jobs. | Keeps one complete batch and existing failure/merge behavior. Avoids a dynamic-membership race where opening completion could merge before other chunks are registered. |
| 2026-09-12 | Bound extraction to at most 60s, job to 720s, overlap lock to 780s. | Leaves the existing 600s Scribe request budget plus slack; existing queue retry-after 1260s remains longer. |
| 2026-09-12 | Apply local Laravel, subtitle-pipeline, AI SDK and ponytail skills. | A required Laravel skill sub-agent reviewed queue, testing/config rules and lifecycle risks. Boost search-docs verified batch completion and after-commit behavior. No AI SDK/provider API changes are needed. |
| 2026-09-13 | Run the benchmark in a separate Postgres schema and Redis prefix with the same 8 generation / 20 analysis worker capacity for Pro jobs. | Preserves real user jobs, transcripts, and billing records. Fresh workers have transcript-cache reads/writes disabled; cached workers use copies of existing transcripts. A separate local fixture user per run prevents job reuse through the normal generation service. |
| 2026-09-13 | Nine matched fresh runs, four matched cached runs, and repeats of baseline 152/154/156. | Tests the same video/provider/settings combinations and checks variability for Spanish, Punjabi and Japanese. Poll ready coverage every 500ms; this measures backend availability, not browser rendering or human-reviewed quality. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-12 | Baseline validated, previous work merged/pushed, experiment branch created. | Main `369ab2f`; baseline harness log. |
| 2026-09-12 | Full experiment harness passed: 537 backend tests (4240 assertions), 255 extension tests across 32 files, contract checks, TypeScript compile, extension build. | `app/backend/storage/logs/first-subtitle-experiment-check.log` |
| 2026-09-12 | Reviewer caught missing field on older serialized transcription payloads; optional extraction bounds now default safely during handling, covered by a legacy-payload regression test. | `ProgressiveSubtitlePipelineTest` |
| 2026-09-12 | Local settings updated to opening audio 15s, opening analysis 10s / 2 cues. Restarted 31 queue workers and backend with no active generations. Strict runtime check passes; `/up` returns 200. | `app/backend/storage/logs/first-subtitle-runtime-restart.log`, `first-subtitle-runtime-after.json` |

## Completion Notes

- Follow-up authorized: retain first15s, add second20s (bounded by half the remaining audio and target), distribute the rest within max8. Disable with `SUBTITLE_TRANSCRIPTION_CHUNK_SECOND_SECONDS=0`; max1/2 and even-chunk mode retain previous behavior. Existing ascending dispatch and contiguous-prefix publication already prioritize early cues; no extra queue infrastructure needed. Laravel rules review and Boost queue docs confirm parallel completion order remains variable.
- Validate chunk continuity, overlap, short videos and capped uploads; run full harness. Repeat the same 12 fresh benchmark runs in a new isolated schema/prefix, preserving the first experiment. Cached path is unchanged, so no paid cached reruns are needed. Compare first-ready, completion and sampled coverage gaps; quality remains a human review item.
- Second-chunk validation passed: 539 backend tests (4318 assertions), 255 extension tests, contracts, compile and build. Integration runs both disabled and enabled second-chunk settings, including retry and final merge. Required Laravel reviewer found no correctness blockers. Evidence: `app/backend/storage/logs/second-chunk-experiment-check.log`.

- Completed 16 authorized live generations: nine matched fresh runs, three repeats, four confirmed transcript-cache hits. Existing 13 user jobs and 32 transcript-cache rows were unchanged. Benchmark workers stopped; isolated evidence retained.
- [Measured report](../evidence/2026-09-13-first-subtitle-experiment.md) and [numeric results](../evidence/2026-09-13-first-subtitle-experiment.csv): matched fresh first-ready mean 15.288s → 12.259s; total 20.168s → 20.563s. Historical comparison and small sample limit causal claims.
- Keep this plan active for coverage handoff and human playback/quality review. Punjabi repeated a potential 6.2–8.8s ready-prefix deficit despite much earlier opening subtitles. Next experiment should shorten the second chunk and prioritize extending contiguous coverage. No further pipeline change was made during measurement.
- Existing generated tracks are retained and may be reused. No output/cache version was bumped because the contract and analysis content requirements are unchanged.
- Rollback: use main and restore local `SUBTITLE_TRANSCRIPTION_CHUNK_FIRST_SECONDS=40`, `SUBTITLE_ANALYSIS_FIRST_BATCH_SECONDS=30`; remove the experiment-only first-cue cap override, clear config and restart drained workers. Main ignores that new cap setting. Local `.env` changes are not committed.
- Follow-up candidates are the opening-to-second-chunk handoff and acquisition streaming. Provider routing and analysis requirements remain unchanged; linguistic quality has not been validated by this timing benchmark.

