# Design

## Product Design Principles

- Make the first usable workflow obvious.
- Prefer dense, purposeful UI over decorative filler for operational tools.
- Keep copy specific to the user's task.
- Treat screenshots and UI recordings as validation evidence for visual changes.

## Design System Status

- Current state: Stitch-led frontend revamp active across the Laravel website and extension.
- Marketing/account direction: Dark Academia Website. The Laravel public website and account surfaces now use an obsidian academic reading-room system: full-bleed image-led hero, ivory serif hierarchy, brass action accents, oxblood emphasis, parchment contrast sections, generated language-learning imagery, and dense ledger-like account panels.
- Extension direction: Cinematic Study Console. The extension popup and YouTube overlay stay compact, dark, video-native, and operational.
- Stitch sources: dark academia website `projects/11285798713880801131`, design system `assets/5ee04fe765404b7fb7e1d34e23d44d50`, manifest `docs/design-assets/stitch-dark-academia/README.md`; earlier marketing refresh `projects/17285330433703510860`, design system `assets/64644ce61714457b96a97064b15560fc`, manifest `docs/design-assets/stitch-clean-marketing/README.md`; cinematic extension/account revamp `projects/2987099361838226750`, design system `assets/871d344e6dab43e185c4573dfa4b95ce`, manifest `docs/design-assets/stitch-cinematic/README.md`.
- Source of truth: `app/backend/public/css/site.css` for the beta Laravel website styles, `app/extension/entrypoints/popup/style.css` for the extension popup, and `app/extension/utils/overlay.ts` for the isolated YouTube overlay styles.
- References: place long framework or design-system notes in `docs/references/`.

## Dark Academia Website Rules

- Keep `Transcribed Subtitle Extension` prominent in the first viewport of the public homepage.
- Use generated, repo-owned imagery from `app/backend/public/img/marketing/dark-academia/`; do not hotlink Stitch, Hermes, or temporary generation paths.
- Keep the homepage sequence disciplined: editorial hero, large product video/poster section, parchment "why use it" section, and final atmospheric CTA.
- Keep the product video slot wired for future local MP4/WebM files while showing the generated poster fallback before a real demo exists.
- Use brass for primary actions and active focus, oxblood only for emphasis, library green for success/healthy states, and red only for failures.
- Keep legal, support, auth, dashboard, and job-detail surfaces readable and operational. Do not put account workflows in decorative hero layouts.
- Use 0-8px radii, thin borders, and tonal layers. Avoid SaaS gradients, pill clusters, nested cards, and decorative image overlays behind long text.

## Cinematic Study Console Rules

- Keep the extension mark tied to captions/timecode instead of a generic AI badge.
- Do not use the old dark subtitle-overlay image in the marketing hero. Use neutral placeholder frames until real product screenshots are supplied.
- Keep operational screens dense and calm: usage, billing, job history, extension connection, and support-safe metadata should be scannable without marketing ornament.
- Use teal for primary actions and active system state. Use amber only for subtitle progress, token focus, and learning emphasis. Use red only for failures.
- Keep the extension popup compact and stable at small widths; controls, tabs, language lists, and generated job rows must not overflow.
- Keep the overlay as a video-native glass rail with source tokens, optional translation, cue timing, romanization/gloss, and click-to-expand token detail.
- Use 8px radii for panels and controls unless a status chip needs a pill shape. Avoid generic SaaS card mosaics and decorative gradients that do not communicate the workflow.

## Agent Expectations

- Inspect existing screens before changing UI.
- Keep responsive behavior explicit.
- Validate mobile and desktop states when UI changes.
- Record visual evidence in the relevant execution plan or PR summary.
