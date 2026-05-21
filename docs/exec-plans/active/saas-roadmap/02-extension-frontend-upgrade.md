# Phase 02: Extension Frontend Upgrade

Status: planned
Owner: agent
Created: 2026-05-20
Last updated: 2026-05-20

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

- [ ] Popup exposes account/login state without blocking unsupported-page and no-track states.
- [ ] Generate flow shows selected languages, translation, romanization, full word-card behavior, tier speed, and expected minute usage.
- [ ] Jobs view supports active, completed, and failed jobs with stage, progress, timing, error, retry/open-video actions, and public-safe job IDs.
- [ ] Usage view shows remaining monthly minutes, current plan, reset date, and upgrade call to action.
- [ ] Settings view keeps overlay controls, timing delay, language defaults, logout, and local-state clear controls legible.
- [ ] Public telemetry excludes transcripts, prompts, raw provider payloads, token payloads, install IDs, and raw audio paths.
- [ ] Desktop and narrow popup layouts avoid overflow and pass screenshot review.
- [ ] Existing overlay sync and clicked-token enrichment continue to work.

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
- Whether usage warnings appear before generation, during generation, or both.
- Which telemetry fields are useful to users versus only to support/admin.
- How aggressive upgrade prompts should be inside the extension.
- Whether full word cards are shown as a toggle, tier badge, or costed option.

## Validation/Evidence Required

- `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location`
- `.\scripts\agent\check.ps1`
- Popup screenshots for logged out, logged in, ready, generating, completed, failed, usage low, and feature-gated states.
- Overlay regression screenshots for ready, loading, error, compact, top, bottom, and token detail states.
- Accessibility checks for focus order, form labels, button text, and color contrast.

## Risks and Follow-up Debt

- A dense power-user UI can become visually noisy in the small popup surface.
- Public telemetry can confuse users if it exposes too much operational detail.
- Auth and billing states may cause UI churn if backend contracts are not stabilized first.
- Browser extension screenshot automation is still tracked as technical debt and should be completed before beta.

