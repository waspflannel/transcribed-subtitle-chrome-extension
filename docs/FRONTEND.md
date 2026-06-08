# Frontend

## Current State

- The browser-extension frontend is a WXT popup under `app/extension/entrypoints/popup`.
- Popup markup is static in `index.html`; `main.ts` now bootstraps typed DOM handles, event wiring, and background messages while feature renderers live under `entrypoints/popup/render/`, view labels live in `view-model.ts`, tab behavior lives in `tabs.ts`, and timing persistence lives in `timing-control.ts`.
- Popup CSS is loaded from `entrypoints/popup/style.css`, which imports responsibility files under `entrypoints/popup/styles/`.
- The in-page YouTube overlay shell is exported from `app/extension/utils/overlay.ts` into an isolated Shadow DOM host. Overlay render functions, shared types, and Shadow DOM styles live under `app/extension/utils/overlay/`.
- The content script binds ready tracks to the page's primary `<video>` element, resolves browser `TextTrack` cue changes back to the matching API cue, and avoids replacing overlay HTML when rendered content has not changed.
- The overlay renders a Shadow DOM language rail with source-token cards, cue timing, optional selected-target cue translation, optional romanization/gloss metadata, hover preview, click/tap pinned token detail, scoped blur/reveal study controls, cue replay, cue copy, keyboard shortcut status, caption size/density/contrast preferences, and an optional transcript sidebar. Token-card reveal includes the token text plus that token's romanization when present; the full cue romanization line and translation line reveal independently on their own hover/focus. The rail, token cards, popovers, and transcript share the black + crimson brand palette (crimson cue timing, token hover/pin, and active-cue highlight) with a high-contrast red/white theme variant.
- The transcript sidebar is rendered in the same Shadow DOM boundary as the overlay and lists generated cues with search, active-cue highlighting, time ranges, jump, replay, copy, and save-placeholder controls. It is keyboard operable and restores focus on close.
- The content script owns YouTube-page keyboard shortcuts for replay, previous/next cue navigation, translation visibility, source-word blur, hover pause, transcript open/close, cue copy, and the Phase 02 save-current-cue placeholder. Shortcut dispatch ignores editable targets.
- The content script temporarily pauses the active YouTube video when a source token is hovered or focused if the local Study setting is enabled. It resumes playback when the token loses hover/focus, or as a fallback when the pointer leaves the rail, only if the extension caused the pause. Blank rail hover and manual video pause do not reveal blurred text.
- The overlay calls the background script for on-click token enrichment when a token only has transcript/romanization data, then re-renders from the patched backend track.
- The popup is styled in the shared black + crimson brand (matching the website: `#0a0a0a` surfaces, `#d83b3b` crimson accent, Geist/Inter type) and is organized into four tabs — Generate, Study, Account, and Jobs — beneath a persistent header (brand mark, plan chip, refresh) and a sticky tab bar over scrollable panels. Generate holds the status card, searchable Subtitle/Translation language pickers, Translate subtitles/Romanization/Full word cards controls, backend-synced progress including the visible `Optimizing audio` stage, and stable public error copy. Study consolidates caption display (show overlay, position, caption size, density, contrast, gloss, timing delay) with the study controls (blur source/romanization/translation, hover-pause, keyboard shortcuts) and keyboard shortcut help. Account consolidates backend account/usage summaries, feature availability, billing denial copy, and a Local data zone with the clear-local-state control. Jobs lists public-safe job timelines grouped by Videos/Shorts. The popup stays responsive down to 320px (grids collapse to a single column under 360px).
- The popup exposes a manual subtitle timing delay from -10s to +10s. Dragging the range updates local display immediately and persists the setting on a short debounce; number/reset controls commit immediately. The content script applies the setting locally by rebinding the generated WebVTT track with shifted cue timings.
- The Laravel web app now serves the beta SaaS website and account surface from server-rendered Blade pages. Public pages cover home, `/desktop`, pricing, language coverage, how it works, FAQ, privacy, terms, support, `robots.txt`, and `sitemap.xml` with canonical and social metadata. Authenticated pages show plan, usage, Stripe-hosted billing actions, extension connection state, recent jobs, and owner-scoped public-safe job detail pages. Website CSS is loaded from `app/backend/public/css/site.css`, which imports split files under `app/backend/public/css/site/`; repeated Blade UI lives under `app/backend/resources/views/components/`. The homepage and `/desktop` use a scoped Hermes Desktop black-theme landing layout from the user-supplied design document; the remaining website/account visual direction stays Stitch-led dark academia.

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
