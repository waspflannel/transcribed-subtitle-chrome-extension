# Plan: Add Jev automatic model selection

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-18
Last updated: 2026-09-18

## Goal

Add an opt-in Auto model choice. TypeSafe Jev classifies a bounded transcript sample once, then the generation uses Transcriber or Transcriber Spark for analysis and its later cards and Quick Fix.

## Scope

- Backend HTTP classifier, saved routing configuration and decision, cache identity, API contracts, extension selector and localization.
- Existing manual choices and default stay unchanged. Full pasted-lyrics replacement retains its dedicated Transcriber path.
- No new dependencies, transcription providers, per-batch switching, deployment, or quality-scoring system.

## Acceptance Criteria

- [x] Auto is accepted by the API and remembered by the extension.
- [x] Resolve before the first analysis request, including progressive and cached transcripts.
- [x] Save one choice with the candidate models pinned at submission; later batches reuse it.
- [x] Fall back to Transcriber for missing credentials, unavailable Spark, malformed responses, errors, or low confidence.
- [x] Separate Auto/manual reuse and clear the decision on force regeneration.
- [x] Recheck current run and status after external work; keep network calls outside DB transactions.
- [x] Focused tests, root harness, and visual inspection pass.

## Relevant Context

- ARCHITECTURE.md, docs/SECURITY.md, docs/RELIABILITY.md, docs/FRONTEND.md.
- TypeSafe: https://docs.typesafe.ai/api and https://docs.typesafe.ai/confidence.
- Applied skills: Ponytail, laravel-best-practices, ai-sdk-development, subtitle-pipeline, laravel-security. A bounded read-only subagent read Laravel rule files as required by laravel-best-practices.
- Boost search-docs and Context7 verified the HTTP and locking APIs. TypeSafe's decision endpoint needs a narrow Laravel HTTP adapter; the installed generative AI agent API does not expose it.

## Implementation Steps

- [x] Trace streaming, merged and cached transcript paths.
- [x] Add classifier, persistence and contract changes.
- [x] Add Auto UI and translations.
- [x] Complete tests and review.
- [x] Update system-of-record docs and record validation.

## Validation Plan

- PHPUnit routing tests: both choices, repeated delivery, model pinning, failure fallback, stale/cancelled/deleted runs, lock contention and progressive processing.
- API tests: Auto/manual separation, cache reuse, regeneration, pinned configuration and fallback.
- Extension tests: settings, pending API responses and selector interactions.
- Run scripts/agent/check.ps1 and Pint.
- Inspect the panel at narrow width; verify no secret enters tracked changes.
- Live Jev smoke calls use synthetic text only, with safe decision metadata printed.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-18 | Select in SubtitleCueBatchProcessor | All analysis paths meet here; final transcript merge is too late. |
| 2026-09-18 | Shared job/run cache lock, then short DB write | Serializes batches without holding account/DB locks during HTTP. |
| 2026-09-18 | Persist routing configuration and fingerprint | Candidate models, criteria and threshold stay stable while queued; Auto has a distinct reuse identity. |
| 2026-09-18 | One request, 2s connect / 5s total timeout, confidence floor 0.7 | Conservative starting policy; thresholds and definitions can be adjusted after real evaluation. |
| 2026-09-18 | No classifier transport retry | A routing outage falls back quickly and avoids delaying first subtitles. |
| 2026-09-18 | Initial sample uses first draft batch and its neighbors, capped at 6000 characters | Supports early subtitles; a short intro may not represent later passages, so uncertain samples use Transcriber. |

## Progress Log

- Branch: codex/jev-auto-model-selection.
- Key stored only in ignored local backend .env.
- Live synthetic English check: Jev 1.13.0 chose Spark, confidence 0.99, 670ms end to end.
- Focused tests passed; full root harness passed.

## Completion Notes

Implemented and reviewed. No production deployment or user-video generation was performed.

- Full scripts/agent/check.ps1: contracts and docs passed; 729 backend tests passed (11 disposable-service tests skipped); 366 extension tests across 33 files passed; TypeScript, Chrome build and release guards passed.
- Pint passed. WebsiteLocalizationTest rerun after privacy revision date: 7 tests passed.
- Live synthetic Jev checks: English -> Spark (0.99 confidence, 670ms); Japanese -> Transcriber (0.99, 755ms); mixed English/Japanese -> Transcriber (0.97, 432ms). These verify integration and initial rules, not model quality.
- Browser skill: 320px-wide static panel fixture inspected, all three options selectable, document width 320px. Screenshot: app/extension/.output/jev-auto-review/panel-320.png (ignored artifact).
- Docker Desktop's engine is stopped. Runtime Postgres/Redis were not started, and the local migration is pending. The standard local runtime launcher applies migrations when started; reload the built extension afterward.
- Local key is ignored and excluded from staged changes. No remote push or PR.

## Commit Plan

1. Add Jev routing for automatic model selection: backend adapter/config, migration, integration, API schemas and backend tests.
2. Add Auto model choice to the extension: selector, labels, persistence, guards, translations and interaction tests.
3. Document automatic model selection and TypeSafe processing: architecture, operations, reliability/security, guide/privacy copy, translations and this plan.
