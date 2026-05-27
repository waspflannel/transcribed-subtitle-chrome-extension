# Design

## Product Design Principles

- Make the first usable workflow obvious.
- Prefer dense, purposeful UI over decorative filler for operational tools.
- Keep copy specific to the user's task.
- Treat screenshots and UI recordings as validation evidence for visual changes.

## Design System Status

- Current state: Stitch-led frontend revamp active across the Laravel website and extension.
- Marketing direction: Precision & Clarity. The public homepage should feel closer to Cluely, Boardy, and Cursor: simple text-led hero, warm off-white page, dark ink typography, teal primary actions, restrained amber accents, neutral replaceable product-image frames, and a clear bottom CTA toward pricing.
- Extension/account direction: Cinematic Study Console. The extension popup and YouTube overlay stay compact, dark, video-native, and operational.
- Stitch sources: marketing refresh `projects/17285330433703510860`, design system `assets/64644ce61714457b96a97064b15560fc`, manifest `docs/design-assets/stitch-clean-marketing/README.md`; cinematic extension/account revamp `projects/2987099361838226750`, design system `assets/871d344e6dab43e185c4573dfa4b95ce`, manifest `docs/design-assets/stitch-cinematic/README.md`.
- Source of truth: `app/backend/public/css/site.css` for the beta Laravel website styles, `app/extension/entrypoints/popup/style.css` for the extension popup, and `app/extension/utils/overlay.ts` for the isolated YouTube overlay styles.
- References: place long framework or design-system notes in `docs/references/`.

## Cinematic Study Console Rules

- Keep `AI Language Subtitles` prominent; the mark should suggest captions/timecode instead of a generic AI badge.
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
