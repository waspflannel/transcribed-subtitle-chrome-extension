# Frontend

## Current State

- The browser-extension frontend is a WXT popup under `app/extension/entrypoints/popup`.
- Popup markup is static in `index.html`; `main.ts` wires DOM events, sends background messages, and updates existing DOM nodes.
- The in-page YouTube overlay is rendered by `app/extension/utils/overlay.ts` into an isolated Shadow DOM host.
- The content script binds ready tracks to the page's primary `<video>` element, selects the active cue from `video.currentTime`, and avoids replacing overlay HTML when rendered content has not changed.
- A Laravel/web application frontend is outside the current release scope.

## Expectations

- Keep the WXT extension bootable from the documented `app/extension` package commands.
- Add browser-driven validation for important user journeys.
- Capture screenshots or videos for UI fixes and visual regressions.
- Keep DOM state, routes, errors, and network failures legible to agents.
- Keep playback sync listeners direct and inspectable; add YouTube DOM fallbacks only after a concrete failure.
- Prefer reusable components once a pattern repeats.

## Future Harness Targets

- `scripts/agent/start-app.ps1`
- `scripts/agent/ui-smoke.ps1`
- Browser screenshots for before/after validation.
- Accessibility checks for critical workflows.
- Visual regression snapshots for stable screens.
