# Frontend

## Current State

- The browser-extension frontend is a WXT popup under `app/extension/entrypoints/popup`.
- Popup markup is static in `index.html`; `main.ts` wires DOM events, sends background messages, and updates existing DOM nodes.
- The in-page YouTube overlay is rendered by `app/extension/utils/overlay.ts` into an isolated Shadow DOM host.
- The content script binds ready tracks to the page's primary `<video>` element, resolves browser `TextTrack` cue changes back to the matching API cue, and avoids replacing overlay HTML when rendered content has not changed.
- The overlay renders a Shadow DOM language rail with source-token cards, cue timing, optional selected-target cue translation, optional romanization/gloss metadata, hover preview, and click/tap pinned token detail.
- The overlay calls the background script for on-click token enrichment when a token only has transcript/romanization data, then re-renders from the patched backend track.
- The popup has Generate, Jobs, Usage, Account, and Settings tabs with searchable Subtitle language and Translation language pickers, Translate subtitles/Romanization/Full word cards controls, backend-synced progress, public-safe job timelines, projected local-beta usage, account placeholders, timing controls, stable public error copy, and local clear-state controls.
- The popup exposes a manual subtitle timing delay from -10s to +10s. The content script applies it locally by rebinding the generated WebVTT track with shifted cue timings.
- A Laravel/web application frontend is outside the current release scope.

## Expectations

- Keep the WXT extension bootable from the documented `app/extension` package commands.
- Add browser-driven validation for important user journeys.
- Capture screenshots or videos for UI fixes and visual regressions.
- Keep DOM state, routes, errors, and network failures legible to agents.
- Keep privacy and failure copy visible in the popup or overlay when video-derived audio/text leaves the browser or generation fails.
- Keep playback sync listeners direct and inspectable; add YouTube DOM fallbacks only after a concrete failure.
- Prefer reusable components once a pattern repeats.

## Future Harness Targets

- `scripts/agent/start-app.ps1`
- `scripts/agent/ui-smoke.ps1`
- Browser screenshots for before/after validation.
- Accessibility checks for critical workflows.
- Visual regression snapshots for stable screens.
