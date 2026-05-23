# Phase Plan Index

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-05-22

## Goal

This index splits `detailed-design-document.md` into implementation phases for the YouTube AI Language Subtitle Extension.

The phase sequence is designed to reach real transcription early, keep the Laravel backend and WXT extension simple, and preserve schema-first contracts across PHP and TypeScript.

## Source Documents

- Product pivot: `revamped-design-document.md`
- Detailed baseline: `detailed-design-document.md`
- Agent map: `AGENTS.md`
- Architecture map: `ARCHITECTURE.md`
- Plan template: `docs/exec-plans/templates/exec-plan-template.md`

## SaaS Roadmap

The original extension proof phases are completed. The next product direction is tracked separately in `saas-roadmap/`:

- `saas-roadmap/00-roadmap-index.md`
- `../completed/2026-05-20-saas-roadmap-phase-01-generation-optimization.md`
- `../completed/2026-05-21-saas-roadmap-phase-02-extension-frontend-upgrade.md`
- `../completed/2026-05-22-saas-roadmap-phase-03-accounts-and-extension-auth.md`
- `../completed/2026-05-22-fortify-sanctum-auth-migration.md`
- `saas-roadmap/04-billing-tiers-and-usage.md`
- `saas-roadmap/04a-tiered-worker-queues-and-concurrency.md`
- `../completed/2026-05-22-saas-roadmap-phase-05-saas-website-and-seo.md`
- `saas-roadmap/06-production-hosting-and-ops.md`
- `saas-roadmap/07-beta-launch-and-support.md`
- `saas-roadmap/08-marketing-and-growth.md`
- `saas-roadmap/09-public-launch-after-beta.md`

Execute the SaaS roadmap phase by phase after explicit user request. Each phase should be validated and updated before the next phase starts.

## Phase Order

| Phase | Plan | Primary Outcome | Exit Gate |
| --- | --- | --- | --- |
| 01 | `../completed/phase-01-project-scaffold-and-contracts.md` | Working Laravel/WXT workspace and canonical contracts | Completed 2026-04-28 |
| 02 | `../completed/phase-02-youtube-extension-shell.md` | Extension detects YouTube videos and renders a controlled overlay shell | Completed 2026-04-28 |
| 03 | `../completed/phase-03-laravel-job-api-and-persistence.md` | Laravel generation API, SQLite persistence, and mock track path | Completed 2026-04-30 |
| 04 | `../completed/phase-04-audio-acquisition-and-transcription-proof.md` | Public YouTube audio acquisition and timestamped transcription proof | Completed 2026-05-02 |
| 05 | `../completed/phase-05-generated-track-and-overlay-sync.md` | Valid generated tracks and playback-synced overlay | Completed 2026-05-02 |
| 06 | `../completed/phase-06-translation-and-arabic-learning-data.md` | Translation and word-level learning data | Completed 2026-05-04 |
| 07 | `../completed/phase-07-hardening-and-release-readiness.md` | Reliability, privacy, rate limits, diagnostics, and acceptance tests | Completed 2026-05-05 |

## Global Constraints

- Platform scope is YouTube watch pages only.
- Supported videos are public YouTube videos only.
- Maximum video length is 60 minutes.
- Backend stack is Laravel.
- Extension stack is WXT and TypeScript.
- AI provider calls must stay backend-only behind narrow services.
- Use Laravel AI SDK provider identity and primitives before custom AI integration.
- If Laravel AI SDK cannot expose a required provider option, use a Laravel-side adapter only for that gap.
- Laravel Boost is development tooling, not product runtime behavior.
- Contracts are schema-first and shared by Laravel and TypeScript.
- Raw audio is temporary and deleted immediately after processing.
- Completed tracks are retained for 30 days.
- No cloud sync beyond the beta account, billing, extension-token, usage, and job-history records required for the SaaS release. Subtitle editing, Netflix support, and vocabulary review remain out of scope.
- Each phase must apply `docs/quality/golden-principles.md` before review so repeated simplicity and readability feedback does not need to be rediscovered.

## Cross-Phase Validation

Every phase must run:

```powershell
.\scripts\agent\check.ps1
```

As implementation code appears, each phase must add stack-specific checks rather than relying only on harness linting.

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Phase backlog created from the detailed design document. | `docs/exec-plans/active/` |
| 2026-04-28 | Phase 01 completed and archived. | `docs/exec-plans/completed/phase-01-project-scaffold-and-contracts.md` |
| 2026-04-28 | Phase 02 completed and archived. | `docs/exec-plans/completed/phase-02-youtube-extension-shell.md` |
| 2026-04-29 | Phase 02 final readability cleanup and closeout validation completed. | `docs/exec-plans/completed/phase-02-youtube-extension-shell.md` |
| 2026-04-30 | Added cross-phase simplicity and readability checks to future phase plans. | `docs/quality/golden-principles.md` |
| 2026-04-30 | Phase 03 completed and archived. | `docs/exec-plans/completed/phase-03-laravel-job-api-and-persistence.md` |
| 2026-05-02 | Phase 04 completed and archived. | `docs/exec-plans/completed/phase-04-audio-acquisition-and-transcription-proof.md` |
| 2026-05-02 | Phase 05 completed and archived. | `docs/exec-plans/completed/phase-05-generated-track-and-overlay-sync.md` |
| 2026-05-03 | Phase 05 sync path refactored to OpenAI WebVTT and browser-native `TextTrack` timing. | `docs/exec-plans/completed/2026-05-03-refactor-subtitle-sync-to-webvtt.md` |
| 2026-05-04 | Corrected Phase 05 provider/model framing so Laravel AI `Lab::OpenAI` remains the provider identity and Whisper remains the transcription model. | `docs/exec-plans/completed/2026-05-03-refactor-subtitle-sync-to-webvtt.md` |
| 2026-05-04 | Phase 06 completed and archived. | `docs/exec-plans/completed/phase-06-translation-and-arabic-learning-data.md` |
| 2026-05-05 | Phase 06 live proof completed; real provider failures drove split-retry hardening and closed `TD-005`. | `docs/exec-plans/completed/phase-06-translation-and-arabic-learning-data.md`; `docs/exec-plans/tech-debt-tracker.md` |
| 2026-05-05 | Phase 07 completed and archived with release hardening, acceptance matrix, and remaining release-smoke automation debt tracked. | `docs/exec-plans/completed/phase-07-hardening-and-release-readiness.md`; `docs/exec-plans/tech-debt-tracker.md` |
| 2026-05-11 | Many-to-many language refactor completed and archived. | `docs/exec-plans/completed/2026-05-11-many-to-many-language-refactor.md` |
| 2026-05-11 | AI-first tokenization and romanization revamp completed and archived. | `docs/exec-plans/completed/2026-05-11-ai-first-tokenization-and-romanization-revamp.md` |
| 2026-05-12 | Cheap tokenizer agent pipeline completed and archived. | `docs/exec-plans/completed/2026-05-12-cheap-tokenizer-agent-pipeline.md` |
| 2026-05-12 | Simple AI tokenization pipeline refactor completed and archived. | `docs/exec-plans/completed/2026-05-12-simple-ai-tokenization-pipeline-refactor.md` |
| 2026-05-12 | Tokenization quality gate upgrade completed and archived. | `docs/exec-plans/completed/2026-05-12-tokenization-quality-gate-upgrade.md` |
| 2026-05-13 | Tokenization pipeline cleanup refactor completed and archived. | `docs/exec-plans/completed/2026-05-13-tokenization-pipeline-cleanup-refactor.md` |
| 2026-05-21 | SaaS Phase 01 generation optimization completed and archived after PR #8 merged to `main`. | `docs/exec-plans/completed/2026-05-20-saas-roadmap-phase-01-generation-optimization.md`; `TD-010` tracks remaining medium and near-limit provider timing evidence. |
| 2026-05-21 | SaaS Phase 02 extension frontend upgrade completed and archived. | `docs/exec-plans/completed/2026-05-21-saas-roadmap-phase-02-extension-frontend-upgrade.md`; five-tab popup, public-safe telemetry, usage projection, and visual smoke evidence. |
| 2026-05-22 | Added SaaS Phase 04a for post-auth tiered worker queues and account concurrency. | `docs/exec-plans/active/saas-roadmap/04a-tiered-worker-queues-and-concurrency.md` |
| 2026-05-22 | SaaS Phase 03 accounts and extension auth completed and archived. | `docs/exec-plans/completed/2026-05-22-saas-roadmap-phase-03-accounts-and-extension-auth.md`; verified web accounts, scoped extension tokens, and authenticated job ownership. |
| 2026-05-22 | Fortify/Sanctum auth migration completed and archived. | `docs/exec-plans/completed/2026-05-22-fortify-sanctum-auth-migration.md`; web auth moved to Fortify and extension bearer auth moved to Sanctum. |
| 2026-05-22 | SaaS Phase 05 website and SEO completed and archived. | `docs/exec-plans/completed/2026-05-22-saas-roadmap-phase-05-saas-website-and-seo.md`; server-rendered marketing pages, dashboard, job detail pages, SEO files, and first-party funnel analytics. |
