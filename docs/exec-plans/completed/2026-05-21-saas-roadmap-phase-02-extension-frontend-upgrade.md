# Phase 02: Extension Frontend Upgrade

Status: completed
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-21

## Goal

Upgrade the browser extension from a functional popup into a product-grade SaaS control surface for power users.

The popup should clearly show account state, generation controls, usage, job details, tier benefits, and public-safe telemetry without making the extension feel like a debugging tool.

## Scope

- In scope:
  - Popup information architecture and visual redesign.
  - Generate, Jobs, Usage, Account, and Settings areas.
  - Public-safe job telemetry and stage timelines.
  - Plan limits, remaining minutes, tier speed, and feature gates.
  - Visual QA and browser smoke coverage for important states.
- Out of scope:
  - Implementing backend authentication.
  - Implementing billing.
  - Rewriting the overlay unless a popup change requires shared state updates.
  - Adding a frontend framework unless the existing WXT/TypeScript structure becomes clearly unmanageable.

## Acceptance Criteria

- [x] Popup exposes account/login state without blocking unsupported-page and no-track states.
- [x] Generate flow shows selected languages, translation, romanization, and full word-card behavior.
- [x] Jobs view supports active, completed, and failed jobs with stage, progress, timing, error, retry/open-video actions, and public-safe job IDs.
- [x] Usage view shows remaining monthly minutes, current plan, reset date, and upgrade call to action.
- [x] Settings view keeps overlay controls, timing delay, language defaults, logout, and local-state clear controls legible.
- [x] Public telemetry excludes transcripts, prompts, raw provider payloads, token payloads, install IDs, and raw audio paths.
- [x] Desktop and narrow popup layouts avoid overflow and pass screenshot review.
- [x] Existing overlay sync and clicked-token enrichment continue to work.

## Key Implementation Areas

- Popup architecture:
  - Split large popup code into focused state, rendering, event, and API modules if repeated complexity justifies it.
  - Keep the background script as the backend API boundary.
- UI design:
  - Use compact operational UI, not a marketing layout.
  - Use tabs or segmented navigation for Generate, Jobs, Usage, Account, and Settings.
  - Keep controls stable and accessible in a small popup viewport.
- Job telemetry:
  - Render stage timeline, queue wait, duration, retry/failure state, and tier priority using backend-provided sanitized data.
  - Keep detailed diagnostics behind user-friendly labels.
- Plan and usage:
  - Show plan limits and feature gates from backend account state.
  - Disable or explain unavailable high-tier features.
- Validation:
  - Add popup tests for state rendering, account state, job details, usage, disabled controls, and error mapping.
  - Add browser screenshot smoke once the extension smoke harness exists.

## Required Product Decisions

- Final popup tab structure and default tab.
  - Decision: use Generate as the default tab with Jobs, Usage, Account, and Settings as sibling tabs.
- Whether usage warnings appear before generation, during generation, or both.
  - Decision: show projected remaining minutes in Usage; running jobs count as pending usage. A post-close UI adjustment removed the Generate-tab expected-usage and tier-speed boxes.
- Which telemetry fields are useful to users versus only to support/admin.
  - Decision: expose public job ID, YouTube video ID, status, stage, progress, safe timing, language route, requested generation controls, duration, track expiry, and public error copy. Do not expose transcripts, prompts, raw provider payloads, token payloads, install IDs, raw audio paths, provider cost, or internal generation tier.
- How aggressive upgrade prompts should be inside the extension.
  - Decision: keep upgrade prompts informational until account auth and billing exist; no modal, blocking prompt, or fake entitlement enforcement.
- Whether full word cards are shown as a toggle, tier badge, or costed option.
  - Decision: keep Full word cards as an explicit Generate toggle with a visible higher-cost explanation; future billing can convert it into a gated option.

## Refined Slices

1. Backend-safe telemetry contract.
   - Builds: optional `videoDurationSeconds`, `enrichmentMode`, `includeRomanization`, and `includeTranslation` fields on job responses/history.
   - Defers: account identity, billing, provider cost exposure, and public generation tier exposure.
   - Touches: backend API resources/controller, shared contracts, fixtures, backend contract tests.
   - Validation: contracts check plus focused subtitle API/history tests.
2. Popup state and view-model helpers.
   - Builds: anonymous account summary, projected monthly usage, expected generation usage, public job telemetry, and stage timelines.
   - Defers: backend account sync and paid entitlement enforcement.
   - Touches: extension message types, background state assembly, pure helper tests.
   - Validation: Vitest helper tests for usage projection, sensitive telemetry exclusion, and stage timeline states.
3. Popup UI upgrade.
   - Builds: compact five-tab popup with Generate, Jobs, Usage, Account, and Settings areas, stable responsive layout, retry/open-video actions, and clearer disabled/future-account controls.
   - Defers: overlay rewrite and browser screenshot automation beyond available build output.
   - Touches: popup HTML, CSS, and DOM wiring.
   - Validation: extension tests, TypeScript compile, WXT build, and screenshot/manual notes where automation is still debt.

## Decision Log

| Date | Decision | Reason |
| --- | --- | --- |
| 2026-05-21 | Keep the popup framework-free and split only repeated state/telemetry calculations into a pure helper module. | The existing WXT/TypeScript DOM entrypoint is still manageable, while usage and telemetry rules need focused tests. |
| 2026-05-21 | Treat the account as anonymous/local beta until Phase 03 defines authenticated account state. | Backend authentication and billing are explicitly out of scope, and the extension must not invent privileged entitlements. |
| 2026-05-21 | Add duration and generation-control fields to extension-facing job telemetry, but keep provider cost and generation tier internal. | Duration/control fields are public-safe and needed for user-facing usage and job details; cost/tier exposure belongs to later account/billing contracts. |
| 2026-05-21 | Do not display anonymous install IDs in the popup. | Install IDs are abuse-control identifiers, not user account identifiers, and should not appear as product telemetry. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-05-21 | Baseline harness and app validation passed before implementation. | `.\scripts\agent\doctor.ps1`; `.\scripts\agent\check.ps1` passed: contracts check/build, 147 backend tests, 48 extension tests, TypeScript compile, WXT build. |
| 2026-05-21 | Added public-safe duration/generation-control telemetry to job responses and history without exposing provider cost or generation tier. | `Push-Location .\packages\contracts; npm run check; Pop-Location`; `Push-Location .\app\backend; php artisan test --compact tests\Feature\SubtitleJobApiTest.php tests\Feature\ContractResponseValidationTest.php; Pop-Location`. |
| 2026-05-21 | Rebuilt the popup into Generate, Jobs, Usage, Account, and Settings tabs with local-beta account state, usage projection, public-safe job timelines, retry/open-video actions, and compact responsive styling. | `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location`; helper tests cover usage projection, public telemetry filtering, stage timelines, and reset-date display. |
| 2026-05-21 | Removed the Generate-tab expected-usage and tier-speed boxes after user review; usage remains in the Usage tab and speed remains in Account details. | `Push-Location .\app\extension; npm run compile; npm run build; Pop-Location`; `Push-Location .\app\extension; npm test -- popup-saas-state; Pop-Location`. |
| 2026-05-21 | Code-simplifier pass made generation-control fields required in job response/history contracts and removed the now-dead Generate-tab estimate helper. | `Push-Location .\packages\contracts; npm run check; Pop-Location`; `Push-Location .\app\extension; npm test; npm run compile; Pop-Location`; focused backend API/contract tests passed. |
| 2026-05-21 | Completed narrow popup visual smoke with Chrome DevTools Protocol after the in-app Browser bridge was unavailable in this desktop session. | CDP screenshots captured for Generate/loading, Jobs, Usage, Account, and Settings at 360px width; `document.documentElement.scrollWidth` and `document.body.scrollWidth` stayed at 360px for the tab states after responsive fixes. |
| 2026-05-21 | Final harness and PR-readiness checks passed. | `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1` passed: contracts check/build, 147 backend tests, 52 extension tests, TypeScript compile, WXT build. |

## Validation/Evidence

- `Push-Location .\packages\contracts; npm run check; Pop-Location` passed and regenerated shared TypeScript contract types.
- `Push-Location .\app\backend; php artisan test --compact tests\Feature\SubtitleJobApiTest.php tests\Feature\ContractResponseValidationTest.php; Pop-Location` passed with response/history assertions for public-safe telemetry and internal-cost exclusion.
- `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location` passed with 52 extension tests.
- `.\scripts\agent\check.ps1` passed.
- `.\scripts\agent\verify-pr.ps1` passed.
- Popup visual smoke captured Generate/loading, Jobs, Usage, Account, and Settings at 360px width with no horizontal overflow after responsive fixes.
- The in-app Browser plugin bridge was unavailable (`browser-client is not trusted`) in this desktop session, so screenshot evidence used local Chrome DevTools Protocol instead of the in-app browser harness.

## Risks and Follow-up Debt

- A dense power-user UI can become visually noisy in the small popup surface.
- Public telemetry can confuse users if it exposes too much operational detail.
- Auth and billing states may cause UI churn if backend contracts are not stabilized first.
- Browser extension screenshot automation remains tracked as technical debt and should be completed before beta.
