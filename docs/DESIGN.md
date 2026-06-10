# Design

## Product Design Principles

- Make the first usable workflow obvious.
- Prefer dense, purposeful UI over decorative filler for operational tools.
- Keep copy specific to the user's task.
- Treat screenshots and UI recordings as validation evidence for visual changes.

## Design System Status

- Current state: Stitch-led frontend revamp active across the Laravel website and extension.
- Marketing/account direction: Dark Academia Website for existing public/account support pages, with a user-directed Hermes-inspired black-theme landing-page override on `/` and a product-specific `/extension` install page. The homepage override follows `C:/Users/jaden/Desktop/hermes_design_doc.docx` for structure and rhythm while keeping product-specific copy and assets.
- Extension direction: Cinematic Study Console. The extension side panel and YouTube overlay stay compact, dark, video-native, and operational.
- Stitch sources: dark academia website `projects/11285798713880801131`, design system `assets/5ee04fe765404b7fb7e1d34e23d44d50`, manifest `docs/design-assets/stitch-dark-academia/README.md`; earlier marketing refresh `projects/17285330433703510860`, design system `assets/64644ce61714457b96a97064b15560fc`, manifest `docs/design-assets/stitch-clean-marketing/README.md`; cinematic extension/account revamp `projects/2987099361838226750`, design system `assets/871d344e6dab43e185c4573dfa4b95ce`, manifest `docs/design-assets/stitch-cinematic/README.md`.
- Source of truth: `app/backend/public/css/site.css` imports the split website styles under `app/backend/public/css/site/`, `app/extension/entrypoints/sidepanel/style.css` owns the side-panel styles, and `app/extension/utils/overlay.ts` re-exports the isolated YouTube overlay modules under `app/extension/utils/overlay/`.
- References: place long framework or design-system notes in `docs/references/`.

## Hermes Landing Override

- The Laravel homepage intentionally renders the Hermes-inspired black-theme landing page from `C:/Users/jaden/Desktop/hermes_design_doc.docx`; `/desktop` redirects to the product-specific `/extension` install page.
- Keep this override scoped to the landing page unless a future plan explicitly changes pricing, legal, auth, dashboard, or extension UI surfaces.
- Source of truth for implementation: `app/backend/resources/views/marketing/home.blade.php`, shared Blade components under `app/backend/resources/views/components/`, split CSS under `app/backend/public/css/site/`, `app/backend/public/js/site-interactions.js`, and local image copies in `app/backend/public/img/desktop/`.
- The MP4 demo and installer downloads remain remote links to the Hermes asset host; do not commit those large binaries into the repository without a separate asset policy decision.

## Dark Academia Website Rules

- Keep `Transcribed Subtitle Extension` prominent in the first viewport of the public homepage.
- Keep historical generated-image references under `docs/design-assets/`; only active, optimized public assets should live under `app/backend/public/img/`.
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
- Keep the extension side panel compact and stable at small widths; controls, tabs, language lists, and generated job rows must not overflow.
- Keep the overlay as a video-native glass rail with source tokens, optional translation, cue timing, romanization/gloss, and click-to-expand token detail.
- Use 8px radii for panels and controls unless a status chip needs a pill shape. Avoid generic SaaS card mosaics and decorative gradients that do not communicate the workflow.

## Agent Expectations

- Inspect existing screens before changing UI.
- Keep responsive behavior explicit.
- Validate mobile and desktop states when UI changes.
- Record visual evidence in the relevant execution plan or PR summary.
