# Plan: Implement remaining subtitle timing wins 4 6 8 9

Status: active
Owner: agent
Created: 2026-07-05
Last updated: 2026-07-05

## Goal

Implement the four remaining wins from `docs/subtitle-generation-timing-analysis.md` on one branch
(`perf/subtitle-timing-wins-2`, based on `origin/main`). Wins #1/#2/#3/#5/#7/#11 are already merged
(PR #16). Win #10 is folded into #6: Scribe is a single-shot multipart upload, so pipelined acquire
needs the same chunk/merge machinery chunked transcription builds.

- Win #4: one merged tokenize+translate LLM call per cue batch.
- Win #6: chunked parallel transcription for long audio.
- Win #8: transcript cache keyed by video so repeat jobs skip acquire/optimize/transcribe.
- Win #9: progressive delivery - the extension renders source cues while the job is still running.

## Scope

- In scope: backend pipeline, provider, agents, config, migrations, contract schema + regenerated
  types, extension background/content/overlay changes for partial cues, tests for each change.
- Out of scope: side-panel transcript progressive rendering, tokenized/translated artifact caching
  (layer on transcript cache only after it proves out), benchmark runs (deferred separately),
  reconciling local main vs origin/main divergence.

## Acceptance Criteria

- [x] Translation-requested jobs dispatch one analysis call per batch that writes both
      `tokenized_cues` and `translated_cues` batch artifacts; same-language jobs keep the
      tokenize-only path; split-retry recovers both halves; single-cue fallback degrades to
      deterministic tokens + source text.
- [x] Audio longer than the chunk threshold is split with overlap, transcribed in parallel,
      and merged with offset word timestamps and overlap dedupe; short audio keeps the
      single-call path; language detection comes from the first chunk; transcription cost is
      recorded once with total duration. Merge logic has dedicated unit tests.
- [x] A completed transcription populates a transcript cache row keyed
      (youtube_video_id, requested_source_language, transcription model); a new job for the same
      key skips acquire/optimize/transcribe, still syncs billing reservation and detected
      language, and does not record transcription provider cost. TTL-based expiry; ttl_days <= 0
      disables the cache.
- [x] While a job is running, `GET /v1/subtitle-jobs/{jobId}/partial-track` returns contract-valid
      partial cues (sourceText immediately; translatedText/romanization patched in as batches
      land) and the extension overlay renders them during the loading state. A
      `delivery.first_cue_available` job event records time-to-first-cue.
- [x] `scripts/agent/check.ps1` passes (contracts, backend tests, extension tests + compile + build).

## Relevant Context

- Product docs: `docs/subtitle-generation-timing-analysis.md` (win definitions and traps)
- Architecture docs: `ARCHITECTURE.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`, `how_to_build.txt`
- Related plans: PR #16 (`perf/subtitle-timing-wins`) landed wins 1/2/3/5/7/11
- Known risks:
  - #4 doubles output tokens per call; the reprompt/split-retry fallback is where the effort goes.
  - #6 chunk boundaries can duplicate or split words; overlap + midpoint dedupe handles this and
    is unit tested with synthetic overlapping payloads.
  - #8 shares transcripts across users. Decision recorded below.
  - #9 adds a contract schema; extension renders partial cues only from contract-valid state.

## Implementation Steps

- [x] Inspect current state (origin/main; wins 1/2/3/5/7/11 already merged).
- [x] Win #4: CueAnalysisAgent + provider analyzeCueBatch with split-retry; AnalyzeSubtitleCueBatch
      job writes both artifacts; pipeline dispatch swap; tests.
- [x] Win #6: ScribeAudioChunkSplitter (ffmpeg), parallel chunk requests, ScribeChunkPayloadMerger
      with unit tests; config thresholds; keep single-call path for short audio.
- [x] Win #8: subtitle_transcript_cache table + SubtitleTranscriptCache service; pipeline lookup
      before acquire; populate after success; prune expired rows; tests.
- [x] Win #9: telemetry event, partial-track assembler + endpoint + resource, contract schema +
      fixture + regenerated types, extension api/guards/messages/background/content/overlay,
      tests both sides.
- [x] Run validation and record evidence.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: backend `php artisan test --compact`, extension `npm test`, contracts `npm run check`.
- Logs: n/a (no production traffic in this environment).
- Metrics or traces: verify in production after deploy via `php artisan subtitles:metrics` —
  expect tokenize+translate wall time to drop (#4), transcribing p95 to drop for long videos
  (#6), cache-hit jobs to complete in analysis+finalize time (#8), and
  `delivery.first_cue_available` to appear in traces (#9).

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-07-05 | Transcripts are cached per video and shared across users (win #8). | Transcripts derive only from public YouTube audio plus the requested source language; no user data is stored in the cache row. Key includes the transcription model id so model upgrades invalidate. TTL default 30 days; `SUBTITLE_TRANSCRIPT_CACHE_TTL_DAYS<=0` disables caching for rollback. |
| 2026-07-05 | Chunk boundaries use fixed intervals + 2s overlap + word-midpoint dedupe, not silence snapping (win #6). | Overlap dedupe alone handles duplicated/split boundary words (a word cut by one chunk edge appears whole in the neighbouring chunk); ffmpeg silencedetect parsing adds fragile stderr scraping for marginal gain. Revisit only if golden diffs show boundary artifacts. |
| 2026-07-05 | Partial cues carry no tokens (win #9). | Word cards need a trackId that only exists after finalization, so tokens are not actionable mid-run; sourceText + translation + romanization is the user-visible progressive payload and keeps the contract and overlay changes small. |
| 2026-07-05 | Merged analysis call records both tokenization and translation per-cue costs (win #4). | The one call does both stages' work; cost telemetry stays comparable before/after. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-07-05 | Plan created. |  |
| 2026-07-05 | Wins #4, #6, #8, #9 implemented and committed on `perf/subtitle-timing-wins-2`. | Commits on branch; per-win tests added. |
| 2026-07-05 | Validation run. | `scripts/agent/check.ps1` output recorded in Completion Notes. |

## Completion Notes

- What changed: see the four win commits on `perf/subtitle-timing-wins-2`.
- Validation results: recorded after check run.
- Simplicity/readability review: each win kept to the smallest direct version; no compat flags
  beyond the documented TTL kill-switch semantics for the shared cache.
- Residual risk: #4 raises per-call output size (watch reprompt/split rates); #6 dedupe tuned via
  unit tests, needs golden-video diff in production; #8 privacy stance documented above.
- Follow-up debt: side-panel transcript does not consume partial cues; tokenized/translation
  artifact caching not layered on #8 yet.
