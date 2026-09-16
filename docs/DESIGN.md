# Design

## Product Design Principles

- Make the first usable workflow obvious.
- Prefer dense, purposeful UI over decorative filler for operational tools.
- Keep copy specific to the user's task.
- Treat screenshots and UI recordings as validation evidence for visual changes.

## Design System Status

- Current state: one "Ink & Marker" design system across the product. The marketing site runs it on bone paper; the extension side panel runs it on the night palette. Both share the single crimson accent and the same type stack.
- Website direction: red/black "ink & marker" study desk — bone paper (`--paper`), black ink (`--ink`), single crimson accent (`--accent #d83b3b`), faint ruled-notebook background, hard 2px borders with offset block shadows, marker-swipe highlights (`.hl`), `//`-prefixed mono eyebrows, and a multilingual (es/ru/ja/fr) annotation motif. Auth and dashboard surfaces are re-skinned to match.
- Extension direction: Ink & Marker, night shift. The side panel runs the same system (bone-paper text, hard borders, offset-shadow primary button, `//` mono micro-labels, single crimson accent) on the site's night tokens, staying compact, video-native, and operational. The YouTube overlay keeps its dark video-native glass rail.
- Type stack (site and panel): Bricolage Grotesque for display, Schibsted Grotesk for body, Spline Sans Mono for eyebrows, labels, and data.
- Source of truth: tokens in `app/backend/public/css/site/tokens.css`; `app/backend/resources/views/layouts/site.blade.php` links the split website styles under `app/backend/public/css/site/` in order with individual cache versions; `app/extension/entrypoints/sidepanel/style.css` owns the side-panel styles (tokens mirrored from the site); `app/extension/utils/overlay.ts` re-exports the isolated YouTube overlay modules under `app/extension/utils/overlay/`.
- Retired directions (Stitch dark academia, clean marketing, cinematic console, Hermes landing) are historical; their assets stay under `docs/design-assets/` for reference only. Do not revive their palettes or rules.
- References: place long framework or design-system notes in `docs/references/`.

## Ink & Marker Website Rules

- The marketing site has a compact night hero, a How To Use introduction, lyrics correction, speed/model choice, study tools, setup steps, a collapsed A–Z language directory, pricing, and FAQ, plus a thin `/pricing` page. Preserve the Ink & Marker palette, fonts, rules, marker highlights, and tactile buttons. `/extension` and `/desktop` redirect to `/how-to-use#how-to-install`; `/how-it-works` redirects to `/how-to-use`. `/languages` and `/faq` still redirect to home anchors. Keep the sitemap listing live pages only.
- `/how-to-use` is the public feature guide. Installation comes first, followed by generation, transcript, full lyrics replacement, single-word correction, word cards, study controls, shortcuts, history, account, and troubleshooting. Use native section anchors, a sticky desktop contents sidebar, and a collapsible contents list on small screens. Keep instructions readable without JavaScript. Place screenshots and captioned videos beside their relevant instructions as they become available; do not show fake previews or empty media controls. Lyrics correction fits full pasted lyrics to existing timing; never imply automatic lyrics retrieval or partial merging.
- Link installation help to the guide from the homepage, pricing, support, terms, and dashboard. Cover both Chrome Web Store and unpacked ZIP installation. Use the configured Chrome listing when available and state when it is unavailable. Support remains for bugs, contact, billing, and failures. Public pages do not market a paid beta; existing pricing, authentication, and account behavior stay intact.
- Website model names are **Transcriber** (the Luna/OpenAI-backed choice) and **Transcriber-Spark** (the Cerebras-backed choice). Explain them as quality and language understanding versus speed, without splitting the marketing comparison by writing system. Use these names consistently in the homepage, pricing, FAQ, and metadata. The guide uses the extension's exact control label **Transcriber Spark** and explains its website name. Provider identifiers and extension labels are unchanged.
- Use crimson as the only accent: marker highlights, primary CTA, active states. Green only for success/positive pills, red tones only for failures.
- Keep buttons flashcard-hard: 2px ink borders, offset block shadow, translate-on-hover/press interaction (`ui.css` `.button`).
- Motion uses the existing CSS and native browser behavior: eased entrances, short page fades, a thin header scroll-progress line, and native disclosure transitions where supported. Preserve ordinary scrolling, anchor offsets, browser history, and no-JS readability. Reduced-motion preferences remove animations, transitions, and their delays, including when changed during a visit.
- Keep the purchase path intact: plan cards link `register?plan=code`, registration stashes the plan in the session, and the dashboard shows a continue-to-checkout banner after registration until checkout starts.
- Keep legal, support, auth, dashboard, and job-detail surfaces readable and operational. Do not put account workflows in decorative hero layouts.
- Legal pages use a full-width outer gutter with a separate readable text column, numbered sections, native anchor navigation, and a visible revision date. Stack the contents navigation on small screens. Never apply viewport gutters inside a width-capped article or hide a long legal document behind scroll-reveal effects. Use the public model names in the terms; name the actual processors and their roles in the privacy policy.
- Keep historical generated-image references under `docs/design-assets/`; only active, optimized public assets should live under `app/backend/public/img/`.

## Extension Side Panel Rules (Ink & Marker, Night)

- Keep the panel's tokens mirrored from the site (`app/backend/public/css/site/tokens.css`): `#0e0e0e` night ground with faint ruled-notebook lines, bone-paper text, single `#d83b3b` crimson accent (crimson is state + emphasis; green only for success pills).
- Type: Bricolage Grotesque for display moments (progress percent), Schibsted Grotesk for body/controls, Spline Sans Mono for `//`-prefixed micro-labels, timecodes, badges, and microcopy.
- Keep the Watch tab state-driven: unsupported page, inline sign-in prompt, setup, named-stage progress, and transcript are mutually exclusive states of one tab — never split this loop back across tabs.
- Keep operational screens dense and calm: usage, job history, and support-safe metadata should be scannable without marketing ornament.
- Keep extension motion brief (140–180ms) and tied to tab/screen changes, switches, and hover previews. Do not animate cue replacement, active-cue navigation, or pinned word-card refreshes. Progress and background updates must not restart screen entrances or move focus/scroll. Reduced-motion rules apply inside the overlay's Shadow DOM too.
- Keep the extension side panel compact and stable at small widths (320px floor); controls, tabs, language lists, and history cards must not overflow.
- Keep the overlay as a video-native glass rail with source tokens, optional translation, cue timing, romanization/gloss, and click-to-expand token detail.
- Use 3-6px radii, hard 1.5-2px borders, and the offset-shadow press interaction only on the primary action. Avoid generic SaaS card mosaics and decorative gradients that do not communicate the workflow.

## Agent Expectations

- Inspect existing screens before changing UI.
- Keep responsive behavior explicit.
- Validate mobile and desktop states when UI changes.
- Record visual evidence in the relevant execution plan or PR summary.
