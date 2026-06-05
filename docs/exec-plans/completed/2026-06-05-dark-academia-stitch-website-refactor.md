# Plan: Dark Academia Stitch Website Refactor

Status: completed
Owner: agent
Created: 2026-06-05
Last updated: 2026-06-05

## Goal

Refactor the public website and account surfaces for **Transcribed Subtitle Extension** into a Stitch-led dark academia visual system inspired by the structure and atmosphere of the Hermes Desktop page, without copying Hermes assets, typography, copy, or brand treatment.

The new site should feel black, classy, literary, language-learning focused, and premium: a full-bleed editorial hero, a large product video section, a high-contrast "why use it" section, and a final atmospheric information/CTA section using generated dark-academia language-learning imagery.

## Scope

- In scope:
  - A new Google Stitch project for the dark academia website direction.
  - A Stitch design system and canonical screen references for all Laravel website/account surfaces.
  - A homepage refactor with four primary sections:
    - Hero: product name, catch line, and extension download CTA.
    - Product video: user-supplied extension-in-use video when available, with generated poster/fallback art before the video exists.
    - Why use it: white or ivory editorial feature section with placeholder perk slots.
    - Final information/CTA: dark academic atmosphere with generated imagery and extra information.
  - Public marketing pages: home, pricing, languages, how-it-works, FAQ, support, privacy, and terms.
  - Auth/account pages: login, register, dashboard, and job detail pages in the same dark style, but kept operational, readable, and dense.
  - Image-generation prompt set for the final art direction.
  - Durable Stitch source references in `docs/design-assets/`.
- Out of scope:
  - Backend API, billing, auth, queue, transcription, or extension runtime behavior changes.
  - Changing pricing semantics, product limits, legal/privacy terms, or supported platforms.
  - Migrating the Laravel site to React, Next.js, Astro, or a separate SPA.
  - Copying Hermes source images, fonts, code, exact layout, or trademarked brand motifs.
  - Final product demo video production; the implementation should accept a video file later.

## Acceptance Criteria

- [x] A dedicated Stitch project exists for `Transcribed Subtitle Extension - Dark Academia Website`.
- [x] Stitch project/design-system/uploaded brief screen IDs are recorded in `docs/design-assets/stitch-dark-academia/README.md`.
- [x] The Stitch brief references `https://hermes-agent.nousresearch.com/desktop` as inspiration for hierarchy and rhythm only, with explicit non-copy constraints.
- [x] Homepage first viewport clearly shows `Transcribed Subtitle Extension`, the catch line `learn languages using youtube`, and a filler download CTA.
- [x] The product video section supports a local MP4/WebM file and shows a generated poster/fallback image until the real video exists.
- [x] The "why use it" section uses placeholder perk copy without pretending final marketing claims exist.
- [x] All public pages adopt the dark academia system while retaining existing SEO metadata, routes, canonical behavior, and legal/support content.
- [x] Auth/account pages adopt the same dark style without sacrificing dashboard readability, support-safe job details, form usability, or responsive behavior.
- [x] Generated imagery is original, language-learning/dark-academia themed, and saved as repo-owned assets before being referenced by Blade/CSS.
- [x] Desktop and mobile browser screenshots show no horizontal overflow, text overlap, unreadable contrast, or awkward image cropping.
- [x] Required Laravel and harness validation passes.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`, `docs/DESIGN.md`.
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`.
- Quality rules: `docs/quality/golden-principles.md`.
- Current website surfaces: `app/backend/resources/views/marketing/`, `app/backend/resources/views/layouts/site.blade.php`, `app/backend/resources/views/layouts/account.blade.php`, `app/backend/resources/views/dashboard.blade.php`, `app/backend/public/css/site.css`.
- Prior Stitch records:
  - `docs/exec-plans/completed/2026-05-25-stitch-led-cinematic-frontend-revamp.md`
  - `docs/design-assets/stitch-cinematic/README.md`
  - `docs/design-assets/stitch-clean-marketing/README.md`
- Reference inspection evidence:
  - `docs/design-assets/hermes-reference-top.png`
  - `docs/design-assets/hermes-reference-full.png`
  - `docs/design-assets/hermes-reference-mid.png`
  - `docs/design-assets/hermes-reference-bottom.png`
- External reference:
  - Hermes Desktop: `https://hermes-agent.nousresearch.com/desktop`
- Context7 references consulted for Stitch workflow:
  - `/davideast/stitch-mcp`
  - `/google-labs-code/stitch-skills`
- Known risks:
  - Stitch can produce beautiful screens that do not map cleanly to existing Blade/account constraints.
  - Generated image assets can drift into generic fantasy or book-cover art if prompts are not specific about YouTube language learning.
  - Dark pages can become low-contrast or illegible on forms, pricing tables, legal copy, and dashboard rows.

## Reference Analysis

Hermes works because it has one dominant visual thesis and repeats it with discipline:

- Full-bleed monochrome hero with oversized serif typography.
- Sparse, uppercase navigation and CTA copy.
- A large center-stage video/product preview after the hero.
- Editorial feature grid on a white field, using numbered items, compact copy, and strong image crops.
- Final atmospheric CTA with a large figure/image and oversized type.

This project should borrow the structural lessons, not the literal brand:

- Use dark academia instead of Hermes blue mythology.
- Use black, ivory, brass, oxblood, library green, and muted ink instead of electric blue/white.
- Use language-learning and academic imagery instead of statues, rays, anime/portal imagery, or Hermes-specific motifs.
- Use open-source serif/sans/mono fonts instead of Hermes custom fonts.
- Use original generated images and user-supplied product video, never hotlinked reference assets.

## Visual Thesis

An obsidian academic reading room for YouTube language learners: black paper, ivory serif type, brass details, oxblood emphasis, scholarly generated imagery, and restrained operational surfaces that make the extension feel serious, literary, and premium.

## Content Plan

1. Hero:
   - Product name: `Transcribed Subtitle Extension`.
   - Catch line: `learn languages using youtube`.
   - Primary CTA: `Download extension`.
   - CTA destination: filler `#download` until a Chrome Web Store or beta install URL exists.
   - Visual: full-bleed generated image or image plane with a calm left-side text area.
2. Product video:
   - Large editorial media block under the hero.
   - Preferred implementation: self-hosted `<video>` with MP4 and optional WebM sources.
   - Temporary state: generated poster/fallback image with a visible play/download-ready frame.
   - Final state: user-supplied extension-in-use demo video.
3. Why use it:
   - Ivory/white editorial section that breaks the dark field.
   - Numbered feature/perk grid.
   - Placeholder perks only:
     - `Perk 1`
     - `Perk 2`
     - `Perk 3`
     - `Perk 4`
     - `Perk 5`
     - `Perk 6`
   - Each perk should have a short placeholder body until final claims are approved.
4. Final information/CTA:
   - Return to dark field.
   - Strong generated language-learning image.
   - Extra information slots for install notes, supported workflow, privacy posture, pricing link, or support.
   - CTA repeats the filler download path.

## Page Design Requirements

### Homepage

- Treat the first viewport as a poster, not a generic SaaS page.
- Keep the product name louder than every other text element.
- Use one dominant hero image and one primary CTA.
- Show a hint of the video section below the fold on common desktop and mobile viewports.
- Avoid hero cards, pill clusters, stat strips, feature mosaics above the fold, and generic gradient backgrounds.
- Keep the CTA button sharp and editorial: small uppercase mono/sans label, brass or ivory surface, 8px radius or less.

### Public Content Pages

- Pricing, languages, how-it-works, FAQ, support, privacy, and terms should share the dark academia frame.
- Legal/support pages must stay readable:
  - Wider line-height.
  - Clear h2/h3 rhythm.
  - Ivory or warm-gray content surfaces when long text needs it.
  - No text over busy imagery.
- Pricing should remain scannable and honest; do not make plan cards overly ornamental.
- Language coverage should preserve clear quality tiers and avoid vague claims.

### Auth And Account Pages

- Use the same dark system but prioritize function over drama.
- Login/register forms should have high contrast labels, visible errors, and stable focus states.
- Dashboard and job detail pages should feel like an academic operations ledger:
  - Dense tables/rows.
  - Clear plan/usage/job status hierarchy.
  - Brass/green accents only for state and actions.
  - Red only for failures.
- Do not put dashboard content in decorative hero layouts.
- Preserve support-safe job detail behavior and avoid exposing generated transcript text in web support surfaces.

## Design Tokens

Target tokens for Stitch and implementation:

- Background black: `#050505`
- Obsidian surface: `#0b0a08`
- Raised surface: `#15120e`
- Ink text on light sections: `#15110d`
- Ivory text: `#f4ead7`
- Muted parchment: `#c8b99e`
- Aged paper section: `#f3ead8`
- Brass accent: `#b88a3b`
- Tarnished brass hover: `#d4aa57`
- Oxblood accent: `#7f1d1d`
- Library green: `#183b2d`
- Failure red: `#b91c1c`
- Thin dark line: `rgba(244, 234, 215, 0.14)`
- Thin light line: `rgba(21, 17, 13, 0.16)`

Typography:

- Display serif: `Cormorant Garamond`, `EB Garamond`, or `Libre Baskerville`.
- Functional sans: `Inter`, `Source Sans 3`, or system UI.
- Accent mono: `IBM Plex Mono`, `JetBrains Mono`, or `ui-monospace`.
- Use open-source fonts only, preferably self-hosted WOFF2 files if implementation needs exact rendering.
- Use uppercase display type sparingly for headings/nav/eyebrows.
- Do not use negative letter spacing. Keep letter spacing at `0` except small uppercase mono labels may use slight positive tracking.

Shape and layout:

- Radius: 0-8px; avoid soft SaaS pill/card styling.
- Borders: thin, mostly single-line, brass or ivory at low opacity.
- Shadows: minimal; use contrast, crops, and spacing instead.
- Cards: only for repeated items, forms, pricing plans, and dashboard records.
- Sections: full-width bands, not nested cards.

## Image Direction

All production imagery for this refactor should be generated with image gen v2, reviewed, saved into the repo, and referenced locally. Stitch should receive these prompts as art-direction requirements even if the exact final generation happens after screen design.

General constraints for every generated image:

- Original imagery only.
- No Hermes, Nous, statues, portal/orb motifs, or blue duotone copying.
- No visible trademarks, YouTube logos, fake browser logos, or legible brand names.
- No embedded UI text unless explicitly requested.
- No watermark, signature, or pseudo-logo.
- Dark academia, language learning, scholarly atmosphere.
- High contrast and usable negative space where page copy sits.
- Prefer photographic/etching/editorial realism over cartoon fantasy.

### Image Prompt 1: Hero Scholarly Language Desk

- Use case: ads-marketing
- Asset type: homepage full-bleed hero image
- Primary request: Create a dark academia hero image for a browser extension called Transcribed Subtitle Extension, focused on learning languages using YouTube.
- Scene/backdrop: An old black-wood academic study at night, stacked language books, handwritten vocabulary notes, brass desk lamp, headphones, subtle laptop glow, and a projected subtitle-like light line across the desk.
- Subject: A refined language-learning workspace, no visible logos, no legible brand names.
- Style/medium: Editorial cinematic photography with slight engraved texture, classy, premium, scholarly.
- Composition/framing: Wide landscape, calm negative space on the left for large website copy, detailed objects on the right and lower third.
- Lighting/mood: Low-key black, warm brass lamp light, ivory highlights, serious and elegant.
- Color palette: Black, ivory, brass, oxblood, deep library green.
- Constraints: No readable text, no YouTube logo, no Hermes-like blue, no statue rays, no watermark.

### Image Prompt 2: Product Video Poster

- Use case: ads-marketing
- Asset type: video poster/fallback image
- Primary request: Create a dark academia poster image for a demo video showing a language subtitle browser extension in use.
- Scene/backdrop: A laptop on a library desk playing an indistinct foreign-language lecture video with tasteful subtitle bars and study notes nearby.
- Subject: Product-in-use atmosphere without showing real UI details or brand logos.
- Style/medium: Cinematic editorial photography, premium software launch page, academic.
- Composition/framing: Landscape media frame, centered laptop, enough dark border area for a play control overlay to sit cleanly.
- Lighting/mood: Warm lamp light against black room, ivory screen glow, restrained contrast.
- Color palette: Obsidian black, ivory, brass, muted green.
- Constraints: No readable UI text, no logos, no people required, no watermark.

### Image Prompt 3: Perk Tile - Missing Captions

- Use case: ads-marketing
- Asset type: feature/perk image
- Primary request: Create an original dark academia feature image representing turning missing or poor captions into useful study subtitles.
- Scene/backdrop: Torn caption strips, open bilingual dictionary, annotation marks, brass ruler, dark desk.
- Subject: Abstract study materials implying subtitle repair and language comprehension.
- Style/medium: Etching-inspired editorial still life with photographic texture.
- Composition/framing: Square crop, strong central object, high contrast for an ivory feature grid.
- Lighting/mood: Scholarly, precise, restrained.
- Color palette: Black ink, ivory paper, brass accent, oxblood pencil marks.
- Constraints: No readable words, no logos, no Hermes-like rays, no watermark.

### Image Prompt 4: Perk Tile - Translation Layer

- Use case: ads-marketing
- Asset type: feature/perk image
- Primary request: Create an original feature image representing translation and romanization layers for language learners.
- Scene/backdrop: Transparent vellum sheets layered over an open book, phonetic marks, language flashcards, brass lamp edge.
- Subject: Layered learning aids without readable text.
- Style/medium: Dark academia editorial still life, subtle engraved/halftone texture.
- Composition/framing: Square crop with diagonal paper layers and strong light/shadow.
- Lighting/mood: Warm, scholarly, mysterious but readable.
- Color palette: Ivory, black, brass, muted green.
- Constraints: No readable text, no logos, no watermark.

### Image Prompt 5: Perk Tile - Word Cards

- Use case: ads-marketing
- Asset type: feature/perk image
- Primary request: Create an original image representing vocabulary word cards generated from video subtitles.
- Scene/backdrop: A neat row of blank study cards, fountain pen, headphones, old dictionary, dark academic desk.
- Subject: Tactile vocabulary study system.
- Style/medium: Premium editorial photography with slight print grain.
- Composition/framing: Square crop, cards in the foreground, dark background.
- Lighting/mood: Focused, elegant, quiet.
- Color palette: Black, parchment, brass, oxblood.
- Constraints: Blank cards or illegible marks only, no readable words, no logos, no watermark.

### Image Prompt 6: Final CTA Image

- Use case: ads-marketing
- Asset type: final CTA atmospheric image
- Primary request: Create a dark academia closing image for a language-learning extension website.
- Scene/backdrop: A grand old reading room at night with a glowing laptop, shelves of language books, handwritten notes, headphones, and a window showing a dark city.
- Subject: The feeling of studying world languages through online video in a serious academic setting.
- Style/medium: Cinematic editorial image, refined, premium, literary, not fantasy.
- Composition/framing: Landscape or wide portrait that can crop on desktop and mobile; clear negative space for nearby CTA copy.
- Lighting/mood: Deep black shadows, warm brass pools of light, ivory highlights.
- Color palette: Black, ivory, brass, oxblood, library green.
- Constraints: No readable text, no logos, no Hermes-style blue, no watermark.

## Google Stitch MCP Workflow

This refactor is Stitch-led. Codex should supply the brief, constraints, page inventory, reference link, image prompts, and implementation limits. Stitch is responsible for design exploration, layout, and screen composition. Codex then implements the accepted Stitch output in the existing Laravel Blade/CSS application.

### Stitch Project Setup

1. Use the Stitch MCP server to create a dedicated project:
   - Title: `Transcribed Subtitle Extension - Dark Academia Website`
2. Create or update a design system for the project using the tokens and visual thesis in this plan.
3. If the environment exposes `upload_design_md` and `create_design_system_from_design_md`, upload the Stitch design brief below as the project `DESIGN.md`.
4. If the environment exposes screen generation tools, generate canonical screens from the brief.
5. If screen generation tools are not exposed in the current Codex session, use the Stitch project UI for screen generation and then return to Codex with project/screen IDs.
6. Record every accepted project, design system, screen, and asset ID in `docs/design-assets/stitch-dark-academia/README.md`.

### Stitch Screens To Produce

- Desktop homepage.
- Mobile homepage.
- Public page template for pricing/languages/how-it-works.
- Long-form legal/support template for FAQ/privacy/terms/support.
- Auth template for login/register/password reset.
- Account dashboard.
- Job detail page.
- Optional asset board showing generated image placements and crops.

### Stitch Brief Seed

Use this as the core prompt/design markdown for Stitch:

```markdown
# Transcribed Subtitle Extension - Dark Academia Website

Design a premium dark academia website and account interface for a browser extension named "Transcribed Subtitle Extension".

Reference inspiration: https://hermes-agent.nousresearch.com/desktop
Use the Hermes page only for structural inspiration: full-bleed editorial hero, oversized serif hierarchy, large video showcase, high-contrast feature section, atmospheric final CTA. Do not copy its blue palette, mythology imagery, fonts, assets, copy, logo, rays, statue treatment, or exact layouts.

Primary catch line: "learn languages using youtube"
Primary CTA: "Download extension"
CTA destination is placeholder/filler for now.

Visual thesis:
An obsidian academic reading room for YouTube language learners: black paper, ivory serif type, brass details, oxblood emphasis, library-green shadows, scholarly generated imagery, and restrained operational surfaces.

Pages:
- Homepage
- Pricing
- Languages
- How it works
- FAQ
- Support
- Privacy
- Terms
- Login/Register/Auth forms
- Account dashboard
- Job detail

Homepage structure:
1. Full-bleed hero with product name, catch line, and download CTA.
2. Large product video section for a future extension-in-use demo video, with generated poster fallback.
3. Ivory "why use it" section with six placeholder perks: Perk 1 through Perk 6.
4. Final dark information/CTA section with atmospheric language-learning image.

Design tokens:
- Black background #050505
- Obsidian #0b0a08
- Raised dark surface #15120e
- Ivory text #f4ead7
- Parchment section #f3ead8
- Muted parchment #c8b99e
- Brass #b88a3b
- Oxblood #7f1d1d
- Library green #183b2d
- Failure red #b91c1c

Typography:
- High-contrast open-source display serif, such as Cormorant Garamond, EB Garamond, or Libre Baskerville.
- Functional sans, such as Inter or Source Sans 3, for forms, tables, and dashboard information.
- Monospace accent for small uppercase labels.

Rules:
- Keep all legal/account text highly readable.
- Use cards only for repeated items, forms, pricing plans, and dashboard records.
- Use 0-8px radii.
- No generic SaaS gradients, pill clusters, or decorative card mosaics.
- Do not place text over busy image regions.
- Preserve clear responsive layouts for mobile.
- Dashboard should be operational and dense, not a marketing hero.
- Final implementation remains Laravel Blade and CSS, not a new frontend framework.
```

### Handoff Back To Implementation

Implementation should begin only after Stitch output is reviewed against this plan. The accepted handoff should include:

- Stitch project ID.
- Design system asset ID.
- Screen IDs for each canonical page/state.
- Screenshot exports or stable view URLs.
- HTML/CSS exports if available.
- Generated image prompts and selected image files.
- Notes on any Stitch layout that must be simplified to fit current Blade/account constraints.

### Stitch Tooling Notes

- Context7 docs for Stitch MCP describe project, screen, code-fetch, and asset-download workflows, but exact exposed tools can differ by Codex session.
- At implementation start, run `tool_search` for Stitch tools and use available MCP calls rather than assuming a fixed API surface.
- Prefer project/design-system/screen IDs as the durable record. Prior Stitch asset downloads in this repo reported success but did not write files, so the README manifest must preserve source references even if local asset download fails.
- Do not implement hotlinks to Stitch or Hermes assets in production.

## Implementation Steps

- [x] Inspect current marketing/account pages and capture browser QA screenshots.
- [x] Create the Stitch project and design system from this plan.
- [x] Upload the Stitch brief and record the generated `DESIGN.md` screen reference. Canonical page-screen generation was not exposed in this MCP session and is tracked as follow-up debt.
- [x] Record Stitch project/design-system/screen references in `docs/design-assets/stitch-dark-academia/README.md`.
- [x] Generate final image assets with image gen v2 from the prompt set, save selected assets locally, and document final prompts/paths.
- [x] Implement the approved homepage structure in `app/backend/resources/views/marketing/home.blade.php`.
- [x] Update shared site/account layout and CSS in `app/backend/resources/views/layouts/` and `app/backend/public/css/site.css`.
- [x] Apply public-page template changes while preserving route content, SEO metadata, and legal/support copy.
- [x] Apply auth/account dark styling while preserving form behavior, dashboard data, billing links, job details, and support-safe constraints.
- [x] Update durable design docs because this direction supersedes current `Precision & Clarity` marketing guidance.
- [x] Run browser QA for desktop and mobile.
- [x] Run validation and record evidence.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Targeted checks likely needed:

```powershell
cd app\backend
php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php tests\Feature\WebAuthTest.php
```

Evidence to capture:

- Tests:
  - Public route and SEO feature tests still pass.
  - Auth/dashboard/job-detail feature tests still pass.
- Screenshots or video:
  - Homepage desktop and mobile.
  - Pricing desktop and mobile.
  - Long-form legal/support page mobile.
  - Login/register mobile.
  - Dashboard desktop and mobile.
  - Job detail desktop and mobile.
- Browser assertions:
  - No horizontal overflow at common mobile widths.
  - CTA remains visible and tappable.
  - Text contrast remains readable on dark and ivory sections.
  - Video poster/fallback crops cleanly.
  - Legal copy does not sit on images.
- Logs:
  - Browser console errors: none on visited pages.
- Metrics or traces:
  - Not required unless implementation changes frontend performance materially.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-05 | Use `Transcribed Subtitle Extension` as the website product name. | User clarified this is the desired hero name. |
| 2026-06-05 | Use `learn languages using youtube` as the catch line. | User supplied exact copy. |
| 2026-06-05 | Keep the download CTA as filler for now. | Final Chrome Web Store or beta install URL is not available yet. |
| 2026-06-05 | Apply the new dark style to all pages, including account pages, while keeping account surfaces operational/readable. | User wants all pages in the style but not at the expense of utility. |
| 2026-06-05 | Use a black/dark-academia palette instead of Hermes blue/white. | User prefers a black direction and wants original language-learning academia imagery. |
| 2026-06-05 | Include image prompts in the plan, but do not generate final assets before Stitch design review. | User wants prompts included; Stitch should lead design/layout first. |
| 2026-06-05 | Make the refactor Stitch-led, with Codex implementing the accepted output. | User specified Google Stitch should be in charge of design and layout. |
| 2026-06-05 | Implement from the uploaded Stitch brief and generated design system because canonical page-screen generation tools were not exposed in this session. | Stitch project, `DESIGN.md` screen, and design system were created successfully; future page-screen generation remains tracked as TD-013. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-05 | Plan created from user-approved direction after inspecting Hermes and existing repo planning/Stitch conventions. | Hermes screenshots in `docs/design-assets/`; prior Stitch records reviewed. |
| 2026-06-05 | Created Stitch project `projects/11285798713880801131`, uploaded the brief, and created design system `assets/5ee04fe765404b7fb7e1d34e23d44d50`. | `docs/design-assets/stitch-dark-academia/README.md`; `projects/11285798713880801131/screens/2546209766202785116`. |
| 2026-06-05 | Generated and saved six dark-academia marketing assets for hero, video poster, perk imagery, and final CTA. | `app/backend/public/img/marketing/dark-academia/`; rejected variants documented in `docs/design-assets/stitch-dark-academia/README.md`. |
| 2026-06-05 | Refactored Laravel marketing/auth/account surfaces into the dark academia system while preserving routes, forms, legal/support copy, dashboard data, and support-safe job details. | Blade/CSS changes under `app/backend/resources/views/`, `app/backend/public/css/site.css`, and `app/backend/app/Http/Controllers/*`. |
| 2026-06-05 | Browser QA captured viewport screenshots and passed DOM assertions for overflow, image loading, video poster wiring, CTA links, console errors, and job-detail privacy. | Screenshots in `docs/design-assets/stitch-dark-academia/qa/`; Playwright assertion run checked 15 route/viewport states with no overflow and no console errors. |
| 2026-06-05 | Required validation passed. | `vendor/bin/pint --dirty --format agent`; `php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php tests\Feature\WebAuthTest.php`; `.\scripts\agent\doc-gardening.ps1`; `.\scripts\agent\check.ps1`; `.\scripts\agent\verify-pr.ps1`. |

## Completion Notes

- What changed: Introduced the Stitch-led `Obsidian Scriptorium` dark academia system across the Laravel public website, auth layout, dashboard, and job-detail surfaces; updated the marketing product name to `Transcribed Subtitle Extension`; replaced the homepage with a full-bleed editorial hero, generated-poster video slot, parchment placeholder-perk section, and final atmospheric CTA; saved repo-owned generated imagery and screenshot evidence; updated durable design/product docs and SEO metadata.
- Validation results: Baseline `.\scripts\agent\check.ps1` passed before edits. Final validation passed with `vendor/bin/pint --dirty --format agent`, targeted Laravel feature tests (12 passed, 131 assertions), browser assertions (15 route/viewport states, no overflow, no console errors, images loaded, job-detail text hidden), `.\scripts\agent\doc-gardening.ps1`, `.\scripts\agent\check.ps1` (190 backend tests, 79 extension tests, contracts check, TypeScript compile, WXT build), and `.\scripts\agent\verify-pr.ps1`.
- Simplicity/readability review: The implementation keeps the existing Blade routes, layouts, controllers, feature tests, and CSS asset pipeline. The product video slot conditionally emits MP4/WebM sources only when local files exist, preventing broken requests while keeping the future file path simple.
- Residual risk: Canonical Stitch page screens for each page/state were not generated because this Codex session exposed project/design-system/upload/read tools but not page screen-generation tools. The uploaded brief screen and generated design-system asset are recorded as the durable Stitch source for this implementation.
- Follow-up debt: TD-013 tracks generating canonical Stitch page screens later if the Stitch UI or MCP tool surface exposes screen generation.
