# Laravel Boost Skill Routing

Created: 2026-04-28

Use this reference whenever implementation touches `app/backend`.

## Core Rule

`phased-implementation-v2` remains the main delivery workflow for phase work. Laravel Boost skills are supporting lenses inside that workflow, not permission to expand scope.

Priority order:

1. User request and current phase plan.
2. `docs/references/project-guardrails.md`.
3. `ARCHITECTURE.md`, `docs/SECURITY.md`, and active execution plan.
4. Local backend skill files under `app/backend/.ai/skills` and `app/backend/.agents/skills`.
5. Generic examples inside a skill.

If a skill suggests Sanctum, Horizon, Livewire, Redis, user auth, or extra infrastructure, do not add it unless the current phase explicitly requires it.

## Before Laravel Backend Edits

1. Read `app/backend/AGENTS.md`.
2. Confirm installed skills with:

```powershell
Push-Location .\app\backend
php artisan boost:list-skills
Pop-Location
```

3. Load only the relevant local skill files. Project-specific skills such as `laravel-security` and `subtitle-pipeline` live under `app/backend/.ai/skills`; agent-facing Boost skills such as `ai-sdk-development` and `laravel-best-practices` live under `app/backend/.agents/skills`.
4. Record material skill-driven decisions in the active phase plan.

## Skill Map

| Skill | Use When | Project-Specific Guardrail |
| --- | --- | --- |
| `laravel-best-practices` | Any Laravel PHP implementation or review. | Follow Laravel conventions, but keep controllers/services/jobs small and phase-scoped. |
| `laravel-patterns` | Backend architecture shape, API boundaries, controllers, services/actions, models, queues, caching, resources. | Do not add layers just because the skill lists them; add a layer only when it removes current complexity. |
| `laravel-specialist` | Concrete Laravel implementation: migrations, Eloquent, jobs, routes, API resources, tests. | Avoid optional stack suggestions like Sanctum, Horizon, Livewire, Redis, or coverage targets unless the phase asks for them. |
| `laravel-security` | Validation, rate limits, provider keys, raw audio, transcript handling, logs, CORS, deployment hardening, abuse controls. | Provider secrets never reach the extension; raw audio is temporary; logs must not contain secrets, raw audio, full prompts, or full transcripts by default. |
| `ai-sdk-development` | Laravel AI SDK provider identity, enrichment agents, structured output, transcription, and provider behavior. | Use `Lab` for provider identity; keep the narrow OpenAI HTTP transcription request only for WebVTT output while the SDK wrapper cannot expose that provider option. |
| `subtitle-pipeline` | Subtitle jobs, YouTube audio acquisition, transcription, translation, Arabic learning data, generated tracks, and provider boundaries. | YouTube is the only audio source; keep provider calls backend-only; normalize provider responses before storage or extension exposure. |

## Phase Defaults

| Phase | Default Boost Skill Routing |
| --- | --- |
| Phase 02: YouTube Extension Shell | Usually none. Use Laravel skills only if backend config changes. |
| Phase 03: Laravel Job API And Persistence | `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, `laravel-security`. |
| Phase 04: Audio Acquisition And Transcription Proof | `laravel-best-practices`, `laravel-specialist`, `laravel-security`, `ai-sdk-development`, `subtitle-pipeline`. |
| Phase 05: Generated Track And Overlay Sync | Use Laravel skills only for backend track endpoint or persistence changes. |
| Phase 06: Translation And Arabic Learning Data | `laravel-best-practices`, `laravel-patterns`, `laravel-specialist`, `laravel-security`, `ai-sdk-development`, `subtitle-pipeline`. |
| Phase 07: Hardening And Release Readiness | `laravel-security`, plus `laravel-patterns` and `laravel-specialist` for cleanup. |

## Evidence

When a phase uses these skills, update the plan progress or decision log with:

- which Boost skills were loaded
- which guidance materially affected the design
- which generic recommendations were intentionally skipped because they conflict with project scope
