# Phase Plan Index

Status: planned
Owner: agent
Created: 2026-04-28
Last updated: 2026-05-02

## Goal

This index splits `detailed-design-document.md` into implementation phases for the YouTube AI Subtitle Learning Extension.

The phase sequence is designed to reach real transcription early, keep the Laravel backend and WXT extension simple, and preserve schema-first contracts across PHP and TypeScript.

## Source Documents

- Product pivot: `revamped-design-document.md`
- Detailed baseline: `detailed-design-document.md`
- Agent map: `AGENTS.md`
- Architecture map: `ARCHITECTURE.md`
- Plan template: `docs/exec-plans/templates/exec-plan-template.md`

## Phase Order

| Phase | Plan | Primary Outcome | Exit Gate |
| --- | --- | --- | --- |
| 01 | `../completed/phase-01-project-scaffold-and-contracts.md` | Working Laravel/WXT workspace and canonical contracts | Completed 2026-04-28 |
| 02 | `../completed/phase-02-youtube-extension-shell.md` | Extension detects YouTube videos and renders a controlled overlay shell | Completed 2026-04-28 |
| 03 | `../completed/phase-03-laravel-job-api-and-persistence.md` | Laravel generation API, SQLite persistence, and mock track path | Completed 2026-04-30 |
| 04 | `../completed/phase-04-audio-acquisition-and-transcription-proof.md` | Public YouTube audio acquisition and timestamped transcription proof | Completed 2026-05-02 |
| 05 | `../completed/phase-05-generated-track-and-overlay-sync.md` | Valid generated tracks and playback-synced overlay | Completed 2026-05-02 |
| 06 | `phase-06-translation-and-arabic-learning-data.md` | Translation and Arabic token learning data | Overlay supports translation plus hover/click token details |
| 07 | `phase-07-hardening-and-release-readiness.md` | Reliability, privacy, rate limits, diagnostics, and acceptance tests | First release criteria are validated against real public videos |

## Global Constraints

- Platform scope is YouTube watch pages only.
- Supported videos are public YouTube videos only.
- Maximum video length is 60 minutes.
- Backend stack is Laravel.
- Extension stack is WXT and TypeScript.
- AI integration should use Laravel AI SDK before custom provider code.
- If Laravel AI SDK cannot expose timestamped transcription output, use a Laravel-side OpenAI transcription adapter for that single path.
- Laravel Boost is development tooling, not product runtime behavior.
- Contracts are schema-first and shared by Laravel and TypeScript.
- Raw audio is temporary and deleted immediately after processing.
- Completed tracks are retained for 30 days.
- No user accounts, subtitle editing, Netflix support, or vocabulary review in the first release.
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
