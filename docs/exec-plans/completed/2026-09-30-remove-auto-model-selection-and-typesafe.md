# Plan: Remove Auto model selection and TypeSafe

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-30
Last updated: 2026-09-30

## Goal

Remove automatic AI model selection and TypeSafe from the BYOK application. Users choose OpenAI or Cerebras directly. Keep automatic source-language detection and interface-language preferences.

## Scope

- Remove model Auto selection, TypeSafe provider settings, routing calls/configuration, contracts and interface copy.
- Normalize old extension preferences to OpenAI. Preserve saved tracks and their resolved provider/model; cancel unresolved Auto work safely for explicit retry.
- Remove obsolete routing metadata and encrypted TypeSafe keys through a forward migration, preserving other settings.
- Update regression coverage, website translations and current documentation.
- No new providers, changes to language detection, live provider requests, publication or deployment.

## Acceptance Criteria

- [x] Only OpenAI and Cerebras appear as analysis providers and are accepted by the API.
- [x] No active TypeSafe configuration, secret fields or outbound requests remain.
- [x] Old preferences/jobs upgrade without losing saved tracks or other credentials.
- [x] Source-language Auto detect remains functional.
- [x] Repository checks pass.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: completed BYOK conversion on `codex/open-source-byok`.
- Known risks: historical Auto jobs can be unresolved or already pinned to a real provider; reuse keys must be rebuilt without deleting duplicate saved tracks.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- API rejection of Auto and TypeSafe settings; explicit provider generation; legacy job/settings migration.
- Extension normalization of old Auto preference, manual selection and settings rendering.
- Contract generation/checking; website translation and privacy copy coverage.
- Targeted tests, then full harness and formatter. No live provider calls required.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-30 | Cancel unresolved active Auto jobs and rotate run IDs during upgrade. | Removing routing must not silently start provider work or allow stale workers to publish. |
| 2026-09-30 | Preserve resolved provider/model and clear old reuse hashes. | Manual and former Auto duplicates can use existing canonical reuse without losing tracks. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-30 | Parallel implementation started. | Backend/migration, extension/contracts and website/localization owners assigned. |
| 2026-09-30 | Extension and website removal complete across nine locales. | 342 extension tests, compile/build/contracts, six website tests pass. |
| 2026-09-30 | Reviewed legacy migration and requested cancelled-run artifact/audio cleanup. | Resolved tracks/models preserved; obsolete credentials and routing metadata removed. |

## Completion Notes

- Removed Auto analysis selection and TypeSafe across backend, extension, contracts and all nine interface locales. Automatic source-language detection remains unchanged.
- Upgrade coverage preserves resolved models, saved tracks and other encrypted credentials; fences unresolved Auto runs, cancels active work, and deletes obsolete artifacts/audio after commit. No live database migration or provider request was performed.
- Full `scripts/agent/check.ps1` passed: 580 backend tests / 27,713 assertions and 342 extension tests, contract checks, TypeScript, production Chrome build and release guards. Four optional PostgreSQL/Redis integration tests were skipped in this run; the earlier BYOK plan records disposable-service integration evidence.
- Pint and `git diff --check` passed. Read-only local HTTP checks returned 200 for the guide, retained Auto detect copy, and exposed only OpenAI, Cerebras and ElevenLabs in sanitized provider settings.
- Review followed the golden principles: removed obsolete routing paths and reused existing preference normalization and job reuse. Migration review caught and fixed temporary audio cleanup before completion.
- Existing installations must stop workers, apply pending migrations, and rebuild/reload the extension as documented in the operations guide. Local extension output has been rebuilt. No commit, push or deployment was performed.
