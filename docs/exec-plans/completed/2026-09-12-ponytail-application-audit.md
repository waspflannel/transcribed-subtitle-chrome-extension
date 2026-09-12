# Plan: Ponytail application audit

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-12
Last updated: 2026-09-12

## Goal

Deliver an evidence-backed Markdown review of unnecessary application complexity, dead code and assets, generation speed, and subtitle quality. Preserve required product behavior and distinguish confirmed issues from ideas that require measurement.

## Scope

- Review the backend, extension, contracts, dependency manifests, and supporting scripts/docs.
- Use Ponytail for complexity findings and an ordinary review pass for performance and quality.
- Write a findings document; do not change application code or dependencies, call paid providers, or modify production state.

## Acceptance Criteria

- [x] Trace current generation and display paths and verify references for deletion candidates.
- [x] Rank actionable findings with code locations, evidence, impact, and minimal recommendations.
- [x] State validation results and limits; avoid invented speedups or accuracy claims.
- [x] Save and link the Markdown report.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`, `docs/REVIEW.md`
- Backend routing: `docs/references/boost-skill-routing.md`
- Skills: Ponytail, Ponytail audit, Ponytail review; relevant local Laravel review skills.

## Review Steps

- [x] Inspect repository state and establish review scope. Initial working tree is clean.
- [x] Delegate independent generation, extension, and support-code review passes.
- [x] Review persistence/API costs and verify candidate findings from all passes.
- [x] Run the repository harness and record results.
- [x] Write the report and close the plan.

## Validation Plan

Run `.\scripts\agent\check.ps1`. Inspect callers and existing tests for findings. Use small read-only probes only when needed to substantiate a claim. No production benchmarks or paid AI evaluations.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-12 | Review only, with separate complexity and performance/quality sections. | The user requested a findings document; Ponytail audit excludes performance from its own lens. |
| 2026-09-12 | Keep ownership, run guards, input validation, billing correctness, accessibility, and useful tests out of deletion targets. | These support current product behavior. |
| 2026-09-12 | Continue with Astra reviewers after the user's clarification. | All three existing reviewers inherited the parent Astra model. |
| 2026-09-12 | Apply local Laravel query/queue guidance, but omit generic interface, Action-class and infrastructure suggestions. | Current code evidence and project simplicity rules control the recommendations. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-12 | Read repository maps and Ponytail skills; started independent review passes. | Backend generation, extension, support/config/contracts, and parent persistence/API review. |
| 2026-09-12 | Full repository harness passed. | Contracts, 531 backend tests/4,199 assertions, 242 extension tests, TypeScript compile, production build. |
| 2026-09-12 | Verified findings with offline probes. | Duplicate declarations, schema mutation, invented-token score, terminal retention, partial assembly and packaged-font measurements. |
| 2026-09-12 | Saved report, linked it from review/debt docs, and performed final evidence review. | `docs/ponytail-application-review-2026-09-12.md`. |

## Completion Notes

- Deliverable: `docs/ponytail-application-review-2026-09-12.md`.
- Changes: documentation only; application and dependency fixes remain open.
- Validation: full harness passed; final documentation/link checks performed after report creation. The initial interrupted harness call was rerun to capture complete results.
- Evidence: 804 duplicate generated lines, eight obsolete language-sync lines, one unused eager-load line; 274,980 removable raw font bytes from an 879,754-byte build. Other line estimates are explicitly approximate.
- Limits: no production/provider/browser benchmark; SQLite probes are synthetic and isolated. Do not treat unmeasured opportunities as proven speedups or browser acceptance.
- Follow-up: report linked in the debt tracker; existing quality score unchanged because no application issue was fixed.
