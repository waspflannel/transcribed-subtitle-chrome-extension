# Plan: Phase 01 - Project Scaffold And Contracts

Status: completed
Owner: agent
Created: 2026-04-28
Last updated: 2026-04-28

## Goal

Create the implementation workspace for the product: a Laravel backend, WXT TypeScript extension, and schema-first contract package that both sides treat as the boundary source of truth.

This phase should produce a runnable skeleton and validated contracts, not product behavior. The goal is to prevent the backend and extension from drifting before real AI and YouTube integration begin.

## Scope

- In scope:
  - Preserve the v2 agent scaffold already created in the repository root.
  - Create `app/backend` as a Laravel application.
  - Create `app/extension` as a WXT TypeScript extension.
  - Create `packages/contracts` for OpenAPI and JSON Schema files.
  - Install and configure Laravel AI SDK.
  - Install Laravel Boost as a development dependency.
  - Configure SQLite as the first backend storage engine.
  - Add baseline environment examples without real secrets.
  - Add minimal health/dev routes only if needed for validation.
  - Add stack-specific validation commands to the harness.
- Out of scope:
  - Real YouTube detection.
  - Real subtitle jobs.
  - Real provider calls.
  - Audio acquisition.
  - Overlay UI beyond framework defaults.
  - Production deployment setup.

## Acceptance Criteria

- [x] `app/backend` contains a Laravel application.
- [x] `app/extension` contains a WXT TypeScript extension application.
- [x] `packages/contracts` contains canonical OpenAPI / JSON Schema files for job requests, job responses, track responses, cue objects, token objects, and errors.
- [x] Laravel AI SDK is installed and configured through environment variables.
- [x] Laravel Boost is installed as a dev dependency and can be initialized for agent tooling.
- [x] SQLite is configured for local backend persistence.
- [x] No provider secrets are committed.
- [x] The extension and backend can each run their default validation/build command.
- [x] `.\scripts\agent\check.ps1` runs the scaffold checks and points to stack-specific checks.

## Relevant Context

- Product docs: `detailed-design-document.md`
- Architecture docs: `ARCHITECTURE.md`
- Related plans: `docs/exec-plans/active/00-phase-index.md`
- Known risks:
  - PHP and TypeScript contracts may drift if schemas are not canonical.
  - Laravel AI SDK is still evolving, so lock exact package behavior during implementation.
  - Boost should remain dev tooling and not become part of product runtime assumptions.

## Implementation Steps

- [x] Inspect current scaffold and confirm the app workspace was empty before scaffolding.
- [x] Scaffold Laravel under `app/backend`.
- [x] Scaffold WXT under `app/extension`.
- [x] Create `packages/contracts` and write the first OpenAPI / JSON Schema contract set.
- [x] Wire backend validation strategy to canonical schemas or document the exact generation/check path.
- [x] Wire extension TypeScript contract types to canonical schemas or document the exact generation/check path.
- [x] Install Laravel AI SDK and publish/configure its config without real provider keys.
- [x] Install Laravel Boost as a dev dependency and run its install flow when Laravel exists.
- [x] Configure SQLite and migrations baseline.
- [x] Update harness scripts with backend and extension validation commands.
- [x] Run validation and record evidence.

## Refined Implementation Slices

1. Scaffold backend and extension with default framework tooling only.
   - Builds: Laravel app in `app/backend`, WXT TypeScript app in `app/extension`.
   - Defers: product routes, YouTube integration, overlay behavior, provider calls.
   - Validation: framework default tests/build commands run.
2. Add canonical contracts as their own package.
   - Builds: JSON Schema files, OpenAPI file, generated TypeScript declarations, validation fixtures.
   - Defers: runtime endpoint implementation.
   - Validation: contract package validates schemas and generated types.
3. Wire repo harness checks.
   - Builds: stack-aware `scripts/agent/check.ps1` commands.
   - Defers: CI service setup.
   - Validation: `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1`.
4. Record durable setup decisions.
   - Builds: docs and plan updates for contract generation, SQLite, AI SDK, Boost.
   - Defers: later phase runtime architecture details.
   - Validation: doc gardening and self-review.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Additional commands to add during implementation:

```powershell
php artisan test
npm run build
```

Evidence to capture:

- Tests: Laravel test output, extension build output, contract validation output.
- Screenshots or video: not required.
- Logs: Laravel install/config output if relevant.
- Metrics or traces: not required.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-04-28 | Use Laravel backend, WXT extension, Laravel AI SDK, Laravel Boost, SQLite first, and schema-first contracts. | Matches the detailed design and keeps the stack aligned with PHP comfort while preserving a TypeScript extension boundary. |
| 2026-04-28 | Use npm for WXT scaffolding in this repo. | Node and npm are available locally; `pnpm` is not installed, and adding another package manager is unnecessary for Phase 01. |
| 2026-04-28 | Keep contract tooling in `packages/contracts` instead of adding custom Laravel or extension validation code first. | The canonical schema package is the boundary source of truth; Laravel and WXT can consume generated/checkable artifacts in later runtime phases. |
| 2026-04-28 | Keep real job routes out of Phase 01 and add only `/health`. | The phase goal is runnable scaffolding and validated contracts, not product behavior. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-04-28 | Plan created from detailed design. | `detailed-design-document.md` |
| 2026-04-28 | Baseline harness inspected; `app/` only contained `.gitkeep`; `doctor.ps1` and `check.ps1` passed before app scaffolding. | `.\scripts\agent\doctor.ps1`, `.\scripts\agent\check.ps1` |
| 2026-04-28 | Laravel 13 backend scaffolded with SQLite, Laravel AI SDK, Laravel Boost, AI config, `/health`, and baseline tests. | `composer create-project laravel/laravel app/backend`, `composer require laravel/ai`, `composer require laravel/boost --dev`, `php artisan test --compact` |
| 2026-04-28 | WXT TypeScript extension scaffolded and stripped of demo counter assets; contract type re-export added. | `npx wxt@latest init app/extension --template vanilla --pm npm`, `npm run compile`, `npm run build` |
| 2026-04-28 | Contract package created with OpenAPI, JSON Schema, fixtures, schema validation, and generated TypeScript declarations. | `packages/contracts`, `npm run check` |
| 2026-04-28 | Harness updated to validate contracts, Laravel, and WXT from the repository root. | `.\scripts\agent\check.ps1`, `.\scripts\agent\verify-pr.ps1` |

## Completion Notes

- What changed: created `app/backend`, `app/extension`, and `packages/contracts`; installed Laravel AI SDK and Laravel Boost; configured SQLite and OpenAI env placeholders; added a health route, backend contract visibility test, WXT contract type export, stack-aware harness checks, and generated contract/database docs.
- Validation results: `packages/contracts npm run check` passed; `php artisan test --compact` passed with 4 tests and 14 assertions; `npm run compile` passed; `npm run build` passed; `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed.
- Residual risk: Laravel AI SDK is still 0.x and transcription timestamp behavior must be proven in Phase 04; WXT template dependency tree reports four moderate npm audit advisories.
- Follow-up debt: `TD-002` tracks extension dependency audit follow-up; Phase 03 owns product database tables and API validation tests.
