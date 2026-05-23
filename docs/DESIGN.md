# Design

## Product Design Principles

- Make the first usable workflow obvious.
- Prefer dense, purposeful UI over decorative filler for operational tools.
- Keep copy specific to the user's task.
- Treat screenshots and UI recordings as validation evidence for visual changes.

## Design System Status

- Current state: beta SaaS web surface selected.
- Visual direction: restrained server-rendered SaaS pages with ink-and-paper readability, teal primary actions, warm support/error accents, dense account surfaces, and an image-like product workflow hero built from the actual subtitle overlay concept.
- Source of truth: `app/backend/public/css/site.css` for the beta Laravel website styles; update this file when a framework, component library, token system, or durable visual direction changes.
- References: place long framework or design-system notes in `docs/references/`.

## Agent Expectations

- Inspect existing screens before changing UI.
- Keep responsive behavior explicit.
- Validate mobile and desktop states when UI changes.
- Record visual evidence in the relevant execution plan or PR summary.
